<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\Apex\Metrics\Metrics;
use Hyperf\Contract\ConnectionInterface;
use Hyperf\Pool\Pool;
use Throwable;

/**
 * 连接池的等待与超时埋点。
 *
 * 池是进程私有资源，池内即时状态（多少连接闲着、多少在用）无法跨 worker 聚合；
 * 但「等了多久」和「等超时了几次」是计数器语义，写进共享内存表天然就是全局口径 ——
 * 这也正是「连接池等待情况」真正要回答的问题。
 *
 * 之所以用 trait 而不是各写一遍：数据库池与 Redis 池只有「池标识怎么拼」这一点不同，
 * 重写两份 get() 意味着超时判定逻辑迟早会在其中一份里被改歪。
 *
 * @phpstan-require-extends Pool
 */
trait RecordsPoolMetrics
{
    public function get(): ConnectionInterface
    {
        $startedAt = hrtime(true);

        try {
            return parent::get();
        } catch (Throwable $exception) {
            if ($this->poolExhausted()) {
                Metrics::incr('core_pool_timeout_total', ['pool' => $this->metricPoolName()]);
            }

            throw $exception;
        } finally {
            // 超时的那一次也要记：等待时长直方图最重要的长尾正是从这些样本里来的。
            Metrics::observe(
                'core_pool_wait_seconds',
                ['pool' => $this->metricPoolName()],
                (hrtime(true) - $startedAt) / 1_000_000_000,
            );

            // 顺手把本进程的池占用刷进共享快照（内部按 5 秒节流）。
            // 挂在取连接之后而不是定时器上：自定义进程收不到 WorkerStart 事件，
            // 用定时器就得逐个进程注册，漏一个总和就偏小。
            PoolSnapshot::touch();
        }
    }

    /**
     * 指标里的池标识，形如 `db.default` / `redis.default`。
     */
    abstract protected function metricPoolName(): string;

    /**
     * 判断这次失败是不是「等不到连接」。
     *
     * 不匹配异常消息里的文案（框架改一次措辞就失效），而是看池此刻的状态：
     * 空闲连接为 0 且已达上限就是等不到。连接建立失败（createConnection 抛错）时
     * currentConnections 已经回退，因此不会被误判成超时。
     */
    private function poolExhausted(): bool
    {
        return $this->getConnectionsInChannel() === 0
            && $this->getCurrentConnections() >= $this->getOption()->getMaxConnections();
    }
}
