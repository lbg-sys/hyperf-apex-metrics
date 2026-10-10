<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Listener;

use Hyperf\Apex\Metrics\Pool\PoolNames;
use Hyperf\Apex\Metrics\Pool\PoolSnapshotTable;
use Hyperf\Apex\Metrics\Registry\MetricDefinitionRegistry;
use Hyperf\Apex\Metrics\Scanner\HttpRoutePathScanner;
use Hyperf\Apex\Metrics\Storage\MetricTable;
use Hyperf\Apex\Metrics\Storage\RateSampleTable;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Psr\Container\ContainerInterface;

/**
 * 在 master 进程、fork worker 之前创建指标共享内存表。
 *
 * 时机不能挪到 WorkerStart：那样每个 worker 会各建一份自己的表，跨进程的计数就散掉了 ——
 * 与 WsTableInitListener 是同一个约束。
 *
 * 这里只做「建表」不做「容错」：容量不足或行写不进去都在启动期直接抛错。
 * 指标写不进去本身是静默的，让它晚到运行期暴露就等于默认丢数据。
 */
final class MetricTableInitListener implements ListenerInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ContainerInterface $container,
    ) {
    }

    public function listen(): array
    {
        return [BeforeMainServerStart::class];
    }

    public function process(object $event): void
    {
        if (! (bool) $this->config->get('metrics.enabled', true)) {
            return;
        }

        $poolNames = PoolNames::fromConfig($this->config);

        $registry = MetricDefinitionRegistry::fromConfig($this->config, [
            'http_routes' => $this->container->get(HttpRoutePathScanner::class)->scan('http'),
            'pool_names' => $poolNames,
        ]);

        MetricTable::init($registry, max(1, (int) $this->config->get('metrics.table_size', 16384)));
        PoolSnapshotTable::init($poolNames, max(8, (int) $this->config->get('metrics.process_table_size', 64)));

        // 大屏的速率差分基准。开关独立于总开关：只看 Prometheus 的部署不需要它。
        if ((bool) $this->config->get('metrics.overview.enabled', true)) {
            RateSampleTable::init();
        }
    }
}
