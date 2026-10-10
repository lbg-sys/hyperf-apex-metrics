<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Storage;

use RuntimeException;
use Swoole\Table;

/**
 * 速率差分的基准：上一次聚合结果与采样时间。
 *
 * 没有 Prometheus 就没有历史存储，而 counter 只给累计值 —— 想知道「最近十秒的 QPS」，
 * 唯一的办法是自己记住上一次读到的累计值。这张表就存那一份。
 *
 * 单行设计：整份基准是一个 JSON 字符串，而不是按指标拆列。指标增减不需要动表结构，
 * 代价是一次序列化（十秒一次，可忽略）。列长按聚合规格估算约 3KB，留到 16KB 有余量。
 *
 * 刻意不做定时采样：没人看大屏就不必采样，刷新间隔本身就是采样间隔，语义正好。
 */
final class RateSampleTable
{
    public const COLUMN_AT = 'sampled_at';

    public const COLUMN_PAYLOAD = 'payload';

    /** 单行 JSON 的字节上限。聚合规格扩大到这里就该考虑拆表了。 */
    private const PAYLOAD_BYTES = 16384;

    private const ROW_KEY = 'last';

    private static ?Table $table = null;

    public static function init(int $size = 8): void
    {
        $table = new Table(max(8, $size));
        $table->column(self::COLUMN_AT, Table::TYPE_INT);
        $table->column(self::COLUMN_PAYLOAD, Table::TYPE_STRING, self::PAYLOAD_BYTES);

        if (! $table->create()) {
            throw new RuntimeException('无法创建指标速率基准表。');
        }

        self::$table = $table;
    }

    public static function initialized(): bool
    {
        return self::$table instanceof Table;
    }

    /**
     * @return array{at: int, values: array<string, array<string, int>>}
     */
    public static function read(): array
    {
        $empty = ['at' => 0, 'values' => []];
        if (! self::$table instanceof Table) {
            return $empty;
        }

        $row = self::$table->get(self::ROW_KEY);
        if (! is_array($row)) {
            return $empty;
        }

        $decoded = json_decode((string) ($row[self::COLUMN_PAYLOAD] ?? ''), true);
        if (! is_array($decoded)) {
            return $empty;
        }

        /** @var array<string, array<string, int>> $values */
        $values = [];
        foreach ($decoded as $metric => $aggregated) {
            if (! is_string($metric) || ! is_array($aggregated)) {
                continue;
            }

            foreach ($aggregated as $key => $value) {
                if (is_string($key) && is_int($value)) {
                    $values[$metric][$key] = $value;
                }
            }
        }

        return ['at' => (int) ($row[self::COLUMN_AT] ?? 0), 'values' => $values];
    }

    /**
     * @param array<string, array<string, int>> $values
     */
    public static function write(int $sampledAt, array $values): void
    {
        if (! self::$table instanceof Table) {
            return;
        }

        $payload = (string) json_encode($values, JSON_UNESCAPED_UNICODE);
        if (strlen($payload) > self::PAYLOAD_BYTES) {
            /*
             * 写不下就整份放弃，而不是截断：截断后的 JSON 解析不出来，下一次差分会被
             * 当成「没有基准」，速率会整体偏大。放弃写入的后果只是基准停留在更早的时刻，
             * 速率偏小的那一次仍是个有意义的平均值。
             */
            return;
        }

        self::$table->set(self::ROW_KEY, [
            self::COLUMN_AT => $sampledAt,
            self::COLUMN_PAYLOAD => $payload,
        ]);
    }

    /**
     * 只给测试用。
     */
    public static function reset(): void
    {
        self::$table = null;
    }
}
