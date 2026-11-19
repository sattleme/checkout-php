<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Net;

/**
 * Opens the SDK's connections: to the relays (WebSocket) and to the Lightning address, the rate
 * sources, the explorer (mempool.space or the merchant's Bitcoin node: REST and WebSocket) and
 * BlockCypher (HTTP). The default, TlsConnector, connects directly with TLS verified; another one
 * may go through a proxy (an HTTP CONNECT tunnel), or reach test servers. A merchant's Bitcoin
 * node on a local network may be asked without TLS: through a PlainConnector alone.
 *
 * It is called in the SDK's event loop, where the other connections of the call wait while it
 * runs: return as soon as the connection is ready, and never past the timeout.
 */
interface Connector
{
    /**
     * A connection to $host:$port with TLS established, its certificate verified for $host.
     *
     * @return resource a stream socket (the SDK sets it non-blocking)
     *
     * @throws \RuntimeException when it cannot be made within $timeoutMs (its message holds no
     *                           secret: it may be logged)
     */
    public function connect(string $host, int $port, int $timeoutMs): mixed;
}
