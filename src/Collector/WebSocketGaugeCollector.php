<?php

declare(strict_types=1);

namespace Hyperf\Apex\Metrics\Collector;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;

/**
 * WebSocket 当前连接数。
 *
 * 连接索引本来就在共享内存里（跨 worker 一致），直接读行数即可 —— 这是全套指标里
 * 唯一「零埋点、零额外成本」的一项。
 */
final class WebSocketGaugeCollector implements GaugeCollectorInterface
{
    public function __construct(private readonly WsConnectionStoreInterface $store)
    {
    }

    public function collect(): array
    {
        return [
            new GaugeSample('core_ws_connections', '当前 WebSocket 连接数', [], $this->store->countConnections()),
            new GaugeSample('core_ws_online_users', '当前 WebSocket 在线用户数', [], $this->store->countUsers()),
            new GaugeSample('core_ws_rooms', '当前 WebSocket 房间数', [], $this->store->countRooms()),
        ];
    }
}
