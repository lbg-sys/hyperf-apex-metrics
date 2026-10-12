<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\Apex\Support\Time;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Pool\PoolFactory as DbPoolFactory;
use Hyperf\Pool\Pool;
use Hyperf\Redis\Pool\PoolFactory as RedisPoolFactory;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * 采集本进程的连接池占用并写进共享快照表。
 *
 * 触发点是「取到一次连接之后」而不是定时器：定时器要为 worker、task worker 与每个自定义进程
 * 分别注册，漏掉任何一个（自定义进程根本不发 WorkerStart 事件）都会让总和偏小；
 * 而一个不用连接池的进程，其占用恒为 0，本来就不需要上报。
 *
 * 节流放在进程内静态变量上：它天然是每进程一份，不需要额外同步。
 */
final class PoolSnapshot
{
    /**
     * 无变化时的保活间隔（秒）。
     *
     * 节流只对「状态没变」的上报生效：纯粹按时间节流会让刚建立起来的连接在抓取时仍显示为
     * 未占用（下一次上报要等到间隔结束），而抓取恰好可能就落在这个窗口里。
     */
    private const REPORT_INTERVAL_SECONDS = 5;

    private static int $lastReportAt = 0;

    /** @var array<string, int> */
    private static array $lastValues = [];

    private static ?ContainerInterface $container = null;

    /**
     * 池名列表由连接配置派生、运行期不会变，因此缓存下来 —— 这个方法是挂在「每次取连接」
     * 后面的，重读一遍配置纯属浪费。
     *
     * 只缓存名字，不缓存池对象：连接池是懒创建的，把「此刻还没有池」固化下来会让快照永远是 0。
     *
     * @var null|list<string>
     */
    private static ?array $poolNames = null;

    public static function touch(): void
    {
        try {
            if (! PoolSnapshotTable::initialized()) {
                return;
            }

            $values = self::values();
            $now = Time::now()->getTimestamp();
            if ($values === self::$lastValues && $now - self::$lastReportAt < self::REPORT_INTERVAL_SECONDS) {
                return;
            }

            self::$lastValues = $values;
            self::$lastReportAt = $now;

            PoolSnapshotTable::write((string) getmypid(), $values, $now);
        } catch (Throwable) {
            // 快照是旁路观测：任何失败都不能影响这次取连接的结果。
        }
    }

    /**
     * 只给测试用：清掉进程内缓存，让下一个用例从干净的容器与配置开始。
     */
    public static function reset(): void
    {
        self::$container = null;
        self::$poolNames = null;
        self::$lastValues = [];
        self::$lastReportAt = 0;
    }

    /**
     * @return array<string, int>
     */
    private static function values(): array
    {
        $container = self::container();
        $pools = self::pools($container);

        $values = [];
        foreach (self::poolNames($container) as $index => $poolName) {
            $pool = $pools[$poolName] ?? null;
            $idle = $pool === null ? 0 : $pool->getConnectionsInChannel();
            $current = $pool === null ? 0 : $pool->getCurrentConnections();

            $values[PoolSnapshotTable::idleColumn($index)] = $idle;
            $values[PoolSnapshotTable::inUseColumn($index)] = max(0, $current - $idle);
        }

        return $values;
    }

    private static function container(): ContainerInterface
    {
        return self::$container ??= ApplicationContext::getContainer();
    }

    /**
     * @return list<string>
     */
    private static function poolNames(ContainerInterface $container): array
    {
        return self::$poolNames ??= PoolNames::fromConfig($container->get(ConfigInterface::class));
    }

    /**
     * 只取本项目替换过的工厂：绑定被改回框架实现时，这里取不到 all() 就退化成空快照，
     * 而不是抛错 —— 指标不该因为别人的配置回退而让取连接失败。
     *
     * @return array<string, Pool>
     */
    private static function pools(ContainerInterface $container): array
    {
        $pools = [];

        $dbFactory = $container->get(DbPoolFactory::class);
        if ($dbFactory instanceof MetricsDbPoolFactory) {
            foreach ($dbFactory->all() as $name => $pool) {
                $pools['db.' . $name] = $pool;
            }
        }

        $redisFactory = $container->get(RedisPoolFactory::class);
        if ($redisFactory instanceof MetricsRedisPoolFactory) {
            foreach ($redisFactory->all() as $name => $pool) {
                $pools['redis.' . $name] = $pool;
            }
        }

        return $pools;
    }
}
