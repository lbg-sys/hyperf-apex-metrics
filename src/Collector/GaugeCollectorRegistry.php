<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Collector;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * 采样采集器注册表。
 *
 * 集合来自 config/autoload/metrics.php 的 collectors —— 显式优于扫描，与 Scheduler 的
 * handles 同一套取舍：目录扫描会把「只用于测试或还没做完」的实现一起挂上去。
 *
 * 缓存放在这里而不是每个采集器里：TTL 是一个全局口径，采集器只管算。
 */
final class GaugeCollectorRegistry
{
    /** @var list<GaugeCollectorInterface> */
    private array $collectors = [];

    /** @var null|list<GaugeSample> */
    private ?array $cached = null;

    private float $cachedAt = 0.0;

    private readonly int $ttl;

    public function __construct(
        ContainerInterface $container,
        ConfigInterface $config,
    ) {
        $this->ttl = max(0, (int) $config->get('metrics.gauge_cache_ttl_seconds', 15));

        $configured = $config->get('metrics.collectors', []);
        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            try {
                $collector = $container->get($class);
            } catch (Throwable) {
                continue;
            }

            if ($collector instanceof GaugeCollectorInterface) {
                $this->collectors[] = $collector;
            }
        }
    }

    /**
     * 绕过缓存的采集。
     *
     * 给运维大屏用：它的刷新间隔（默认 10 秒）短于缓存 TTL，走缓存会连着两次拿到同一份数据，
     * 页面看起来像卡住了。Prometheus 抓取仍走 collect()，缓存是给高频抓取准备的。
     *
     * @return list<GaugeSample>
     */
    public function collectFresh(): array
    {
        $samples = [];
        foreach ($this->collectors as $collector) {
            try {
                foreach ($collector->collect() as $sample) {
                    $samples[] = $sample;
                }
            } catch (Throwable) {
                // 与 collect() 同一套隔离：一个采集器坏了不该让整页数据变空。
            }
        }

        $this->cached = null;

        return $samples;
    }

    /**
     * @return list<GaugeSample>
     */
    public function collect(): array
    {
        $now = microtime(true);
        if ($this->cached !== null && ($now - $this->cachedAt) < $this->ttl) {
            return $this->cached;
        }

        $samples = [];
        foreach ($this->collectors as $collector) {
            try {
                foreach ($collector->collect() as $sample) {
                    $samples[] = $sample;
                }
            } catch (Throwable) {
                // 单个采集器失败只丢它自己的样本：一个坏掉的采集器不该让整个端点变空。
            }
        }

        $this->cached = $samples;
        $this->cachedAt = $now;

        return $samples;
    }
}
