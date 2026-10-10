<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Pool;

use Hyperf\Contract\ConfigInterface;

/**
 * 连接池标签的取值集合，从连接配置的键派生。
 *
 * 不手写清单：手写意味着新增一个数据库或 Redis 连接时要记得在两处同步，
 * 漏掉的那次指标会被静默丢弃（只会体现在 dropped 计数上，很难联想到是标签没登记）。
 */
final class PoolNames
{
    /** @return list<string> */
    public static function fromConfig(ConfigInterface $config): array
    {
        $names = [];

        /** @var array<string, string> $sources 配置键 => 标签前缀 */
        $sources = [
            'databases' => 'db',
            'redis' => 'redis',
        ];

        foreach ($sources as $configKey => $prefix) {
            $connections = $config->get($configKey, []);
            if (! is_array($connections)) {
                continue;
            }

            foreach (array_keys($connections) as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $prefix . '.' . $name;
                }
            }
        }

        return $names;
    }
}
