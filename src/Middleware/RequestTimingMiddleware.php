<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Middleware;

use Hyperf\Apex\Exception\ExceptionStatus;
use Hyperf\Apex\Exception\ExpectedException;
use Hyperf\Apex\Http\Middleware\AbstractMiddleware;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Metrics\Metrics;
use Hyperf\Apex\Metrics\Scanner\HttpRoutePathScanner;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class RequestTimingMiddleware extends AbstractMiddleware
{
    /**
     * 请求属性：本次请求已经记过一次。
     *
     * 组件包会把它注册为全局中间件，而应用侧可能早就自己注册过一份 —— 两条都在管线里时
     * 会各记一次，指标翻倍、access 日志也翻倍，且现象只在数字上看得出来。用一个请求属性
     * 把「这条请求记过没有」串下去，重复注册就退化成多一次透传。
     */
    private const RECORDED_ATTRIBUTE = 'apex.metrics.timing_recorded';

    private readonly LoggerInterface $logger;

    /**
     * 不记 access 日志的 path，见 config/autoload/access_log.php。
     *
     * @var list<string>
     */
    private readonly array $excludePatterns;

    public function __construct(LoggerFactory $loggerFactory, ConfigInterface $config)
    {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'http', 'access');
        $this->excludePatterns = self::parsePatterns($config->get('access_log.exclude', []));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(self::RECORDED_ATTRIBUTE) === true) {
            return $handler->handle($request);
        }

        // 属性要在往下传之前设好：内层那一份要靠它认出「已经记过了」。
        $request = $request->withAttribute(self::RECORDED_ATTRIBUTE, true);

        $startedAt = hrtime(true);
        $response = null;
        $exception = null;

        try {
            $response = $handler->handle($request);

            return $response;
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            /*
             * 异常路径拿不到最终响应（异常处理器在中间件外层），只能按异常反推状态码。
             * 不复用 500 兜底：那会把「未登录」这类业务拒绝记成 5xx，把错误率顶高，
             * 也会让日志里的 status_code 与真实响应对不上。日志与指标共用这一个值。
             */
            $statusCode = $response?->getStatusCode()
                ?? ($exception !== null ? ExceptionStatus::resolve($exception) : 500);

            $this->recordMetrics($request, $exception, $statusCode, $startedAt);

            if ($this->shouldLog($request, $response, $exception)) {
                $context = [
                    'status_code' => $statusCode,
                    'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                ];
                if ($exception !== null) {
                    $context['exception'] = $exception::class;
                }

                $this->logger->info('HTTP request completed.', $context);
            }
        }
    }

    /**
     * 记录 HTTP 指标。
     *
     * 与 access 日志共用这次计时和状态码，但排除口径不同：日志的排除名单治的是「噪音」，
     * 而名单里最高频的心跳接口恰恰是最需要观测的 —— 指标不跟随 access_log.exclude。
     *
     * 状态码由调用方算好传进来（异常路径要按异常反推），这里只负责归档：
     * 两处各算一次的话，迟早会出现「日志写 500、指标记 4xx」这种自相矛盾的现场。
     * 耗时用 hrtime 的纳秒差换算成秒；指标本身绝不影响响应（门面内部吞掉全部异常）。
     */
    private function recordMetrics(
        ServerRequestInterface $request,
        ?Throwable $exception,
        int $statusCode,
        int $startedAt
    ): void {
        $route = self::route($request);
        $labels = [
            'method' => self::method($request->getMethod()),
            'route' => $route,
        ];

        Metrics::incr('core_http_requests_total', $labels + [
            'status_class' => self::statusClass($statusCode),
        ]);
        Metrics::observe('core_http_request_duration_seconds', $labels, (hrtime(true) - $startedAt) / 1_000_000_000);

        /*
         * 只统计「预期外」的异常：业务异常、参数校验失败、404 都是设计好的失败路径，
         * 由 AppExceptionHandler 转成业务响应，可观测性由 status_class=4xx 与 access 日志承担。
         * 把它们算成未捕获异常，会让这个数字永远不归零。
         */
        if ($exception !== null && ! ExpectedException::matches($exception)) {
            Metrics::incr('core_http_unhandled_exceptions_total', ['route' => $route]);
        }
    }

    /**
     * 路由模板是低基数标签的来源：原始 URL 带 id 与查询串，进标签就是基数炸弹。
     *
     * 路由在全局中间件阶段已经解析完（CoreMiddleware 先 dispatch 再进管线），
     * 因此这里拿到的是模板；未命中任何路由的请求归到 not_found 桶。
     */
    private static function route(ServerRequestInterface $request): string
    {
        $dispatched = $request->getAttribute(Dispatched::class);
        if (! $dispatched instanceof Dispatched) {
            return HttpRoutePathScanner::NOT_FOUND;
        }

        $handler = $dispatched->handler;
        if (! $handler instanceof Handler || $handler->route === '') {
            return HttpRoutePathScanner::NOT_FOUND;
        }

        return $handler->route;
    }

    private static function method(string $method): string
    {
        $method = strtoupper($method);

        return in_array($method, ['GET', 'POST'], true) ? $method : 'OTHER';
    }

    private static function statusClass(int $statusCode): string
    {
        $class = intdiv($statusCode, 100) . 'xx';

        return in_array($class, ['1xx', '2xx', '3xx', '4xx', '5xx'], true) ? $class : '5xx';
    }

    /**
     * 排除名单只筛正常完成的请求。5xx 与向下游抛出的异常必须留痕：
     * 否则被高频接口（如轮询、上报类接口）报错这件事会被静默，排障时无从下手。
     */
    private function shouldLog(
        ServerRequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception
    ): bool {
        if ($exception !== null) {
            return true;
        }

        $statusCode = $response?->getStatusCode();
        if ($statusCode !== null && $statusCode >= 500) {
            return true;
        }

        $path = $request->getUri()->getPath();
        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function parsePatterns(mixed $patterns): array
    {
        if (! is_array($patterns)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $pattern): string => is_string($pattern) ? trim($pattern) : '', $patterns),
            static fn (string $pattern): bool => $pattern !== ''
        ));
    }
}
