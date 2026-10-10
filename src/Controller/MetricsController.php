<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Controller;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\Controller\AbstractController;
use Hyperf\Apex\Metrics\Access\MetricsAccessGuard;
use Hyperf\Apex\Metrics\DTO\MetricsOverviewDTO;
use Hyperf\Apex\Metrics\Service\MetricsOverviewService;
use Hyperf\Apex\Metrics\Service\MetricsSnapshotService;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 指标端点：抓取、运维大屏页面、大屏数据。
 *
 * 三个端点都不做身份鉴权 —— Prometheus 抓取时带不了业务凭证，浏览器打开大屏也只是一次 GET，
 * 在响应头里塞 token 换不来任何安全。限制来源只能靠网络位置：`metrics.allow_ips` 白名单是
 * 唯一的门禁，默认只允许本机；配成空数组表示不校验，那是留给「已经由网关/内网限住来源」的部署。
 *
 * 抓取路径就是 `/metrics`（Prometheus 惯例，也是配置注释里写明的地址），所以 mapping 用空 path ——
 * 空 path 在 Hyperf 里表示「就是 prefix 本身」，不会退化成按方法名推导出的 `/metrics/scrape`。
 */
#[Controller(prefix: '/metrics')]
final class MetricsController extends AbstractController
{
    private const PROMETHEUS_CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    private const DASHBOARD_CONTENT_TYPE = 'text/html; charset=utf-8';

    public function __construct(
        private readonly MetricsAccessGuard $guard,
        private readonly MetricsSnapshotService $snapshot,
        private readonly MetricsOverviewService $overview,
        private readonly HttpResponseInterface $response,
    ) {
    }

    #[GetMapping(path: '')]
    #[PublicAccess]
    public function scrape(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->snapshot->enabled()) {
            throw new BusinessException(ErrorCode::RESOURCE_NOT_FOUND, '指标未启用（metrics.enabled=false）。');
        }

        $this->assertAllowed($request);

        return $this->response
            ->raw($this->snapshot->render())
            ->withHeader('content-type', self::PROMETHEUS_CONTENT_TYPE);
    }

    #[GetMapping(path: 'dashboard')]
    #[PublicAccess]
    public function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertOverviewEnabled();
        $this->assertAllowed($request);

        return $this->response
            ->raw($this->dashboardHtml())
            ->withHeader('content-type', self::DASHBOARD_CONTENT_TYPE);
    }

    #[GetMapping(path: 'overview')]
    #[PublicAccess]
    public function overview(ServerRequestInterface $request): MetricsOverviewDTO
    {
        $this->assertOverviewEnabled();
        $this->assertAllowed($request);

        return $this->overview->overview();
    }

    private function assertOverviewEnabled(): void
    {
        if ($this->overview->enabled()) {
            return;
        }

        throw new BusinessException(ErrorCode::RESOURCE_NOT_FOUND, '运维大屏未启用（metrics.overview.enabled=false）。');
    }

    private function assertAllowed(ServerRequestInterface $request): void
    {
        if ($this->guard->allows($request)) {
            return;
        }

        // 不回显来源 IP：响应本身是给请求方看的，把「谁在被拒绝」写进去等于多送一条信息。
        throw new BusinessException(ErrorCode::PERMISSION_DENIED, '指标端点不允许该来源访问。');
    }

    /**
     * 大屏页面是随包发布的静态文件。
     *
     * 读不到就报错而不是回一份空页面：空页面看起来像「指标全挂了」，
     * 而真正的原因是组件包不完整，两者的排查方向完全不同。
     */
    private function dashboardHtml(): string
    {
        $path = __DIR__ . '/../../resource/dashboard.html';
        $html = is_readable($path) ? file_get_contents($path) : false;

        if ($html === false) {
            throw new BusinessException(ErrorCode::INTERNAL_ERROR, '运维大屏页面缺失，请确认组件包完整。');
        }

        return $html;
    }
}
