<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Http;

use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Internal\Onchain\NodeUrl;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Url;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\Net\PlainConnector;
use Sattleme\Checkout\Net\TlsConnector;

/**
 * HTTP/1.1 GET over the SDK's own connections (SPEC.md §15): https URLs, and a merchant's Bitcoin
 * node's (SPEC.md §31: its URL's own rule, `http` to a local host through a PlainConnector, an
 * IPv4 address; every other rule the same); one connection per request, closed
 * after it; redirects never followed; the body read up to 1 MiB, whether its length is given,
 * chunked or up to the end of the connection; its head (each status line and its headers, 64 KiB
 * at most, and a chunked body's size lines and trailers, 64 KiB at most too) 10 MiB at most in
 * all; the whole request within its timeout.
 */
final class StreamHttpClient implements HttpClient
{
    private const MAX_HEAD = 65536;

    /** Interim answers (100 Continue, 103 Early Hints) before the answer, at most. */
    private const MAX_INTERIM = 8;

    private readonly Connector $connector;

    public function __construct(?Connector $connector = null)
    {
        $this->connector = $connector ?? new TlsConnector();
    }

    public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
    {
        $target = Url::request($url);
        $tls = true;
        if ($target === null) {
            // A merchant's Bitcoin node (SPEC.md §31): never another http URL, nor an IP address.
            $target = NodeUrl::request($url);
            if ($target === null) {
                throw new \InvalidArgumentException('Not an https URL');
            }
            $tls = $target['tls'];
            if (!$tls && !$this->connector instanceof PlainConnector) {
                throw new \RuntimeException('This connector makes no connection without TLS');
            }
        }
        $lines = [
            'GET ' . $target['target'] . ' HTTP/1.1',
            'Host: ' . $target['host'] . ($target['port'] === ($tls ? 443 : 80) ? '' : ':' . $target['port']),
            'Accept: application/json',
            'Connection: close',
        ];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid header');
            }
            $lines[] = $name . ': ' . $value;
        }
        $deadline = Loop::now() + $timeoutMs / 1000;
        $remaining = (int) ceil(($deadline - Loop::now()) * 1000);
        $connector = $this->connector;
        $socket = new Socket($tls || !$connector instanceof PlainConnector
            ? $connector->connect($target['host'], $target['port'], max(1, $remaining))
            : $connector->connectPlain($target['host'], $target['port'], max(1, $remaining)));
        try {
            $socket->write(implode("\r\n", $lines) . "\r\n\r\n", $deadline);

            return self::response($socket, $deadline);
        } finally {
            $socket->close();
        }
    }

    private static function response(Socket $socket, float $deadline): HttpResponse
    {
        $buffer = '';
        // What of the head was read: every status line and header, a chunked body's size lines.
        $headBytes = 0;
        for ($interim = 0; ; ++$interim) {
            if ($interim > self::MAX_INTERIM) {
                throw new \RuntimeException('Too many interim HTTP answers');
            }
            $end = self::headEnd($socket, $deadline, $buffer);
            $headBytes += $end + 4;
            $head = substr($buffer, 0, $end);
            $buffer = substr($buffer, $end + 4);
            $headLines = explode("\r\n", $head);
            if (preg_match('~^HTTP/1\.[01] ([1-5][0-9][0-9])(?: .*)?$~sD', array_shift($headLines), $match) !== 1) {
                throw new \RuntimeException('Not an HTTP answer');
            }
            $status = (int) $match[1];
            $fields = [];
            foreach ($headLines as $line) {
                $colon = strpos($line, ':');
                if ($colon === false || $colon === 0) {
                    throw new \RuntimeException('Invalid HTTP header');
                }
                $fields[strtolower(substr($line, 0, $colon))][] = trim(substr($line, $colon + 1), " \t");
            }
            if ($status >= 100 && $status < 200) {
                continue; // An interim answer (100 Continue, 103 Early Hints): the answer follows.
            }
            if ($status === 204 || $status === 304) {
                return new HttpResponse($status, '');
            }

            return new HttpResponse($status, self::body($socket, $deadline, $fields, $buffer, $headBytes));
        }
    }

    /**
     * Reads until the end of a head (its position in $buffer), 64 KiB at most: each byte searched
     * once (SPEC.md §13: reading costs time linear in its size).
     */
    private static function headEnd(Socket $socket, float $deadline, string &$buffer): int
    {
        $from = 0;
        while (true) {
            $end = strpos($buffer, "\r\n\r\n", $from);
            if ($end !== false) {
                if ($end > self::MAX_HEAD) {
                    throw new \RuntimeException('HTTP head too large');
                }

                return $end;
            }
            if (\strlen($buffer) > self::MAX_HEAD) {
                throw new \RuntimeException('HTTP head too large');
            }
            $chunk = $socket->read($deadline);
            if ($chunk === null) {
                throw new \RuntimeException('HTTP connection closed before an answer');
            }
            $from = max(0, \strlen($buffer) - 3); // A CRLF CRLF may straddle the bytes before.
            $buffer .= $chunk;
        }
    }

    /**
     * @param array<string, list<string>> $fields
     * @param int                          $headBytes what of the head was read so far
     */
    private static function body(Socket $socket, float $deadline, array $fields, string $buffer, int $headBytes): string
    {
        $encoding = strtolower(implode(',', $fields['transfer-encoding'] ?? []));
        if ($encoding !== '') {
            $codings = array_map('trim', explode(',', $encoding));
            if ($codings[\count($codings) - 1] !== 'chunked') {
                throw new \RuntimeException('Unsupported transfer encoding');
            }

            return self::chunked($socket, $deadline, $buffer, $headBytes);
        }
        if (isset($fields['content-length'])) {
            $lengths = array_unique($fields['content-length']);
            if (\count($lengths) !== 1 || preg_match('/^[0-9]{1,16}$/D', $lengths[0]) !== 1) {
                throw new \RuntimeException('Invalid Content-Length');
            }
            $length = (int) $lengths[0];
            if ($length > Protocol::MAX_BODY_BYTES) {
                throw new \RuntimeException('HTTP body larger than 1 MiB');
            }
            while (\strlen($buffer) < $length) {
                $chunk = $socket->read($deadline);
                if ($chunk === null) {
                    throw new \RuntimeException('HTTP body cut short');
                }
                $buffer .= $chunk;
            }

            return substr($buffer, 0, $length);
        }
        while (true) {
            if (\strlen($buffer) > Protocol::MAX_BODY_BYTES) {
                throw new \RuntimeException('HTTP body larger than 1 MiB');
            }
            $chunk = $socket->read($deadline);
            if ($chunk === null) {
                return $buffer;
            }
            $buffer .= $chunk;
        }
    }

    /**
     * A chunked body: each byte read once, from where the last chunk ended (SPEC.md §13: reading
     * costs time linear in its size, however small the chunks). Its size lines and its trailers are
     * of the head: with $headBytes of it read before, 10 MiB at most in all (SPEC.md §15).
     */
    private static function chunked(Socket $socket, float $deadline, string $buffer, int $headBytes): string
    {
        $body = '';
        // What of $buffer was read: what came is copied only as more comes.
        $position = 0;
        $fill = static function (int $needed) use ($socket, $deadline, &$buffer, &$position): void {
            while (\strlen($buffer) - $position < $needed) {
                $chunk = $socket->read($deadline);
                if ($chunk === null) {
                    throw new \RuntimeException('HTTP body cut short');
                }
                $buffer = substr($buffer, $position) . $chunk;
                $position = 0;
            }
        };
        while (true) {
            $line = strpos($buffer, "\r\n", $position);
            if ($line === false) {
                if (\strlen($buffer) - $position > 4096) {
                    throw new \RuntimeException('Invalid HTTP chunk');
                }
                $fill(\strlen($buffer) - $position + 1);
                continue;
            }
            $headBytes += $line - $position + 2;
            if ($headBytes > Protocol::MAX_HEAD_BYTES) {
                throw new \RuntimeException('HTTP head larger than 10 MiB');
            }
            $sizeText = trim(explode(';', substr($buffer, $position, $line - $position), 2)[0], " \t");
            if (preg_match('/^[0-9A-Fa-f]{1,8}$/D', $sizeText) !== 1) {
                throw new \RuntimeException('Invalid HTTP chunk size');
            }
            $size = (int) hexdec($sizeText);
            $position = $line + 2;
            if ($size === 0) {
                self::trailers($socket, $deadline, substr($buffer, $position), $headBytes);

                return $body;
            }
            if (\strlen($body) + $size > Protocol::MAX_BODY_BYTES) {
                throw new \RuntimeException('HTTP body larger than 1 MiB');
            }
            $fill($size + 2);
            if (substr($buffer, $position + $size, 2) !== "\r\n") {
                throw new \RuntimeException('Invalid HTTP chunk');
            }
            $body .= substr($buffer, $position, $size);
            $position += $size + 2;
        }
    }

    /**
     * The trailer section that ends a chunked body ($rest: what came after its last chunk's line),
     * up to the empty line that ends it: at once when there is none, else after its fields. Of the
     * head (SPEC.md §15): 64 KiB at most, as a head, and 10 MiB at most in all with the $headBytes
     * read before; read, never kept (the SDK reads no trailer), each byte searched once.
     */
    private static function trailers(Socket $socket, float $deadline, string $rest, int $headBytes): void
    {
        // The CRLF that ended the last chunk's line, so that one search finds the end either way.
        $before = "\r\n";
        $read = 0;
        while (true) {
            $end = strpos($before . $rest, "\r\n\r\n");
            $read += $end === false ? \strlen($rest) : $end + 4 - \strlen($before);
            if ($read > self::MAX_HEAD || $headBytes + $read > Protocol::MAX_HEAD_BYTES) {
                throw new \RuntimeException('HTTP head too large: its trailers');
            }
            if ($end !== false) {
                return;
            }
            $before = substr($before . $rest, -3); // A CRLF CRLF may straddle the bytes before.
            $chunk = $socket->read($deadline);
            if ($chunk === null) {
                throw new \RuntimeException('HTTP body cut short');
            }
            $rest = $chunk;
        }
    }
}
