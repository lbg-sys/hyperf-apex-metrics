<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;

/**
 * 把 Redis 连接池换成会记录等待与超时的实现。理由与数据库侧一致，
 * 见 {@see MetricsDbPoolFactory}。
 */
final class MetricsRedisPoolFactory extends PoolFactory
{
    public function getPool(string $name): RedisPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        return $this->pools[$name] = new MetricsRedisPool($this->container, $name);
    }

    /**
     * 已创建的池，供进程快照采集占用数。理由见 {@see MetricsDbPoolFactory::all()}。
     *
     * @return array<string, RedisPool>
     */
    public function all(): array
    {
        return $this->pools;
    }
}
