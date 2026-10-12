<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics;

use Hyperf\Apex\Metrics\Listener\MetricTableInitListener;
use Hyperf\Apex\Metrics\Middleware\RequestTimingMiddleware;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            'commands' => [],
            'listeners' => [
                MetricTableInitListener::class,
            ],
            /*
             * 全局中间件：请求计时与埋点。注册在包里才能「装完即用」——
             * 中间件本身带重复注册保护，应用已经自己挂过一份也不会重复计数。
             *
             * 位置在全局列表最前（组件配置先合并），也就是最外层：计时覆盖整个请求，
             * 且能拿到最终的响应状态码。
             */
            'middlewares' => [
                'http' => [
                    RequestTimingMiddleware::class,
                ],
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'apex 指标套件的配置模板。',
                    'source' => __DIR__ . '/../publish/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
