<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics;

use Hyperf\Apex\Metrics\Storage\MetricTable;
use Throwable;

/**
 * 埋点门面。业务代码只依赖这个类。
 *
 * 两条不可违背的约定：
 * 1. **永不抛异常**。指标是旁路观测，任何内部错误（未初始化、标签未声明、表写入失败）
 *    都必须在内部终止，绝不能让业务请求因为「记不上一个数」而失败。
 * 2. **不做 I/O**。只查进程内索引 + 共享内存原子自增，不写 Redis、不打日志、不查库。
 *    单测环境不启动 server、表未初始化，此时全部降级为 no-op。
 */
final class Metrics
{
    private const DROPPED = 'core_metrics_series_dropped_total';

    /**
     * 计数器 +1（或指定增量）。
     *
     * @param array<array-key, mixed> $labels
     */
    public static function incr(string $name, array $labels = [], int $delta = 1): void
    {
        if ($delta === 0) {
            return;
        }

        try {
            $registry = MetricTable::registry();
            if ($registry === null) {
                return;
            }

            $id = $registry->resolve($name, $labels);
            if ($id === null) {
                self::drop($name);

                return;
            }

            MetricTable::add($id, $delta);
        } catch (Throwable) {
            // 见类注释第 1 条。
        }
    }

    /**
     * 记录一次直方图观测，单位秒。
     *
     * 桶是累积计数（Prometheus 语义）：所有 `le >= 观测值` 的桶都 +1；`_sum` 以微秒存储，
     * 避免定长表存浮点；`_count` 与 `+Inf` 桶共用同一个值，渲染时补出 `+Inf`。
     *
     * 行键一次性解析（见 resolveHistogram），不在循环里逐行 resolve：观测点都在请求热路径上，
     * 而逐行解析意味着同一组标签被校验十几遍。
     *
     * @param array<array-key, mixed> $labels
     */
    public static function observe(string $name, array $labels, float $seconds): void
    {
        if ($seconds < 0.0) {
            return;
        }

        try {
            $registry = MetricTable::registry();
            if ($registry === null) {
                return;
            }

            $series = $registry->resolveHistogram($name, $labels);
            if ($series === null || $series['sum'] === null || $series['count'] === null) {
                self::drop($name);

                return;
            }

            // 桶上界来自构建期的固定列表，与行键同序：只写命中的累积桶（le >= 观测值）。
            $bounds = $registry->buckets();
            foreach ($series['buckets'] as $index => $id) {
                if ($id === null || $seconds > ($bounds[$index] ?? 0.0)) {
                    continue;
                }

                MetricTable::add($id);
            }

            MetricTable::add($series['sum'], (int) round($seconds * 1_000_000));
            MetricTable::add($series['count']);
        } catch (Throwable) {
            // 见类注释第 1 条。
        }
    }

    /**
     * 未声明的指标或标签组合被丢弃时记一笔。
     *
     * 这里刻意不走 incr()：它自己就是「查表失败」的兜底路径，再递归一次没有意义。
     */
    private static function drop(string $name): void
    {
        try {
            $registry = MetricTable::registry();
            if ($registry === null) {
                return;
            }

            $id = $registry->resolve(self::DROPPED, ['metric' => $name])
                ?? $registry->resolve(self::DROPPED, ['metric' => 'unknown']);
            if ($id !== null) {
                MetricTable::add($id);
            }
        } catch (Throwable) {
            // 见类注释第 1 条。
        }
    }
}
