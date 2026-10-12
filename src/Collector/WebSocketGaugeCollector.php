<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Collector;

use Hyperf\Apex\Support\Time;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use Hyperf\Contract\ConfigInterface;
use Throwable;

/**
 * WebSocket 连接侧指标。
 *
 * 连接索引本来就在共享内存里（跨 worker 一致），直接读即可 —— 这是全套指标里唯一
 * 「零埋点、零额外成本」的一项，所以推送失败率之类需要在热路径累加的量不放在这里。
 *
 * 容量与空闲数对应两个「不报警就没人发现」的故障：
 * - 连接数逼近容量：索引表是定长结构，写满之后 set() 只返回 false，新连接会被直接拒绝；
 * - 空闲数长期不降：说明空闲回收没在工作（websocket.idle_timeout 与 Swoole 心跳都没配）。
 *
 * hyperf-apex/websocket 是可选依赖（见包内 composer.json 的 suggest）：没装时本采集器不会被
 * 实例化（注册处按 class_exists 过滤），装了但没启用网关（websocket.enabled=false）时连接索引
 * 表不存在，这里整组不输出 —— 输出 0 会看起来像「一条连接都没有」，与「根本没采集到」是两件事。
 */
final class WebSocketGaugeCollector implements GaugeCollectorInterface
{
    public function __construct(
        private readonly WsConnectionStoreInterface $store,
        // 房间索引可能不在共享内存里（websocket.room_store_driver=redis），因此单独取一份契约。
        private readonly WsRoomStoreInterface $roomStore,
        private readonly ConfigInterface $config,
    ) {
    }

    public function collect(): array
    {
        try {
            return [
                new GaugeSample('core_ws_connections', '当前 WebSocket 连接数', [], $this->store->countConnections()),
                new GaugeSample(
                    'core_ws_connection_capacity',
                    '连接索引表的行数上限（连接数接近它时新连接会被拒绝）',
                    [],
                    max(0, (int) $this->config->get('websocket.table_size', 0)),
                ),
                new GaugeSample('core_ws_idle_connections', '空闲连接数（超过 websocket.idle_timeout 未收到消息、等待回收）', [], $this->idleConnections()),
                new GaugeSample('core_ws_online_users', '当前 WebSocket 在线用户数', [], $this->store->countUsers()),
                new GaugeSample('core_ws_rooms', '当前 WebSocket 房间数', [], $this->roomStore->countRooms()),
            ];
        } catch (Throwable) {
            // 索引表不存在（未启用网关、未启动 server）或读失败：整组不输出。
            return [];
        }
    }

    /**
     * 等待回收的连接数。
     *
     * 未启用空闲回收时恒为 0：没有回收机制也就没有「等待回收」这个状态，
     * 这种配置组合会被 WsRuntimeCheckListener 在启动期拦下（除非另有 Swoole 心跳）。
     */
    private function idleConnections(): int
    {
        $idleTimeout = (int) $this->config->get('websocket.idle_timeout', 0);
        if ($idleTimeout <= 0) {
            return 0;
        }

        // 与连接索引同一口径：活跃时间早于「现在 - 回收时限」的连接即为空闲。
        return count($this->store->idleConnections(Time::now()->getTimestamp() - $idleTimeout));
    }
}
