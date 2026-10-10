<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Overview;

use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Metrics\DTO\MetricsAlertDTO;
use Hyperf\Apex\Metrics\DTO\MetricsGaugeDTO;
use Hyperf\Apex\Metrics\DTO\MetricsOverviewDTO;
use Hyperf\Apex\Metrics\Enum\AlertLevel;
use Hyperf\Apex\Metrics\Service\MetricsOverviewService;
use Hyperf\Apex\Support\Time;
use Hyperf\Contract\ConfigInterface;

/**
 * 把两次聚合结果算成大屏数据。
 *
 * 只做计算、不碰共享内存与连接池：调用方（{@see MetricsOverviewService}）
 * 负责取数与写回基准，这里保证「同样的输入永远得到同样的输出」，也才能在单测里把
 * 分位数、错误率、阈值判定这些真正会算错的地方钉住。
 */
final class OverviewComposer
{
    private const HTTP_REQUESTS = 'core_http_requests_total';

    private const HTTP_DURATION = 'core_http_request_duration_seconds';

    private const UNHANDLED_EXCEPTIONS = 'core_http_unhandled_exceptions_total';

    private const POOL_TIMEOUTS = 'core_pool_timeout_total';

    private const DROPPED_SERIES = 'core_metrics_series_dropped_total';

    /** 由累计指标算出来的阈值 key；其余 key 去采样指标里找同后缀的那条。 */
    private const BUILT_IN_THRESHOLDS = ['error_rate', 'p95_seconds', 'pool_timeout'];

    /** 直方图 `_sum` 在共享内存里存的是微秒。 */
    private const MICROS_PER_SECOND = 1_000_000;

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    /**
     * @param array<string, array<string, int>> $current 本次聚合值（指标名 => [聚合键 => 累计值]）
     * @param array<string, array<string, int>> $previous 上次聚合值；没有基准时传空数组
     * @param int $previousAt 上次采样时间戳；0 表示没有基准
     * @param list<GaugeSample> $gauges 本次采样到的 gauge
     */
    public function compose(array $current, array $previous, int $previousAt, array $gauges, int $now): MetricsOverviewDTO
    {
        $gaugeDtos = array_map(
            static fn (GaugeSample $sample): MetricsGaugeDTO => MetricsGaugeDTO::fromSample($sample),
            $gauges,
        );
        $refreshSeconds = max(1, (int) $this->config->get('metrics.overview.refresh_seconds', 10));

        if ($previousAt <= 0) {
            /*
             * 没有基准就没有「本次间隔」：增量类数值一律按不可用处理（0 / null），
             * 而不是把累计值当成这一段的增量 —— 那会让大屏第一眼就是一个假峰值，
             * 峰值有多高只取决于服务已经跑了多久。
             *
             * 采样指标是即时值，不受基准影响，照常回传。
             */
            return new MetricsOverviewDTO(
                sampledAt: Time::now()->toDateTimeString(),
                intervalSeconds: 0,
                hasBaseline: false,
                refreshSeconds: $refreshSeconds,
                status: AlertLevel::OK,
                qps: 0.0,
                requests: 0,
                errors: 0,
                errorRate: 0.0,
                p50Seconds: null,
                p95Seconds: null,
                p99Seconds: null,
                avgSeconds: null,
                unhandledExceptions: 0,
                poolTimeouts: 0,
                droppedSeries: 0,
                alerts: [],
                gauges: $gaugeDtos,
            );
        }

        $delta = MetricAggregator::delta($current, $previous);
        $interval = max(0, $now - $previousAt);

        $requests = MetricAggregator::total($delta, self::HTTP_REQUESTS);
        $errors = $this->errors($delta[self::HTTP_REQUESTS] ?? []);
        $errorRate = $requests > 0 ? $errors / $requests : 0.0;

        $duration = $delta[self::HTTP_DURATION] ?? [];
        $count = $this->histogramValue($duration, MetricAggregator::HISTOGRAM_COUNT);
        $sumSeconds = $this->histogramValue($duration, MetricAggregator::HISTOGRAM_SUM) / self::MICROS_PER_SECOND;

        $p50 = $this->quantile($duration, $count, 0.50);
        $p95 = $this->quantile($duration, $count, 0.95);
        $p99 = $this->quantile($duration, $count, 0.99);

        $alerts = $this->alerts([
            'error_rate' => $errorRate,
            'p95_seconds' => $p95,
            'pool_timeout' => (float) MetricAggregator::total($delta, self::POOL_TIMEOUTS),
        ], $gauges);

        $status = AlertLevel::OK;
        foreach ($alerts as $alert) {
            $status = $status->worst($alert->level);
        }

        return new MetricsOverviewDTO(
            sampledAt: Time::now()->toDateTimeString(),
            intervalSeconds: $interval,
            hasBaseline: true,
            refreshSeconds: $refreshSeconds,
            status: $status,
            qps: $interval > 0 ? $requests / $interval : 0.0,
            requests: $requests,
            errors: $errors,
            errorRate: $errorRate,
            p50Seconds: $p50,
            p95Seconds: $p95,
            p99Seconds: $p99,
            avgSeconds: $count > 0 ? $sumSeconds / $count : null,
            unhandledExceptions: MetricAggregator::total($delta, self::UNHANDLED_EXCEPTIONS),
            poolTimeouts: MetricAggregator::total($delta, self::POOL_TIMEOUTS),
            droppedSeries: MetricAggregator::total($delta, self::DROPPED_SERIES),
            alerts: $alerts,
            gauges: $gaugeDtos,
        );
    }

