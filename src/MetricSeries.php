<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics;

use Hyperf\Apex\Metrics\Enum\MetricType;

/**
 * 共享内存表里的一行，即 Prometheus 的一条时间序列。
 *
 * histogram 的每个桶、_sum、_count 各占一行，因此一个 histogram 序列会展开成多行；
 * 它们共享同一个 name 与 labels，靠 suffix / bucket 区分。
 */
final readonly class MetricSeries
{
    /**
     * @param int $id 表的行键（数字串，Swoole\Table 的键上限 63 字节）
     * @param array<string, string> $labels
     * @param ''|'_bucket'|'_count'|'_sum' $suffix
     * @param null|float $bucket 仅 _bucket 行有值，单位秒
     */
    public function __construct(
        public int $id,
        public string $name,
        public MetricType $type,
        public string $help,
        public array $labels,
        public string $suffix = '',
        public ?float $bucket = null,
    ) {
    }

    /**
     * 桶上界的文本形式。
     *
     * 不能用 (string) 直接转：浮点转字符串受 precision ini 影响，
     * `le` 一旦出现 `0.10000000000000001` 这类噪声，序列名就与预期不符了。
     */
    public function bucketLabel(): string
    {
        if ($this->bucket === null) {
            return '';
        }

        $formatted = rtrim(rtrim(number_format($this->bucket, 9, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
