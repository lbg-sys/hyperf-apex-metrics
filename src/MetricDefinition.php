<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics;

use Hyperf\Apex\Metrics\Enum\MetricType;

/**
 * 一个指标的声明。
 *
 * 标签取值域必须是闭集：所有 (标签组合) 会在启动期展开成共享内存表的行，
 * 运行期只做「查表 → 原子自增」。开放值域（请求 id、账号 id、原始 URL）不允许进来，
 * 它们既是基数炸弹，也写不进定长表。
 */
final readonly class MetricDefinition
{
    /**
     * @param string $name 指标名（Prometheus 命名规则）
     * @param MetricType $type 指标类型
     * @param string $help 中文说明，渲染成 # HELP
     * @param list<string> $labelNames 标签名，顺序即渲染顺序
     * @param array<string, list<string>> $labelValues 标签名 => 取值域
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $labelNames,
        public array $labelValues,
    ) {
    }
}
