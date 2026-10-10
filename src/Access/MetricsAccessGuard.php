<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Access;

use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\Support\StringList;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 指标端点（抓取、大屏）的来源判定。
 *
 * 这三个端点不做身份鉴权 —— Prometheus 抓取时带不了业务凭证，浏览器打开大屏也只是一次
 * GET。限制来源只能靠网络位置，所以白名单是唯一的门禁：`metrics.allow_ips` 支持单个 IP 与
 * CIDR，默认只允许本机。
 *
 * 白名单配成空数组表示不校验（与配置注释一致）。那是有意留的口子：同机部署的抓取
 * 之外还有「已经由网关/内网限住来源」的场景，此时在应用里再配一遍是重复的。
 */
final class MetricsAccessGuard
{
    /** @var null|list<string> 缓存：白名单由纯配置派生，运行期不变 */
    private ?array $allowed = null;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ClientIpResolver $clientIp,
        private readonly IpRuleMatcher $rules,
    ) {
    }

    public function allows(?ServerRequestInterface $request): bool
    {
        $allowed = $this->allowed ??= StringList::of($this->config->get('metrics.allow_ips', []));
        if ($allowed === []) {
            return true;
        }

        $ip = $this->clientIp->resolve($request);
        if ($ip === null) {
            return false;
        }

        foreach ($allowed as $rule) {
            if ($this->rules->matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }
}
