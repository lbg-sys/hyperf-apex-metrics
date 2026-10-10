<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Collector;

/**
 * 一次采样得到的 gauge 值。
 *
 * 采样类指标（队列深度、连接池状态、在线连接数）不在共享表里累加，而是抓取时现算，
 * 因此它们的标签不做闭集校验 —— 但值域仍必须由采集器自己控制住。
 */
final readonly class GaugeSample
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $name,
        public string $help,
        public array $labels,
        public float|int $value,
    ) {
    }
}