    /**
     * 4xx / 5xx 的请求数。
     *
     * 聚合键就是 `status_class` 的值（`metrics.overview.aggregates` 保留了它）。
     * 若把该标签从聚合规格里去掉，键会退化成空串、这里算不出错误率 ——
     * 所以配置注释里写明这个标签要保留，不在这里猜测。
     *
     * @param array<string, int> $byStatusClass
     */
    private function errors(array $byStatusClass): int
    {
        $errors = 0;
        foreach ($byStatusClass as $statusClass => $value) {
            if ($statusClass !== '' && ($statusClass[0] === '4' || $statusClass[0] === '5')) {
                $errors += $value;
            }
        }

        return $errors;
    }

    /**
     * 逐条比较 `metrics.overview.thresholds`，只回传已触发的档位。
     *
     * @param array<string, null|float> $builtIn 内置阈值 key => 值；null 表示这次算不出来
     * @param list<GaugeSample> $gauges
     * @return list<MetricsAlertDTO>
     */
    private function alerts(array $builtIn, array $gauges): array
    {
        $thresholds = $this->config->get('metrics.overview.thresholds', []);
        if (! is_array($thresholds)) {
            return [];
        }

        $alerts = [];
        foreach ($thresholds as $key => $limit) {
            if (! is_string($key) || ! is_array($limit) || ! isset($limit['warning'], $limit['critical'])) {
                continue;
            }

            $value = in_array($key, self::BUILT_IN_THRESHOLDS, true)
                ? ($builtIn[$key] ?? null)
                : $this->gaugeValue($gauges, $key);

            // 算不出来的指标不参与判定：宁可不报，也不能拿 0 去比阈值报出一个假的「正常」。
            if ($value === null) {
                continue;
            }

            $warning = (float) $limit['warning'];
            $critical = (float) $limit['critical'];
            $level = match (true) {
                $value >= $critical => AlertLevel::CRITICAL,
                $value >= $warning => AlertLevel::WARNING,
                default => AlertLevel::OK,
            };

            if ($level === AlertLevel::OK) {
                continue;
            }

            $alerts[] = new MetricsAlertDTO($key, $level, $value, $warning, $critical);
        }

        return $alerts;
    }

    /**
     * 应用侧的阈值 key 取「同后缀的采样指标」。
     *
     * 约定写成 `core_<key>`（例如阈值 `alert_backlog_seconds` 对应采样指标
     * `core_alert_backlog_seconds`）：阈值配置因此不必知道指标是怎么采集的，
     * 组件也不必为每个业务指标硬编码一条判定。
     *
     * @param list<GaugeSample> $gauges
     */
    private function gaugeValue(array $gauges, string $key): ?float
    {
        foreach ($gauges as $gauge) {
            if ($gauge->name === 'core_' . $key || str_ends_with($gauge->name, $key)) {
                return (float) $gauge->value;
            }
        }

        return null;
    }

    /**
     * 取直方图差分里 `_sum` / `_count` 的值。
     *
     * 聚合规格不保留标签时键就是 `_sum`；保留了标签时形如 `target|_sum`。两种都认，
     * 调用方不必关心聚合规格怎么配。
     *
     * @param array<string, int> $duration
     */
    private function histogramValue(array $duration, string $suffix): int
    {
        foreach ($duration as $key => $value) {
            if ($key === $suffix || str_ends_with($key, '|' . $suffix)) {
                return $value;
            }
        }

        return 0;
    }

    /**
     * 从累积桶差分里估分位数。
     *
     * 桶上界是固定列表（`metrics.histogram_buckets`），差分后仍是累积计数：取第一个
     * 「累积计数 ≥ 目标名次」的桶上界作为估计 —— 与 Prometheus 的 histogram_quantile
     * 同一套近似，误差不超过一个桶宽。
     *
     * 落到最大有限桶之外时返回 null：那说明观测值超过了最大可测上界，返回一个假的
     * 上界数字会让页面看起来「刚好卡在阈值上」。
     *
     * @param array<string, int> $duration
     */
    private function quantile(array $duration, int $count, float $quantile): ?float
    {
        if ($count <= 0) {
            return null;
        }

        $rows = [];
        foreach ($duration as $key => $value) {
            $bound = $this->bucketBound($key);
            if ($bound !== null) {
                $rows[] = ['bound' => $bound, 'count' => $value];
            }
        }

        if ($rows === []) {
            return null;
        }

        usort($rows, static fn (array $left, array $right): int => $left['bound'] <=> $right['bound']);

        $rank = (int) ceil($count * min(1.0, max(0.0, $quantile)));
        foreach ($rows as $row) {
            if ($row['count'] >= $rank) {
                return $row['bound'];
            }
        }

        return null;
    }

    /**
     * 从聚合键里取出桶上界：`le:0.05`（保留标签时是 `label|le:0.05`）。
     *
     * 无穷桶在表里本来就没有行（渲染时用 `_count` 补出来），这里仍显式跳过，
     * 免得上界被当成一个能比较的有限值。
     */
    private function bucketBound(string $key): ?float
    {
        $position = strrpos($key, MetricAggregator::BUCKET_PREFIX);
        if ($position === false) {
            return null;
        }

        $label = substr($key, $position + strlen(MetricAggregator::BUCKET_PREFIX));
        if ($label === '+Inf') {
            return null;
        }

        return is_numeric($label) ? (float) $label : null;
    }
}
