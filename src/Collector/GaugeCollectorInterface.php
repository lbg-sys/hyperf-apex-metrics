<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Collector;

/**
 * 采样采集器：在抓取时回答「当前值是多少」。
 *
 * 适用于那些「算得出来、但不该在热路径上维护」的量：队列深度、连接池占用、在线连接数。
 * 实现可以是查库、查共享内存或读本进程状态，但必须自己控制耗时 —— 结果会按
 * metrics.gauge_cache_ttl_seconds 缓存，抓取频率高于 TTL 时不会重复回源。
 */
interface GaugeCollectorInterface
{
    /** @return list<GaugeSample> */
    public function collect(): array;
}
