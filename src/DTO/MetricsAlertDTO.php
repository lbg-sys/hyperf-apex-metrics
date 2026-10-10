<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\DTO;

use Hyperf\Apex\Metrics\Enum\AlertLevel;

/**
 * 大屏上的一条告警：某个指标越过了 `metrics.overview.thresholds` 里配的档位。
 *
 * 只回传**已触发**的项：没越线的指标不进这个列表，页面不必自己过滤，
 * 也避免把「配了阈值但当前正常」误读成告警。
 */
final readonly class MetricsAlertDTO
{
    public function __construct(
        public string $key,
        public AlertLevel $level,
        public float $value,
        public float $warning,
        public float $critical,
    ) {
    }
}
