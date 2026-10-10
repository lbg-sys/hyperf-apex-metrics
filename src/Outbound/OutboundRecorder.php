<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Outbound;

use Hyperf\Apex\Metrics\Metrics;

/**
 * 第三方出站调用的统一埋点入口。
 *
 * 把「目标 + 结果 + 耗时」固定下来，调用方只负责判定自己这一次算成功还是失败。
 */
final class OutboundRecorder
{
    public const TARGET_HTTP = 'http';

    public const TARGET_WECOM_ROBOT = 'wecom_robot';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const RESULT_THROTTLED = 'throttled';

    /**
     * 记录一次出站调用。
     *
     * @param string $target 出站目标标识符
     * @param string $result 调用结果（success, failure, throttled）
     * @param float $seconds 本次调用耗时（秒）
     */
    public static function record(string $target, string $result, float $seconds): void
    {
        Metrics::incr('core_outbound_requests_total', ['target' => $target, 'result' => $result]);
        Metrics::observe('core_outbound_request_duration_seconds', ['target' => $target], $seconds);
    }

    /**
     * 按 HTTP 状态码归类。
     */
    public static function resultFromStatus(int $statusCode): string
    {
        return match (true) {
            $statusCode === 429 => self::RESULT_THROTTLED,
            $statusCode >= 400 => self::RESULT_FAILURE,
            default => self::RESULT_SUCCESS,
        };
    }

    /**
     * 把纳秒时间戳换算成秒。
     */
    public static function secondsSince(int $startedAtNanos): float
    {
        return round((hrtime(true) - $startedAtNanos) / 1e9, 4);
    }
}
