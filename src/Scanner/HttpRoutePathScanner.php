<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Scanner;

use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Handler;
use Throwable;

/**
 * 扫描已注册的 HTTP 路由模板，作为 `@http_routes` 标签的值域。
 *
 * 为什么必须扫描而不是运行期按需建行：共享表是定长的，两条序列抢同一个标签组合时
 * 会各自拿到不同的行键，同一个路由被拆成两条样本 —— Prometheus 会因重复序列直接报错。
 * 所以值域在 fork 之前一次算清，运行期只读。
 *
 * 取的是路由模板（`/api/user/get_list`）而不是原始 URL：
 * 前者基数固定在一个有限集合内，后者带着 id、查询串进标签就是基数炸弹。
 */
final class HttpRoutePathScanner
{
    /** 未命中任何路由的请求归到这个桶，必须出现在值域里，否则 404 会被整体丢掉。 */
    public const NOT_FOUND = 'not_found';

    public function __construct(private readonly DispatcherFactory $dispatcherFactory)
    {
    }

    /**
     * @return list<string>
     */
    public function scan(string $serverName = 'http'): array
    {
        try {
            $data = $this->dispatcherFactory->getRouter($serverName)->getData();
        } catch (Throwable) {
            // 拿不到路由表时退化成「只有 other」：指标粗一点，但端点仍然可用。
            return [];
        }

        $paths = [];

        // staticRouteMap：method => [path => Handler]
        $staticRoutes = $data[0] ?? [];
        if (is_array($staticRoutes)) {
            foreach ($staticRoutes as $routes) {
                if (! is_array($routes)) {
                    continue;
                }

                foreach (array_keys($routes) as $path) {
                    if (is_string($path) && $path !== '') {
                        $paths[$path] = true;
                    }
                }
            }
        }

        $variableRoutes = $data[1] ?? [];
        if (is_array($variableRoutes)) {
            foreach ($variableRoutes as $routes) {
                if (! is_array($routes)) {
                    continue;
                }

                foreach ($routes as $route) {
                    $handler = is_array($route) ? ($route[1] ?? null) : null;
                    if ($handler instanceof Handler && $handler->route !== '') {
                        $paths[$handler->route] = true;
                    }
                }
            }
        }

        $values = array_keys($paths);
        $values[] = self::NOT_FOUND;

        return $values;
    }
}
