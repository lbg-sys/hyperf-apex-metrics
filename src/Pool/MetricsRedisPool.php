<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\Redis\Pool\RedisPool;

/**
 * 记录等待与超时的 Redis 连接池。由 {@see MetricsRedisPoolFactory} 创建，
 * 工厂在 config/autoload/dependencies.php 里替换掉框架默认实现。
 */
final class MetricsRedisPool extends RedisPool
{
    use RecordsPoolMetrics;

    protected function metricPoolName(): string
    {
        return 'redis.' . $this->getName();
    }
}
