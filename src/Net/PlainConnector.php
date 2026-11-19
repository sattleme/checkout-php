<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Net;

/**
 * Opens connections without TLS, for one use alone: a merchant's Bitcoin node on a local network
 * (SPEC.md §31), whose URL may be `http://` (and its WebSocket `ws://`) for a local host only:
 * an IPv4 address of 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 or 100.64.0.0/10, or a name under
 * `.local`. Every other host the SDK asks is asked over TLS (Connector).
 *
 * A Connector that does not implement it leaves such a node unreachable: its `fallback` then
 * applies (mempool.space, or no on-chain payment judged with `"none"`). TlsConnector implements
 * it. It is called in the SDK's event loop, as Connector::connect().
 */
interface PlainConnector
{
    /**
     * A TCP connection to $host:$port, without TLS.
     *
     * @return resource a stream socket (the SDK sets it non-blocking)
     *
     * @throws \RuntimeException when it cannot be made within $timeoutMs (its message holds no
     *                           secret: it may be logged)
     */
    public function connectPlain(string $host, int $port, int $timeoutMs): mixed;
}
