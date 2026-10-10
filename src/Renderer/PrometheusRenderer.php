<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Renderer;

use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Metrics\Enum\MetricType;
use Hyperf\Apex\Metrics\MetricSeries;

/**
 * 渲染成 Prometheus 文本格式（text/plain; version=0.0.4）。
 *
 * 直方图按 Prometheus 的约定输出：桶是累积计数、必须有 `+Inf` 桶、`_sum` 与 `_count` 成对。
 * 这里不建 `+Inf` 行，渲染时用 `_count` 补出来 —— 两行存同一个数只会多一份不一致的机会。
 *
 * `_sum` 在表里存的是微秒整数（定长表不适合存浮点），渲染时才换算回秒。
 */
final class PrometheusRenderer
{
    /**
     * @param list<MetricSeries> $series
     * @param array<int, int> $values 行键 => 值
     * @param list<GaugeSample> $gauges
     */
    public function render(array $series, array $values, array $gauges): string
    {
        $lines = [];

        /** @var array<string, list<MetricSeries>> $grouped */
        $grouped = [];
        foreach ($series as $row) {
            $grouped[$row->name][] = $row;
        }

        foreach ($grouped as $name => $group) {
            if (! self::hasAnyValue($group, $values)) {
                continue;
            }

            $first = $group[0];
            $lines[] = sprintf('# HELP %s %s', $name, self::escapeHelp($first->help));
            $lines[] = sprintf('# TYPE %s %s', $name, $first->type->value);

            if ($first->type === MetricType::HISTOGRAM) {
                // 桶 / _sum / _count 必须成组出现，因此按标签组合逐组过滤，而不是逐行。
                foreach (self::byLabels($group) as $rows) {
                    if (! self::hasAnyValue($rows, $values)) {
                        continue;
                    }

                    foreach (self::histogram($name, $rows, $values) as $line) {
                        $lines[] = $line;
                    }
                }

                continue;
            }

            foreach ($group as $row) {
                if (($values[$row->id] ?? 0) === 0) {
                    continue;
                }

                $lines[] = sprintf('%s%s %s', $name, self::labels($row->labels), self::number($values[$row->id]));
            }
        }

        /** @var array<string, list<GaugeSample>> $gaugeGroups */
        $gaugeGroups = [];
        foreach ($gauges as $gauge) {
            $gaugeGroups[$gauge->name][] = $gauge;
        }

        foreach ($gaugeGroups as $name => $group) {
            // 一个 gauge 名可能带多组标签（例如队列按 state 拆开），HELP/TYPE 只能出现一次，
            // 重复输出会让 Prometheus 直接拒绝整份抓取结果。
            $lines[] = sprintf('# HELP %s %s', $name, self::escapeHelp($group[0]->help));
            $lines[] = sprintf('# TYPE %s gauge', $name);

            foreach ($group as $gauge) {
                $lines[] = sprintf('%s%s %s', $name, self::labels($gauge->labels), self::number($gauge->value));
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * 按标签组合切分同名指标。
     *
     * 跳零必须落在「一条时间序列」的粒度上：同一个指标名下挂着上万条标签组合，
     * 只要其中一个有值就整名输出的话，跳零等于没做。
     *
     * @param list<MetricSeries> $group
     * @return array<string, list<MetricSeries>>
     */
    private static function byLabels(array $group): array
    {
        $buckets = [];
        foreach ($group as $row) {
            $buckets[implode("\0", $row->labels)][] = $row;
        }

        return $buckets;
    }

    /**
     * 整组都是 0 的序列不输出。
     *
     * 展开后的行数由「路由 × 方法 × 状态码段」决定（当前约 1.3 万行），真正被触达的只有
     * 其中一小部分；把从未发生的组合渲染出来，只会让每次抓取多传一份上兆的文本。
     *
     * counter 与 histogram 的 0 值序列不输出不影响 Prometheus 侧计算（rate() 从首次出现算起）；
     * 采样 gauge 不走这里 —— 它们的 0 有意义（例如连接数掉到 0），始终输出。
     *
     * @param list<MetricSeries> $group
     * @param array<int, int> $values
     */
    private static function hasAnyValue(array $group, array $values): bool
    {
        foreach ($group as $row) {
            if (($values[$row->id] ?? 0) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MetricSeries> $group
     * @param array<int, int> $values
     * @return list<string>
     */
    private static function histogram(string $name, array $group, array $values): array
    {
        $lines = [];
        $sumRow = null;
        $countRow = null;

        foreach ($group as $row) {
            if ($row->suffix === '_bucket') {
                $lines[] = sprintf(
                    '%s_bucket%s %s',
                    $name,
                    self::labels($row->labels, ['le' => $row->bucketLabel()]),
                    self::number($values[$row->id] ?? 0)
                );

                continue;
            }

            if ($row->suffix === '_sum') {
                $sumRow = $row;

                continue;
            }

            if ($row->suffix === '_count') {
                $countRow = $row;
            }
        }

        $count = $countRow === null ? 0 : (int) ($values[$countRow->id] ?? 0);
        $labels = $countRow === null ? [] : $countRow->labels;
        $lines[] = sprintf('%s_bucket%s %s', $name, self::labels($labels, ['le' => '+Inf']), self::number($count));

        if ($sumRow !== null) {
            $micros = (int) ($values[$sumRow->id] ?? 0);
            $lines[] = sprintf(
                '%s_sum%s %s',
                $name,
                self::labels($sumRow->labels),
                number_format($micros / 1_000_000, 6, '.', '')
            );
        }

        $lines[] = sprintf('%s_count%s %s', $name, self::labels($labels), self::number($count));

        return $lines;
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, string> $extra
     */
    private static function labels(array $labels, array $extra = []): string
    {
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = sprintf('%s="%s"', $key, self::escapeLabel($value));
        }

        foreach ($extra as $key => $value) {
            $parts[] = sprintf('%s="%s"', $key, self::escapeLabel($value));
        }

        return $parts === [] ? '' : '{' . implode(',', $parts) . '}';
    }

    private static function number(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if ($value === floor($value) && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return number_format($value, 6, '.', '');
    }

    private static function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\n', '\"'], $value);
    }

    private static function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\n'], $value);
    }
}
