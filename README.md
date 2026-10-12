# hyperf-apex/metrics

基于 Swoole Table 共享内存的低基数运行指标：Prometheus 抓取端点、请求耗时拦截、以及一个运维大屏。

- **依赖**：`hyperf-apex/core`、`hyperf/memory`（Swoole Table）
- **可选集成**：`hyperf-apex/websocket`（装了才输出连接数/容量类指标，见 `composer.json` 的 `suggest`）
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)

## 安装

```bash
composer require hyperf-apex/metrics
php bin/hyperf.php vendor:publish hyperf-apex/metrics --id=config
```

## 端点

| 端点 | 用途 |
| :--- | :--- |
| `GET /metrics` | Prometheus 抓取（文本格式） |
| `GET /metrics/dashboard` | 运维大屏页面 |
| `GET /metrics/overview` | 大屏数据（大屏页面自己取它） |

访问控制由 `metrics.allow_ips` 决定，**默认只允许本机**（`127.0.0.1,::1`）—— 抓取方在容器/另一台机器上时
需要显式放行，否则拿到的是 403。

## 配置项

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `metrics.enabled` | `METRICS_ENABLED`（`true`） | 关掉后不注册端点与拦截器 |
| `metrics.table_size` | `METRICS_TABLE_SIZE`（65536） | 指标表行数 |
| `metrics.gauge_cache_ttl_seconds` | `METRICS_GAUGE_CACHE_TTL_SECONDS`（15） | 采集器结果缓存时长（用单调时钟计时） |
| `metrics.process_table_size` | `METRICS_PROCESS_TABLE_SIZE`（64） | 进程维度的行数 |
| `metrics.pool_snapshot_ttl_seconds` | `METRICS_POOL_SNAPSHOT_TTL_SECONDS`（30） | 连接池快照缓存时长 |
| `metrics.histogram_buckets` | — | 直方图分桶 |
| `metrics.allow_ips` | `METRICS_ALLOW_IPS`（`127.0.0.1,::1`） | 允许访问端点的来源，支持 CIDR |
| `metrics.collectors` | — | 采集器清单 |
| `metrics.overview.enabled` | `METRICS_OVERVIEW_ENABLED`（`true`） | 是否启用大屏 |
| `metrics.overview.refresh_seconds` | `METRICS_OVERVIEW_REFRESH_SECONDS`（10） | 大屏刷新间隔 |
| `metrics.overview.aggregates` | — | 大屏聚合项 |
| `metrics.overview.thresholds` | — | 大屏告警阈值（错误率、P95、积压、连接池超时等） |
| `metrics.definitions` | — | 自定义指标定义（`type` / `help` / `labels`） |

## 内置指标

| 指标 | 类型 | 标签 |
| :--- | :--- | :--- |
| `core_http_requests_total` | counter | `method`、`route`、`status_class` |
| `core_http_request_duration_seconds` | histogram | `method`、`route` |
| `core_http_unhandled_exceptions_total` | counter | `route` |
| `core_outbound_requests_total` | counter | `target`、`result` |
| `core_outbound_request_duration_seconds` | histogram | `target` |
| `core_pool_wait_seconds` | histogram | `pool` |
| `core_pool_timeout_total` | counter | `pool` |
| `core_metrics_series_dropped_total` | counter | `metric` |

装了 `hyperf-apex/websocket` 时另有一组连接类指标（连接数、容量、空闲连接数）——
连接索引不可用（未启用网关）时整组不输出，而不是给出一串 `0`。

## 说明

- 指标基数由应用控制：路由要归一（`core_http_requests_total` 的 `route` 标签），
  未知路由不会把基数撑爆（超出上限的序列计入 `core_metrics_series_dropped_total`）。
- 大屏的采集走缓存（`gauge_cache_ttl_seconds`），抓取频率高也不会把 Swoole Table 打满。
- 指标自身的埋点**不跟随** `access_log.exclude` —— 高频接口正是最需要观测的。
