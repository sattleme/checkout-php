<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Net;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\TimeoutException;
use Sattleme\Checkout\Internal\Onchain\NodeUrl;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\RelayList;
use Sattleme\Checkout\Internal\Text;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\Net\PlainConnector;

/**
 * A WebSocket client (RFC 6455) for the relays and the explorer's socket: its handshake bounded
 * by a deadline, a message capped at 1 MiB (SPEC.md §13), no extension. Text messages only: a
 * binary one is skipped.
 *
 * @internal
 */
final class WebSocket
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** A pong a reader without deadline writes, at most (a peer that stops reading holds no write). */
    private const PONG_SECONDS = 5.0;
    private const MAX_HEAD = 16384;

    /** What came and was not read yet: from $position on. */
    private string $buffer = '';

    private int $position = 0;

    private bool $open = true;

    /** One frame written at a time: a pong never lands inside a message being written. */
    private readonly Locks $writes;

    private function __construct(private readonly Socket $socket)
    {
        $this->writes = new Locks();
    }

    /**
     * Connects to the `wss://` $url (a relay's, mempool.space's, or a merchant's Bitcoin node's,
     * `ws://` too for one on a local network through a PlainConnector: endpoint()), the handshake
     * done by $deadline (Loop::now()).
     *
     * @throws TransportException
     * @throws CancelledException when the call is cancelled before or while the name is looked up
     */
    public static function connect(Connector $connector, string $url, float $deadline): self
    {
        $endpoint = self::endpoint($url);
        $remaining = (int) ceil(($deadline - Loop::now()) * 1000);
        if ($remaining <= 0) {
            throw new TransportException('Timed out');
        }
        // The connector looks the name up, which blocks the process (Loop): none starts once the
        // call is cancelled, and a cancellation that came meanwhile wins over what it gave.
        $loop = Loop::get();
        $loop->throwIfCancelled();
        try {
            if ($endpoint['tls']) {
                $stream = $connector->connect($endpoint['host'], $endpoint['port'], $remaining);
            } elseif ($connector instanceof PlainConnector) {
                $stream = $connector->connectPlain($endpoint['host'], $endpoint['port'], $remaining);
            } else {
                throw new TransportException('This connector makes no connection without TLS');
            }
        } catch (CancelledException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $loop->throwIfCancelled();
            throw new TransportException($e->getMessage(), 0, $e);
        }
        $socket = new Socket($stream);
        try {
            $loop->throwIfCancelled();
            if (Loop::now() >= $deadline) {
                throw new TransportException('Timed out'); // While the name was looked up.
            }
            $key = Base64::encode(random_bytes(16));
            $socket->write(implode("\r\n", [
                'GET ' . $endpoint['path'] . ' HTTP/1.1',
                'Host: ' . $endpoint['host'] . ($endpoint['port'] === ($endpoint['tls'] ? 443 : 80) ? '' : ':' . $endpoint['port']),
                'Upgrade: websocket',
                'Connection: Upgrade',
                'Sec-WebSocket-Key: ' . $key,
                'Sec-WebSocket-Version: 13',
                // As an HTTP request's (SPEC.md §15): relays behind Cloudflare's bot protection
                // (nostr.wine) refuse a handshake without one.
                'User-Agent: ' . Protocol::USER_AGENT,
            ]) . "\r\n\r\n", $deadline);
            $webSocket = new self($socket);
            $webSocket->handshake($key, $deadline);

            return $webSocket;
        } catch (\Throwable $e) {
            $socket->close();
            throw $e instanceof TransportException || $e instanceof CancelledException ? $e : new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Where $url is: a relay address (SPEC.md §9), or the WebSocket of a merchant's Bitcoin node
     * (SPEC.md §31: its node URL with `ws` for its leading `http`, then `/api/v1/ws`), whose host
     * and port follow the node URL's rule.
     *
     * @return array{host: string, port: int, path: string, tls: bool}
     *
     * @throws TransportException for any other URL
     */
    private static function endpoint(string $url): array
    {
        if (RelayList::isRelayUrl($url)) {
            return RelayList::endpoint($url) + ['tls' => true];
        }
        // ws:// for http://, wss:// for https://: the node URL's own rule, http to a local host.
        $node = str_starts_with($url, 'ws://') || str_starts_with($url, 'wss://') ? NodeUrl::request('http' . substr($url, \strlen('ws'))) : null;
        if ($node === null) {
            throw new TransportException('Not a WebSocket URL the SDK asks');
        }

        return ['host' => $node['host'], 'port' => $node['port'], 'path' => $node['target'], 'tls' => $node['tls']];
    }

    /**
     * Sends a text message.
     *
     * @throws TransportException
     */
    public function send(string $text, ?float $deadline): void
    {
        if (!$this->open) {
            throw new TransportException('WebSocket closed');
        }
        $this->write(self::frame(0x1, $text), $deadline);
    }

    /**
     * The next text message, or null once the connection closed.
     *
     * @throws TransportException when it fails, breaks the protocol, a message passes 1 MiB, or the
     *                            deadline passes
     */
    public function receive(?float $deadline): ?string
    {
        $message = null;
        while ($this->open) {
            // Frames that keep coming (pings, pongs) hold neither the loop nor the deadline.
            Loop::get()->checkpoint();
            if ($deadline !== null && Loop::now() >= $deadline) {
                throw new TransportException('Timed out');
            }
            $frame = $this->frameFromBuffer();
            if ($frame === null) {
                $chunk = $this->socket->read($deadline);
                if ($chunk === null) {
                    $this->open = false;
                    $this->socket->close();

                    return null;
                }
                // What was read goes (once a frame was read: else the buffer only grows).
                if ($this->position > 0) {
                    $this->buffer = substr($this->buffer, $this->position);
                    $this->position = 0;
                }
                $this->buffer .= $chunk;
                continue;
            }
            [$fin, $opcode, $payload] = $frame;
            if ($opcode >= 0x8) {
                if ($opcode === 0x8) {
                    $this->close();

                    return null;
                }
                if ($opcode === 0x9) {
                    $this->write(self::frame(0xA, $payload), $deadline ?? Loop::now() + self::PONG_SECONDS);
                }
                continue; // A pong, or another control frame: nothing to do.
            }
            if ($opcode === 0x0) {
                if ($message === null) {
                    throw $this->fail('Continuation without a message');
                }
                $message[1] .= $payload;
            } else {
                if ($message !== null) {
                    throw $this->fail('A message inside another');
                }
                $message = [$opcode, $payload];
            }
            if (\strlen($message[1]) > Protocol::MAX_MESSAGE_BYTES) {
                throw $this->fail('Message larger than 1 MiB');
            }
            if (!$fin) {
                continue;
            }
            [$type, $data] = $message;
            $message = null;
            if ($type === 0x1) {
                if (!Text::isUtf8($data)) {
                    throw $this->fail('Text message not UTF-8');
                }

                return $data;
            }
            // A binary message: not of this protocol, skipped.
        }

        return null;
    }

    /** Closes the connection now, without waiting: a close frame if it can go at once. */
    public function close(): void
    {
        if ($this->open) {
            $this->open = false;
            $this->socket->tryWrite(self::frame(0x8, pack('n', 1000)));
        }
        $this->socket->close();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    private function handshake(string $key, float $deadline): void
    {
        // Each byte searched once (SPEC.md §13: reading costs time linear in its size).
        $from = 0;
        while (true) {
            $end = strpos($this->buffer, "\r\n\r\n", $from);
            if ($end !== false || \strlen($this->buffer) > self::MAX_HEAD) {
                break;
            }
            $chunk = $this->socket->read($deadline);
            if ($chunk === null) {
                throw new TransportException('WebSocket handshake cut short');
            }
            $from = max(0, \strlen($this->buffer) - 3); // A CRLF CRLF may straddle the bytes before.
            $this->buffer .= $chunk;
        }
        if ($end === false || $end > self::MAX_HEAD) {
            throw new TransportException('WebSocket handshake too large');
        }
        $lines = explode("\r\n", substr($this->buffer, 0, $end));
        $this->buffer = substr($this->buffer, $end + 4);
        $this->position = 0;
        if (preg_match('~^HTTP/1\.1 101(?: .*)?$~sD', array_shift($lines)) !== 1) {
            throw new TransportException('WebSocket handshake refused');
        }
        $fields = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new TransportException('Invalid WebSocket handshake');
            }
            $fields[Text::asciiLower(substr($line, 0, $colon))][] = trim(substr($line, $colon + 1), " \t");
        }
        $upgrade = Text::asciiLower(implode(',', $fields['upgrade'] ?? []));
        $connection = array_map('trim', explode(',', Text::asciiLower(implode(',', $fields['connection'] ?? []))));
        $accept = $fields['sec-websocket-accept'] ?? [];
        if ($upgrade !== 'websocket' || !\in_array('upgrade', $connection, true)
            || $accept !== [Base64::encode(sha1($key . self::GUID, true))]
            || isset($fields['sec-websocket-extensions']) || isset($fields['sec-websocket-protocol'])) {
            throw new TransportException('Invalid WebSocket handshake');
        }
    }

    /**
     * The next whole frame of the buffer: whether it is final, its opcode and payload; null when
     * it has not all come yet.
     *
     * @return array{0: bool, 1: int, 2: string}|null
     */
    private function frameFromBuffer(): ?array
    {
        // From $position on, so that many small frames are not each a copy of the whole buffer.
        $start = $this->position;
        $length = \strlen($this->buffer) - $start;
        if ($length < 2) {
            return null;
        }
        $first = \ord($this->buffer[$start]);
        $second = \ord($this->buffer[$start + 1]);
        if (($first & 0x70) !== 0) {
            throw $this->fail('Reserved bits set');
        }
        if (($second & 0x80) !== 0) {
            throw $this->fail('A masked frame from the server');
        }
        $opcode = $first & 0x0F;
        $fin = ($first & 0x80) !== 0;
        if (!\in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xA], true)) {
            throw $this->fail('Unknown opcode');
        }
        $size = $second & 0x7F;
        $offset = 2;
        if ($size === 126) {
            if ($length < 4) {
                return null;
            }
            $size = (\ord($this->buffer[$start + 2]) << 8) | \ord($this->buffer[$start + 3]);
            $offset = 4;
        } elseif ($size === 127) {
            if ($length < 10) {
                return null;
            }
            $high = unpack('N', $this->buffer, $start + 2);
            $low = unpack('N', $this->buffer, $start + 6);
            \assert(\is_array($high) && \is_array($low) && \is_int($high[1]) && \is_int($low[1]));
            if ($high[1] !== 0) {
                throw $this->fail('Message larger than 1 MiB');
            }
            $size = $low[1];
            $offset = 10;
        }
        if ($opcode >= 0x8 && ($size > 125 || !$fin)) {
            throw $this->fail('Invalid control frame');
        }
        if ($size > Protocol::MAX_MESSAGE_BYTES) {
            throw $this->fail('Message larger than 1 MiB');
        }
        if ($length < $offset + $size) {
            return null;
        }
        $payload = substr($this->buffer, $start + $offset, $size);
        $this->position = $start + $offset + $size;

        return [$fin, $opcode, $payload];
    }

    /**
     * One frame, written whole before another starts: the wait for the one before is within
     * $deadline too.
     *
     * @throws TransportException
     */
    private function write(string $frame, ?float $deadline): void
    {
        try {
            $this->writes->with('frame', fn() => $this->socket->write($frame, $deadline), $deadline);
        } catch (TimeoutException) {
            throw new TransportException('Timed out');
        }
    }

    private function fail(string $reason): TransportException
    {
        $this->open = false;
        $this->socket->close();

        return new TransportException($reason);
    }

    /** A frame from the client: final, masked with 4 random bytes. */
    private static function frame(int $opcode, string $payload): string
    {
        $length = \strlen($payload);
        $head = \chr(0x80 | $opcode);
        if ($length < 126) {
            $head .= \chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= \chr(0x80 | 126) . pack('n', $length);
        } else {
            $head .= \chr(0x80 | 127) . pack('NN', 0, $length);
        }
        $mask = random_bytes(4);

        return $head . $mask . ($payload ^ str_repeat($mask, intdiv($length + 3, 4)));
    }
}
