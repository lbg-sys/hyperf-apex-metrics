<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Service;

use Hyperf\Apex\Metrics\Collector\GaugeCollectorRegistry;
use Hyperf\Apex\Metrics\Renderer\PrometheusRenderer;
use Hyperf\Apex\Metrics\Storage\MetricTable;
use Hyperf\Contract\ConfigInterface;

/**
 * 把共享内存里的累计值 + 采样指标拼成一次 Prometheus 抓取的内容。
 */
final class MetricsSnapshotService
{
    public function __construct(
        private readonly PrometheusRenderer $renderer,
        private readonly GaugeCollectorRegistry $collectors,
        private readonly ConfigInterface $config,
    ) {
    }

    /**
     * 端点是否可用。
     *
     * `metrics.enabled=false` 时不建表、埋点全是 no-op，此时端点应给出 404 而不是
     * 一份「所有指标都是 0」的文本 —— 后者会被抓取方当成真实数据存进时序库。
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('metrics.enabled', true) && MetricTable::initialized();
    }

    public function render(): string
    {
        $registry = MetricTable::registry();

        return $this->renderer->render(
            $registry === null ? [] : $registry->series(),
            MetricTable::snapshot(),
            $this->collectors->collect(),
        );
    }
}
