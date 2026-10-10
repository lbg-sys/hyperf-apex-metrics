<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\DbConnection\Pool\DbPool;
use Hyperf\DbConnection\Pool\PoolFactory;

/**
 * 把数据库连接池换成会记录等待与超时的实现。
 *
 * 工厂是框架创建并长期持有池实例的唯一位置，因此这是唯一能覆盖全部取连接路径、
 * 又不必改业务代码的替换点。子类只改池的类型，其余行为（复用、flushAll）全部继承。
 */
final class MetricsDbPoolFactory extends PoolFactory
{
    public function getPool(string $name): DbPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        return $this->pools[$name] = new MetricsDbPool($this->container, $name);
    }

    /**
     * 已创建的池，供进程快照采集占用数。
     *
     * 只列出「已经被取过连接」的池：池是懒建的，从未用过的连接池占用恒为 0，
     * 不需要为它编造一个快照。
     *
     * @return array<string, DbPool>
     */
    public function all(): array
    {
        return $this->pools;
    }
}
