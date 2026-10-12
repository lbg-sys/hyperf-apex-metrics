<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\Apex\Metrics\Collector\GaugeCollectorInterface;
use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Support\Time;
use Hyperf\Contract\ConfigInterface;

/**
 * 连接池占用：空闲 / 使用中 / 上限。
 *
 * 前两个是各进程快照的求和，`max` 则直接来自配置 —— 上限是每个进程各自持有的同一个值，
 * 把它也求和会变成「上限 × 进程数」这个毫无意义的数字。
 *
 * 表未初始化（未启动 server）时整组不输出，而不是给出「0 个连接」：那会看起来像
 * 「池是空的」，与「根本没有采集到」是两回事。
 */
final class PoolGaugeCollector implements GaugeCollectorInterface
{
    private const HELP = '连接池占用，按池与状态拆分（idle / in_use 为各进程求和，max 为单进程上限）';

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function collect(): array
    {
        if (! PoolSnapshotTable::initialized()) {
            return [];
        }

        $poolNames = PoolNames::fromConfig($this->config);
        if ($poolNames === []) {
            return [];
        }

        $rows = PoolSnapshotTable::rows();
        $expiredBefore = Time::now()->getTimestamp() - max(10, (int) $this->config->get('metrics.pool_snapshot_ttl_seconds', 30));

        $samples = [];
        foreach ($poolNames as $index => $poolName) {
            $idle = 0;
            $inUse = 0;
            foreach ($rows as $row) {
                if (($row[PoolSnapshotTable::COLUMN_TIME] ?? 0) < $expiredBefore) {
                    // 进程重启后留下的行：pid 不会再来更新它。
                    continue;
                }

                $idle += (int) ($row[PoolSnapshotTable::idleColumn($index)] ?? 0);
                $inUse += (int) ($row[PoolSnapshotTable::inUseColumn($index)] ?? 0);
            }

            $samples[] = new GaugeSample('core_pool_connections', self::HELP, ['pool' => $poolName, 'state' => 'idle'], $idle);
            $samples[] = new GaugeSample('core_pool_connections', self::HELP, ['pool' => $poolName, 'state' => 'in_use'], $inUse);
            $samples[] = new GaugeSample('core_pool_connections', self::HELP, ['pool' => $poolName, 'state' => 'max'], $this->maxConnections($poolName));
        }

        return $samples;
    }

    private function maxConnections(string $poolName): int
    {
        $separator = strpos($poolName, '.');
        if ($separator === false) {
            return 0;
        }

        $backend = substr($poolName, 0, $separator);
        $connection = substr($poolName, $separator + 1);
        $configKey = ($backend === 'redis' ? 'redis.' : 'databases.') . $connection . '.pool.max_connections';

        return max(0, (int) $this->config->get($configKey, 0));
    }
}
