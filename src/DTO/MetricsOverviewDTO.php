<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\DTO;

use Hyperf\Apex\Metrics\Enum\AlertLevel;

/**
 * `GET /metrics/overview` 的响应体，即运维大屏的数据。
 *
 * 速率类字段（qps / requests / errors）是**两次采样之间的增量**，不是累计值：
 * 累计值对看现场没有意义，而 Prometheus 不在时的历史基准就存在共享内存里的一行。
 */
final readonly class MetricsOverviewDTO
{
    /**
     * @param int $intervalSeconds 距上次采样的秒数；0 表示这是第一次采样，还没有基准
     * @param int $requests 本次间隔内的请求数
     * @param int $errors 本次间隔内的 4xx + 5xx 请求数
     * @param float $errorRate 错误率（0..1）；没有请求时为 0
     * @param null|float $p50Seconds 中位耗时（秒）；没有样本时为 null
     * @param null|float $p95Seconds P95 耗时；没有样本或落出最大桶上界时为 null
     * @param null|float $p99Seconds P99 耗时；同上
     * @param null|float $avgSeconds 平均耗时；没有样本时为 null
     * @param list<MetricsAlertDTO> $alerts 已触发的告警
     * @param list<MetricsGaugeDTO> $gauges 本次采样到的 gauge
     */
    public function __construct(
        public string $sampledAt,
        public int $intervalSeconds,
        public bool $hasBaseline,
        public int $refreshSeconds,
        public AlertLevel $status,
        public float $qps,
        public int $requests,
        public int $errors,
        public float $errorRate,
        public ?float $p50Seconds,
        public ?float $p95Seconds,
        public ?float $p99Seconds,
        public ?float $avgSeconds,
        public int $unhandledExceptions,
        public int $poolTimeouts,
        public int $droppedSeries,
        public array $alerts,
        public array $gauges,
    ) {
    }
}
