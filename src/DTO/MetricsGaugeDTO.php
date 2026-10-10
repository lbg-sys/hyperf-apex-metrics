<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\DTO;

use Hyperf\Apex\Metrics\Collector\GaugeSample;

/**
 * 大屏上的一条采样指标（连接池占用、在线连接数等）。
 */
final readonly class MetricsGaugeDTO
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $name,
        public array $labels,
        public float|int $value,
    ) {
    }

    public static function fromSample(GaugeSample $sample): self
    {
        return new self($sample->name, $sample->labels, $sample->value);
    }
}
