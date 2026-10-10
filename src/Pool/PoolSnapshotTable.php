<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use RuntimeException;
use Swoole\Table;

/**
 * 各进程的连接池占用快照。
 *
 * 连接池是进程私有资源，而抓取只会命中一个 worker —— 想让「池里还剩多少连接」成为全局口径，
 * 就必须让每个用过连接池的进程把自己的快照写进共享内存，渲染时再按池求和。
 *
 * 行键用 pid：进程重启后 pid 会变，旧行不会有人再更新，靠 updated_at 过期即可失效，
 * 因此不需要显式清理，容量也不必随重启增长。
 *
 * 列按「池序号 × 状态」在 create() 前声明 —— Swoole\Table 的列必须提前定下来，
 * 这也正是池数量有上限的原因（超了在启动期直接报错，而不是运行期少一列）。
 */
final class PoolSnapshotTable
{
    /** 快照写入时间（秒），用于判断某进程的行是否已失效。 */
    public const COLUMN_TIME = 'updated_at';

    /**
     * 支持的连接池数量上限。
     *
     * 列名形如 `p0i` / `p0u`，每个池两列；Swoole\Table 的列数上限远高于此，
     * 这个值只是给「连接配置被误写成几十个」留一个明确的上限。
     */
    private const MAX_POOLS = 32;

    private static ?Table $table = null;

    private static int $poolCount = 0;

    /**
     * @param list<string> $poolNames 与 metrics.definitions 里 pool 标签的值域同序
     */
    public static function init(array $poolNames, int $size): void
    {
        if (count($poolNames) > self::MAX_POOLS) {
            throw new RuntimeException(sprintf(
                '连接池数量 %d 超过快照表上限 %d：请调大 PoolSnapshotTable::MAX_POOLS 或收敛连接配置。',
                count($poolNames),
                self::MAX_POOLS
            ));
        }

        $table = new Table(max(8, $size));
        for ($index = 0; $index < count($poolNames); ++$index) {
            $table->column(self::idleColumn($index), Table::TYPE_INT);
            $table->column(self::inUseColumn($index), Table::TYPE_INT);
        }
        $table->column(self::COLUMN_TIME, Table::TYPE_INT);

        if (! $table->create()) {
            throw new RuntimeException('无法创建连接池快照表。');
        }

        self::$poolCount = count($poolNames);
        self::$table = $table;
    }

    public static function initialized(): bool
    {
        return self::$table instanceof Table;
    }

    public static function poolCount(): int
    {
        return self::$poolCount;
    }

    /**
     * 写入某进程的快照。
     *
     * 写失败（容量满）刻意不报错：这是随时会被下一轮覆盖的采样值，丢一次没有影响 ——
     * 与指标计数写不进去必须暴露的处理方式不同。
     *
     * @param array<string, int> $values
     */
    public static function write(string $processKey, array $values, int $updatedAt): void
    {
        self::$table?->set($processKey, $values + [self::COLUMN_TIME => $updatedAt]);
    }

    /**
     * @return list<array<string, int>>
     */
    public static function rows(): array
    {
        if (! self::$table instanceof Table) {
            return [];
        }

        $rows = [];
        foreach (self::$table as $row) {
            if (is_array($row)) {
                /* @var array<string, int> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * 只给测试用。
     */
    public static function reset(): void
    {
        self::$table = null;
        self::$poolCount = 0;
    }

    public static function idleColumn(int $index): string
    {
        return 'p' . $index . 'i';
    }

    public static function inUseColumn(int $index): string
    {
        return 'p' . $index . 'u';
    }
}
