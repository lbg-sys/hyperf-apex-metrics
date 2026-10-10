<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Registry;

use Hyperf\Apex\Metrics\Enum\MetricType;
use Hyperf\Apex\Metrics\MetricDefinition;
use Hyperf\Apex\Metrics\MetricSeries;
use Hyperf\Contract\ConfigInterface;

/**
 * 指标定义与「序列索引」的构建结果。
 *
 * 启动期把 definitions 展开成运行期需要的全部行，并把「指标名 + 标签组合 + 后缀」映射成
 * 定长表的行键。运行期只做查表与原子自增，不产生新行（Swoole\Table 写不进去是静默的）。
 *
 * 标签值域在这里同时落成一张查找表：热路径上的每一次 `resolve()` 都要校验标签值是否在
 * 闭集内，用 in_array 线性扫描会让埋点本身成为开销，因此值域在构建期就翻转成 hash。
 */
final class MetricDefinitionRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $definitions = [];

    /** @var list<MetricSeries> */
    private array $series = [];

    /** @var array<string, int> */
    private array $index = [];

    /** @var array<string, array<string, array<string, true>>> 指标名 => 标签名 => 取值 hash */
    private array $allowedValues = [];

    /** @var list<float> */
    private array $buckets = [];

    /**
     * 与 buckets() 同序的桶 key 片段。
     *
     * 构建期就把数字格式化这类开销算完：它在热路径上会按桶重复执行，而每次观测都要走完整份桶列表。
     *
     * @var list<string>
     */
    private array $bucketKeys = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, list<string>> $dynamicValues 动态值域，键为不带 @ 前缀的占位符名
     */
    public static function fromConfig(ConfigInterface $config, array $dynamicValues = []): self
    {
        $registry = new self();
        $registry->buckets = self::readBuckets($config);
        foreach ($registry->buckets as $bucket) {
            $registry->bucketKeys[] = self::bucketKey($bucket);
        }

        $configured = $config->get('metrics.definitions', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $dynamicValues['metric_names'] = self::metricNames($configured);

        foreach ($configured as $name => $spec) {
            if (! is_string($name) || $name === '' || ! is_array($spec)) {
                continue;
            }

            /** @var array<array-key, mixed> $spec */
            $type = is_string($spec['type'] ?? null) ? MetricType::transform($spec['type']) : null;
            if ($type === null) {
                continue;
            }

            $definition = new MetricDefinition(
                $name,
                $type,
                is_string($spec['help'] ?? null) ? $spec['help'] : $name,
                [],
                []
            );

            $labelNames = [];
            $labelValues = [];
            /** @var array<array-key, mixed> $labels */
            $labels = is_array($spec['labels'] ?? null) ? $spec['labels'] : [];
            foreach ($labels as $labelName => $values) {
                if (! is_string($labelName) || $labelName === '') {
                    continue;
                }

                $values = self::resolveValues($values, $dynamicValues);
                if ($values === []) {
                    // 动态值域为空（例如路由扫描失败）时退化成单一取值：宁可粗，也不能让指标消失。
                    $values = ['other'];
                }

                $labelNames[] = $labelName;
                $labelValues[$labelName] = $values;
            }

            $definition = new MetricDefinition($name, $type, $definition->help, $labelNames, $labelValues);
            $registry->definitions[$name] = $definition;
            $registry->allowedValues[$name] = self::valueLookup($labelValues);

            foreach (self::labelCombinations($labelNames, $labelValues) as $combination) {
                $registry->appendSeries($definition, $combination);
            }
        }

        foreach ($registry->series as $row) {
            $registry->index[self::key($row->name, $row->labels, $row->suffix, $row->bucket)] = $row->id;
        }

        return $registry;
    }

    /** @return list<MetricSeries> */
    public function series(): array
    {
        return $this->series;
    }

    public function definition(string $name): ?MetricDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    /** @return list<float> */
    public function buckets(): array
    {
        return $this->buckets;
    }

    /**
     * 把调用方传来的标签解析成行键。
     *
     * 返回 null 表示这个组合没有被声明过（指标名未知、缺标签、多余标签或标签值不在闭集内），
     * 由调用方计入 core_metrics_series_dropped_total。传参顺序无关：按定义的标签顺序归一化。
     *
     * @param array<array-key, mixed> $labels
     */
    public function resolve(string $name, array $labels, string $suffix = '', ?float $bucket = null): ?int
    {
        $ordered = $this->normalizeLabels($name, $labels);
        if ($ordered === null) {
            return null;
        }

        return $this->index[self::key($name, $ordered, $suffix, $bucket)] ?? null;
    }

    /**
     * 一次直方图观测要写的全部行：桶、_sum 与 _count。
     *
     * 单开这个方法而不是让调用方逐行 resolve()：resolve() 每次都要重新校验标签值域并重建标签
     * 文本，而一次观测最多 12 行（10 桶 + sum + count）—— 那等于同一组标签被校验 12 遍。
     * 这里标签只处理一次，返回的行键与 {@see buckets()} 的下标一一对应。
     *
     * 桶上界刻意不在这里返回：它在构建期就固定了，调用方直接从 buckets() 取。为每个桶现建一个
     * 关联数组的开销，在热路径上比行键查找本身还贵。
     *
     * 返回 null 表示这不是一个已声明的直方图，或标签组合未声明，由调用方计入 dropped。
     *
     * @param array<array-key, mixed> $labels
     * @return null|array{sum: null|int, count: null|int, buckets: list<null|int>}
     */
    public function resolveHistogram(string $name, array $labels): ?array
    {
        $definition = $this->definitions[$name] ?? null;
        if ($definition === null || $definition->type !== MetricType::HISTOGRAM) {
            return null;
        }

        $ordered = $this->normalizeLabels($name, $labels);
        if ($ordered === null) {
            return null;
        }

        // 行键形如 name \0 suffix \0 bucket \0 label=value ...：标签在所有行里都是同一段文本，
        // 拼一次复用，桶循环里就只剩两段在变。
        $labelsPart = self::labelsPart($ordered);

        $buckets = [];
        foreach ($this->bucketKeys as $bucketKey) {
            $buckets[] = $this->index[self::keyFromParts($name, '_bucket', $bucketKey, $labelsPart)] ?? null;
        }

        return [
            'buckets' => $buckets,
            'sum' => $this->index[self::keyFromParts($name, '_sum', '', $labelsPart)] ?? null,
            'count' => $this->index[self::keyFromParts($name, '_count', '', $labelsPart)] ?? null,
        ];
    }

    /**
     * 校验标签值域并把它们归一化成定义里的顺序；null 表示这个组合没有被声明过。
     *
     * @param array<array-key, mixed> $labels
     * @return null|array<string, string>
     */
    private function normalizeLabels(string $name, array $labels): ?array
    {
        $definition = $this->definitions[$name] ?? null;
        if ($definition === null) {
            return null;
        }

        $ordered = [];
        foreach ($definition->labelNames as $labelName) {
            $value = $labels[$labelName] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                return null;
            }

            $value = (string) $value;
            if (! isset($this->allowedValues[$name][$labelName][$value])) {
                return null;
            }

            $ordered[$labelName] = $value;
        }

        // 传了未声明的标签：与位置无关地拒绝，避免同一组合被拆成两条序列。
        if (count($labels) !== count($ordered)) {
            return null;
        }

        return $ordered;
    }

    /**
     * @param array<string, string> $labels
     */
    private function appendSeries(MetricDefinition $definition, array $labels): void
    {
        if ($definition->type !== MetricType::HISTOGRAM) {
            $this->series[] = new MetricSeries(
                count($this->series),
                $definition->name,
                $definition->type,
                $definition->help,
                $labels
            );

            return;
        }

        $id = count($this->series);
        foreach ($this->buckets as $bucket) {
            $this->series[] = new MetricSeries(
                $id++,
                $definition->name,
                $definition->type,
                $definition->help,
                $labels,
                '_bucket',
                $bucket
            );
        }

        $this->series[] = new MetricSeries($id++, $definition->name, $definition->type, $definition->help, $labels, '_sum');
        $this->series[] = new MetricSeries($id, $definition->name, $definition->type, $definition->help, $labels, '_count');
    }

    /**
     * @param list<string> $labelNames
     * @param array<string, list<string>> $labelValues
     * @return list<array<string, string>>
     */
    private static function labelCombinations(array $labelNames, array $labelValues): array
    {
        $combinations = [[]];
        foreach ($labelNames as $labelName) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($labelValues[$labelName] as $value) {
                    $next[] = $combination + [$labelName => $value];
                }
            }

            $combinations = $next;
        }

        /* @var list<array<string, string>> $combinations */
        return $combinations;
    }

    /**
     * @param array<string, list<string>> $labelValues
     * @return array<string, array<string, true>>
     */
    private static function valueLookup(array $labelValues): array
    {
        $lookup = [];
        foreach ($labelValues as $labelName => $values) {
            foreach ($values as $value) {
                $lookup[$labelName][$value] = true;
            }
        }

        return $lookup;
    }

    /**
     * @param array<array-key, mixed> $configured
     * @return list<string>
     */
    private static function metricNames(array $configured): array
    {
        $names = [];
        foreach (array_keys($configured) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        $names[] = 'unknown';

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, list<string>> $dynamicValues
     * @return list<string>
     */
    private static function resolveValues(mixed $values, array $dynamicValues): array
    {
        if (is_string($values)) {
            $dynamic = $dynamicValues[ltrim($values, '@')] ?? [];

            return array_values(array_unique(array_map('strval', $dynamic)));
        }

        if (! is_array($values)) {
            return [];
        }

        $resolved = [];
        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $text = (string) $value;
                if ($text !== '') {
                    $resolved[] = $text;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /** @return list<float> */
    private static function readBuckets(ConfigInterface $config): array
    {
        /** @var array<array-key, mixed> $configured */
        $configured = $config->get('metrics.histogram_buckets', []);
        $buckets = [];
        foreach ($configured as $bucket) {
            if (is_int($bucket) || is_float($bucket)) {
                $value = (float) $bucket;
                if ($value > 0) {
                    $buckets[] = $value;
                }
            }
        }

        sort($buckets);

        return $buckets === [] ? [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0] : $buckets;
    }

    /**
     * @param array<string, string> $labels
     */
    private static function key(string $name, array $labels, string $suffix, ?float $bucket): string
    {
        return self::keyFromParts(
            $name,
            $suffix,
            $bucket === null ? '' : self::bucketKey($bucket),
            self::labelsPart($labels)
        );
    }

    /**
     * @param array<string, string> $labels
     */
    private static function labelsPart(array $labels): string
    {
        $part = '';
        foreach ($labels as $labelName => $value) {
            $part .= "\0" . $labelName . '=' . $value;
        }

        return $part;
    }

    /**
     * 行键拼装的唯一出口：格式只在这里定义，避免热路径上另拼一份而慢慢漂移。
     */
    private static function keyFromParts(string $name, string $suffix, string $bucketKey, string $labelsPart): string
    {
        return $name . "\0" . $suffix . "\0" . $bucketKey . $labelsPart;
    }

    private static function bucketKey(float $bucket): string
    {
        $formatted = rtrim(rtrim(number_format($bucket, 9, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
