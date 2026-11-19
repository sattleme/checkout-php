<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Tests\Support\TestTime;

/** The server's side of a WebSocket connection (RFC 6455), for the tests' relays. */
final class ServerWebSocket
{
    private string $buffer = '';

    public bool $open = true;

    public string $path = '/';

    private function __construct(private readonly Socket $socket) {}

    /** Answers a client's handshake; null when it is none. */
    public static function accept(Socket $socket): ?self
    {
        $webSocket = new self($socket);
        while (true) {
            $end = strpos($webSocket->buffer, "\r\n\r\n");
            if ($end !== false) {
                break;
            }
            $chunk = $socket->read(TestTime::deadline(10));
            if ($chunk === null) {
                return null;
            }
            $webSocket->buffer .= $chunk;
        }
        $lines = explode("\r\n", substr($webSocket->buffer, 0, $end));
        $rest = substr($webSocket->buffer, $end + 4);
        $path = explode(' ', array_shift($lines))[1] ?? '/';
        $key = null;
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            if (strtolower($name) === 'sec-websocket-key') {
                $key = trim($value);
            }
        }
        if ($key === null) {
            return null;
        }

        return self::upgraded($socket, $key, $path, $rest);
    }

    /** Answers a handshake whose request was read already ($rest: what came after it). */
    public static function upgraded(Socket $socket, string $key, string $path, string $rest = ''): self
    {
        $webSocket = new self($socket);
        $webSocket->buffer = $rest;
        $webSocket->path = $path;
        $accept = Base64::encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $socket->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n", TestTime::deadline(10));

        return $webSocket;
    }

    /** The next text message from the client, or null once it closed. */
    public function receive(): ?string
    {
        $message = '';
        while ($this->open) {
            if (\strlen($this->buffer) >= 2) {
                $second = \ord($this->buffer[1]);
                $size = $second & 0x7F;
                $offset = 2;
                if ($size === 126 && \strlen($this->buffer) >= 4) {
                    $size = (\ord($this->buffer[2]) << 8) | \ord($this->buffer[3]);
                    $offset = 4;
                } elseif ($size === 127 && \strlen($this->buffer) >= 10) {
                    $size = (int) hexdec(bin2hex(substr($this->buffer, 2, 8)));
                    $offset = 10;
                } elseif ($size >= 126) {
                    $size = \PHP_INT_MAX;
                }
                if ($size !== \PHP_INT_MAX && \strlen($this->buffer) >= $offset + 4 + $size) {
                    $first = \ord($this->buffer[0]);
                    $mask = substr($this->buffer, $offset, 4);
                    $payload = substr($this->buffer, $offset + 4, $size);
                    $payload = $payload ^ str_repeat($mask, intdiv($size + 3, 4));
                    $this->buffer = substr($this->buffer, $offset + 4 + $size);
                    $opcode = $first & 0x0F;
                    if ($opcode === 0x8) {
                        $this->open = false;

                        return null;
                    }
                    if ($opcode === 0x9 || $opcode === 0xA) {
                        continue;
                    }
                    $message .= $payload;
                    if (($first & 0x80) !== 0) {
                        return $message;
                    }
                    continue;
                }
            }
            $chunk = $this->socket->read(null);
            if ($chunk === null) {
                $this->open = false;

                return null;
            }
            $this->buffer .= $chunk;
        }

        return null;
    }

    /** Sends a text message, in $fragments frames. */
    public function send(string $text, int $fragments = 1): void
    {
        $parts = $fragments > 1 ? str_split($text, max(1, (int) ceil(\strlen($text) / $fragments))) : [$text];
        $data = '';
        foreach ($parts as $index => $part) {
            $opcode = $index === 0 ? 0x1 : 0x0;
            $data .= self::frame($opcode, $part, $index === \count($parts) - 1);
        }
        $this->socket->write($data, TestTime::deadline(10));
    }

    /** Sends a raw frame. */
    public function sendFrame(int $opcode, string $payload, bool $fin = true): void
    {
        $this->socket->write(self::frame($opcode, $payload, $fin), TestTime::deadline(10));
    }

    public function close(): void
    {
        $this->open = false;
        $this->socket->close();
    }

    private static function frame(int $opcode, string $payload, bool $fin): string
    {
        $length = \strlen($payload);
        $head = \chr(($fin ? 0x80 : 0) | $opcode);
        if ($length < 126) {
            $head .= \chr($length);
        } elseif ($length < 65536) {
            $head .= \chr(126) . pack('n', $length);
        } else {
            $head .= \chr(127) . pack('NN', 0, $length);
        }

        return $head . $payload;
    }
}
