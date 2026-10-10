<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Storage;

use Hyperf\Apex\Metrics\Registry\MetricDefinitionRegistry;
use RuntimeException;
use Swoole\Table;

/**
 * 指标值的共享内存表。
 *
 * 必须在 fork worker 之前（BeforeMainServerStart）创建：worker、task worker 与自定义进程
 * 共用同一份表，跨进程的计数才有意义。这与 WebSocket 连接索引是同一个理由。
 *
 * 表是定长的，因此所有行都在 init() 里按定义建好；运行期只做 incr，不 set 新行。
 * 容量不足在启动期直接抛错 —— 指标写不进去是静默的，必须让它在启动时暴露。
 */
final class MetricTable
{
    private const COLUMN = 'value';

    private static ?Table $table = null;

    private static ?MetricDefinitionRegistry $registry = null;

    public static function init(MetricDefinitionRegistry $registry, int $size): void
    {
        $series = $registry->series();
        $required = self::capacityFor(count($series));
        if ($size < $required) {
            throw new RuntimeException(sprintf(
                '指标定义展开出 %d 行，metrics.table_size=%d 不足，建议至少 %d：'
                . 'Swoole\Table 的实际可用行数受内部装载因子影响，需要留 2 倍余量。',
                count($series),
                $size,
                $required
            ));
        }

        $table = new Table($size);
        $table->column(self::COLUMN, Table::TYPE_INT);
        if (! $table->create()) {
            throw new RuntimeException('无法创建指标共享内存表。');
        }

        foreach ($series as $row) {
            if ($table->set((string) $row->id, [self::COLUMN => 0]) === false) {
                throw new RuntimeException(sprintf('指标行 %d 写入失败（表容量已满或键／值超出限制）。', $row->id));
            }
        }

        self::$registry = $registry;
        self::$table = $table;
    }

    public static function initialized(): bool
    {
        return self::$table instanceof Table;
    }

    /**
     * 未初始化时返回 null，调用方据此降级为 no-op（单测不启动 server，表本来就不存在）。
     */
    public static function registry(): ?MetricDefinitionRegistry
    {
        return self::$registry;
    }

    public static function add(int $seriesId, int $delta = 1): void
    {
        self::$table?->incr((string) $seriesId, self::COLUMN, $delta);
    }

    /**
     * @return array<int, int>
     */
    public static function snapshot(): array
    {
        if (! self::$table instanceof Table) {
            return [];
        }

        $values = [];
        foreach (self::$table as $key => $row) {
            $values[(int) $key] = is_array($row) ? (int) ($row[self::COLUMN] ?? 0) : 0;
        }

        return $values;
    }

    /**
     * 只给测试用：丢掉当前表，让下一个用例可以重建。
     */
    public static function reset(): void
    {
        self::$table = null;
        self::$registry = null;
    }

    /**
     * 按 2 倍余量算出建议容量，并对齐到 2 的幂（Swoole\Table 内部也是这么取的）。
     */
    private static function capacityFor(int $rows): int
    {
        $target = max(1, $rows * 2);
        $size = 1;
        while ($size < $target) {
            $size <<= 1;
        }

        return $size;
    }
}
