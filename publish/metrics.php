<?php

declare(strict_types=1);

use Hyperf\Apex\Metrics\Collector\WebSocketGaugeCollector;
use Hyperf\Apex\Metrics\Pool\PoolGaugeCollector;

use function Hyperf\Support\env;

/*
 * 低基数运行指标。
 *
 * ## 为什么是定长表而不是 Prometheus 客户端库
 *
 * 单机多进程（worker / task worker / 自定义进程）下，计数必须在进程间聚合，
 * 而热路径又不允许写 Redis。这里复用 WebSocket 连接索引同一条路：fork 之前在
 * 共享内存里建一张 Swoole\Table，埋点只做原子自增，抓取时遍历渲染。
 *
 * ## 行的产生方式
 *
 * `definitions` 里每个指标的所有 (标签组合) 会在启动期展开成行，运行期只做
 * 「查表 → 自增」。Swoole\Table 是定长结构，**没预建的行写不进去且静默失败**，
 * 所以标签值域必须是闭集（`@http_routes` 这类占位符由启动期的扫描结果填充），
 * 未声明的组合会被丢弃并计入 core_metrics_series_dropped_total。
 */
return [
    // 总开关。关闭即不建表、埋点全部 no-op、/metrics 端点返回 404。
    'enabled' => (bool) env('METRICS_ENABLED', true),

    /*
     * 共享内存表容量（行）。
     *
     * 必须 ≥ 定义展开后的行数 × 2：Swoole\Table 的实际可用行数受内部装载因子影响
     * （实测申请 16384 行时约 9200 行就分配失败），余量不足会在启动期直接抛错。
     *
     * 行数由「路由数 × 方法数 × 状态码段数」主导，抓取只遍历 set 过的行。
     */
    'table_size' => (int) env('METRICS_TABLE_SIZE', 65536),

    /*
     * 采样类指标（队列深度、连接池状态等）的进程内缓存秒数。
     * 每次抓到 /metrics 都回源查库会让 Prometheus 的抓取变成一份持续负载，
     * 因此采样结果按此 TTL 复用。
     */
    'gauge_cache_ttl_seconds' => (int) env('METRICS_GAUGE_CACHE_TTL_SECONDS', 15),

    /*
     * 连接池快照表的行数上限：每行对应一个上报过快照的进程（worker / task worker / 自定义进程）。
     * 行按 pid 存，进程重启后由 TTL 自然失效，因此容量不随重启增长。
     */
    'process_table_size' => (int) env('METRICS_PROCESS_TABLE_SIZE', 64),

    /*
     * 快照的有效期（秒）。超过这个时间没有更新的行会被渲染时忽略 ——
     * 进程被杀后它的最后一份快照不该继续被算进「当前占用」。
     */
    'pool_snapshot_ttl_seconds' => (int) env('METRICS_POOL_SNAPSHOT_TTL_SECONDS', 30),

    /* 直方图分桶（秒）。固定桶是低基数的一部分，不接受按请求动态生成。 */
    'histogram_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0],

    /*
     * 抓取来源白名单，支持单个 IP 与 CIDR。留空表示不校验。
     *
     * `/metrics`（抓取）、`/metrics/dashboard`（大屏页面）、`/metrics/overview`（大屏数据）
     * 三个端点共用这份名单：它们都不做身份鉴权 —— Prometheus 抓取时带不了业务凭证，
     * 浏览器打开大屏也只是一次 GET，来源限制只能靠网络位置。
     *
     * 指标会暴露接口清单与内部结构，所以默认值取安全侧（只允许本机）而不是空名单：
     * Prometheus 与本服务同机部署时不必改；部署在别的机器或别的网段时，
     * 用 METRICS_ALLOW_IPS=10.1.0.0/16 这类显式放通，不要图省事写 `*`。
     */
    'allow_ips' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('METRICS_ALLOW_IPS', '127.0.0.1,::1'))),
        static fn (string $rule): bool => $rule !== ''
    )),

    /*
     * 采样采集器。每个采集器返回一组 gauge，结果按 gauge_cache_ttl_seconds 复用；
     * 单个采集器抛错只丢它自己的样本，不影响其余指标与整个端点。
     *
     * WebSocketGaugeCollector 依赖可选的 hyperf-apex/websocket：未安装时它连类都不存在，
     * 注册处按 class_exists 过滤后自动跳过，不必手工删这一行。
     */
    'collectors' => [
        WebSocketGaugeCollector::class,
        PoolGaugeCollector::class,
    ],

    /*
     * 运维大屏：GET /metrics/dashboard 返回页面，GET /metrics/overview 返回它的数据。
     *
     * aggregates 决定「哪些指标参与速率差分、按哪些标签聚合」。大屏要的是全局速率而不是
     * 逐路由明细，所以这里把 route 之类的维度求和掉：差分基准表只存几十个数，而不是上万行。
     * 保留标签写成空数组表示「全部求和成一个数」。
     *
     * core_http_requests_total 的 status_class 必须保留：错误率按它拆出 4xx / 5xx，
     * 去掉这个标签后键会退化成空串，错误率就无从计算（那种情况下大屏把它算成 0，
     * 而不是猜一个数）。
     */
    'overview' => [
        'enabled' => (bool) env('METRICS_OVERVIEW_ENABLED', true),
        'refresh_seconds' => (int) env('METRICS_OVERVIEW_REFRESH_SECONDS', 10),
        'aggregates' => [
            'core_http_requests_total' => ['status_class'],
            'core_http_request_duration_seconds' => [],
            'core_http_unhandled_exceptions_total' => [],
            'core_outbound_requests_total' => ['target', 'result'],
            'core_outbound_request_duration_seconds' => ['target'],
            'core_pool_timeout_total' => ['pool'],
            'core_metrics_series_dropped_total' => [],
        ],
        /*
         * 总判定与染色阈值。放在后端而不是前端：阈值调整只改一处，页面不用重新部署。
         * 数值为「超过即升级」，按 warning → critical 逐档比较。
         */
        'thresholds' => [
            'error_rate' => ['warning' => 0.01, 'critical' => 0.05],
            'p95_seconds' => ['warning' => 0.5, 'critical' => 2.0],
            'alert_backlog_seconds' => ['warning' => 300, 'critical' => 900],
            'queue_expired' => ['warning' => 1, 'critical' => 10],
            'pool_timeout' => ['warning' => 1, 'critical' => 5],
        ],
    ],

    /*
     * 指标定义。
     *
     * - type：counter / histogram / gauge；
     * - labels 的每个键对应一个取值域，数组写闭集，`@xxx` 写启动期注入的动态值域；
     * - histogram 不需要写桶，统一取 histogram_buckets，并自动生成 _sum / _count。
     */
    'definitions' => [
        'core_http_requests_total' => [
            'type' => 'counter',
            'help' => 'HTTP 请求总数，按方法、路由模板与状态码段统计',
            'labels' => [
                'method' => ['GET', 'POST', 'OTHER'],
                'route' => '@http_routes',
                'status_class' => ['1xx', '2xx', '3xx', '4xx', '5xx'],
            ],
        ],
        'core_http_request_duration_seconds' => [
            'type' => 'histogram',
            'help' => 'HTTP 请求处理耗时（秒）',
            'labels' => [
                'method' => ['GET', 'POST', 'OTHER'],
                'route' => '@http_routes',
            ],
        ],
        /*
         * 未捕获异常按分组统计，而不是按异常类名：类名是开放集合，一旦把新异常类带进标签，
         * 就会因为不在闭集内被丢弃并计入 dropped —— 分组是稳定的三个桶。
         */
        /*
         * 只统计「预期外」的异常。BusinessException / ValidationException /
         * ModelNotFoundException / 4xx 的 HttpException 由 AppExceptionHandler 直接转成业务响应，
         * 属于设计好的失败路径，可观测性由 status_class=4xx 与 access 日志承担 ——
         * 把它们算进来会让这个数字永远不归零，真出故障时反而看不出差别。
         * 判定口径见 Hyperf\Apex\Exception\ExpectedException。
         */
        'core_http_unhandled_exceptions_total' => [
            'type' => 'counter',
            'help' => 'HTTP 请求过程中抛出的预期外异常数（不含业务异常等设计好的失败路径）',
            'labels' => [
                'route' => '@http_routes',
            ],
        ],
        'core_outbound_requests_total' => [
            'type' => 'counter',
            'help' => '第三方出站请求数，按目标与结果拆分',
            'labels' => [
                'target' => ['http', 'wecom_robot', 'custom'],
                'result' => ['success', 'failure', 'throttled'],
            ],
        ],
        'core_outbound_request_duration_seconds' => [
            'type' => 'histogram',
            'help' => '第三方出站请求耗时（秒）',
            'labels' => [
                'target' => ['http', 'wecom_robot', 'custom'],
            ],
        ],
        /*
         * 连接池的等待情况。pool 标签的值域由启动期从 databases / redis 配置的键派生
         * （见 PoolNames），新增连接不需要手工登记。
         */
        'core_pool_wait_seconds' => [
            'type' => 'histogram',
            'help' => '从连接池获取连接的等待耗时（秒）',
            'labels' => [
                'pool' => '@pool_names',
            ],
        ],
        'core_pool_timeout_total' => [
            'type' => 'counter',
            'help' => '等待连接超时（池耗尽）的次数',
            'labels' => [
                'pool' => '@pool_names',
            ],
        ],
        'core_metrics_series_dropped_total' => [
            'type' => 'counter',
            'help' => '未在 metrics.definitions 中声明、因而被丢弃的指标或标签组合次数',
            'labels' => [
                'metric' => '@metric_names',
            ],
        ],
    ],
];
