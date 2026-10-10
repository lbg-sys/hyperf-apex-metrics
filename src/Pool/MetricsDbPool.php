<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\DbConnection\Pool\DbPool;

/**
 * 记录等待与超时的数据库连接池。由 {@see MetricsDbPoolFactory} 创建，
 * 工厂在 config/autoload/dependencies.php 里替换掉框架默认实现。
 */
final class MetricsDbPool extends DbPool
{
    use RecordsPoolMetrics;

    protected function metricPoolName(): string
    {
        return 'db.' . $this->getName();
    }
}
