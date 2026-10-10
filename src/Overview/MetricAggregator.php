<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Overview;

use Hyperf\Apex\Metrics\MetricSeries;
use Hyperf\Apex\Metrics\Registry\MetricDefinitionRegistry;

/**
 * 按「保留哪些标签」把指标表聚合成少量汇总值。
 *
 * 大屏要的是全局速率（整体 QPS、整体错误率），不是逐路由明细。而指标表里展开了一万多行，
 * 全量做速率差分既没有意义也存不下；聚合之后只剩几十个数，一行共享内存就够。
 *
 * 聚合键的构造规则（与 Service 侧的解析约定一致）：
 * - 普通指标：保留标签的值按顺序用 `|` 连接；空标签列表表示全部求和成一个键；
 * - 直方图：在保留标签之后追加 `le:{上界}`、`_sum`、`_count`。
 *
 * 之所以把 `|` 连接的结果当键而不是嵌套数组：这个结构会被序列化进共享内存的字符串列，
 * 扁平的键值对最省空间，也最不容易在反序列化时出现类型歧义。
 */
final class MetricAggregator
{
    public const HISTOGRAM_SUM = '_sum';

    public const HISTOGRAM_COUNT = '_count';

    public const BUCKET_PREFIX = 'le:';

    /**
     * @param array<string, list<string>> $spec 指标名 => 保留的标签名（空数组表示全部求和）
     * @param array<int, int> $values 行键 => 值
     * @return array<string, array<string, int>> 指标名 => [聚合键 => 累计值]
     */
    public static function aggregate(
        ?MetricDefinitionRegistry $registry,
        array $values,
        array $spec,
    ): array {
        if ($registry === null || $spec === []) {
            return [];
        }

        $totals = [];
        foreach ($registry->series() as $row) {
            $keepLabels = $spec[$row->name] ?? null;
            if ($keepLabels === null) {
                continue;
            }

            $key = self::key($row, $keepLabels);
            $totals[$row->name][$key] = ($totals[$row->name][$key] ?? 0) + ($values[$row->id] ?? 0);
        }

        return $totals;
    }

    /**
     * 两次聚合结果的差。只出现在本次的键按「上次为 0」处理，因此新序列不需要特殊照顾。
     *
     * @param array<string, array<string, int>> $current
     * @param array<string, array<string, int>> $previous
     * @return array<string, array<string, int>>
     */
    public static function delta(array $current, array $previous): array
    {
        $delta = [];
        foreach ($current as $metric => $values) {
            foreach ($values as $key => $value) {
                $difference = $value - ($previous[$metric][$key] ?? 0);
                if ($difference !== 0) {
                    $delta[$metric][$key] = $difference;
                }
            }
        }

        return $delta;
    }

    /**
     * 求和某指标的全部聚合值。
     *
     * @param array<string, array<string, int>> $values
     */
    public static function total(array $values, string $metric): int
    {
        $sum = 0;
        foreach ($values[$metric] ?? [] as $value) {
            $sum += $value;
        }

        return $sum;
    }

    /**
     * 取某指标某个聚合键的值。
     *
     * @param array<string, array<string, int>> $values
     */
    public static function get(array $values, string $metric, string $key): int
    {
        return $values[$metric][$key] ?? 0;
    }

    /**
     * @param list<string> $keepLabels
     */
    private static function key(MetricSeries $row, array $keepLabels): string
    {
        $parts = [];
        foreach ($keepLabels as $labelName) {
            $parts[] = $row->labels[$labelName] ?? '';
        }

        if ($row->suffix === '_bucket') {
            $parts[] = self::BUCKET_PREFIX . $row->bucketLabel();

            return implode('|', $parts);
        }

        if ($row->suffix !== '') {
            $parts[] = $row->suffix;
        }

        return implode('|', $parts);
    }
}
