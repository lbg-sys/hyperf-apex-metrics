<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Service;

use Hyperf\Apex\Metrics\Collector\GaugeCollectorRegistry;
use Hyperf\Apex\Metrics\DTO\MetricsOverviewDTO;
use Hyperf\Apex\Metrics\Overview\MetricAggregator;
use Hyperf\Apex\Metrics\Overview\OverviewComposer;
use Hyperf\Apex\Metrics\Storage\MetricTable;
use Hyperf\Apex\Metrics\Storage\RateSampleTable;
use Hyperf\Contract\ConfigInterface;

/**
 * 大屏数据：读本次聚合值 + 上次基准 → 算 → 把本次写回基准。
 *
 * 基准只在有人看大屏时才更新（这次请求就是「有人看」），与 `RateSampleTable` 里
 * 「刻意不做定时采样」的取舍一致：没人看的时候维护基准只是白花代价。
 */
final class MetricsOverviewService
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly GaugeCollectorRegistry $collectors,
        private readonly OverviewComposer $composer,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('metrics.enabled', true)
            && (bool) $this->config->get('metrics.overview.enabled', true)
            && MetricTable::initialized();
    }

    public function overview(): MetricsOverviewDTO
    {
        $now = time();
        $current = MetricAggregator::aggregate(
            MetricTable::registry(),
            MetricTable::snapshot(),
            $this->aggregates(),
        );
        $previous = RateSampleTable::read();

        /*
         * 大屏的刷新间隔（默认 10 秒）短于采样指标的缓存 TTL（默认 15 秒），
         * 走缓存会连着两次拿到同一份数据、页面看起来像卡住了，所以这里强制回源。
         */
        $overview = $this->composer->compose(
            $current,
            $previous['values'],
            $previous['at'],
            $this->collectors->collectFresh(),
            $now,
        );

        RateSampleTable::write($now, $current);

        return $overview;
    }

    /**
     * @return array<string, list<string>>
     */
    private function aggregates(): array
    {
        $configured = $this->config->get('metrics.overview.aggregates', []);
        if (! is_array($configured)) {
            return [];
        }

        $spec = [];
        foreach ($configured as $metric => $labels) {
            if (is_string($metric) && is_array($labels)) {
                $spec[$metric] = array_values(array_filter($labels, 'is_string'));
            }
        }

        return $spec;
    }
}
