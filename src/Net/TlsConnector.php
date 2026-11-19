<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Net;

use Sattleme\Checkout\Internal\Loop\Loop;

/**
 * Connects over TCP, then TLS 1.2 or 1.3 with the peer's certificate verified for the host
 * (never `verify_peer` false), without blocking the SDK's event loop but for the name lookup
 * (PHP has no asynchronous resolver): a connection whose time it took fails once it returns.
 *
 * The certificate authorities are PHP's (openssl.cafile, openssl.capath, else OpenSSL's default
 * paths; on Windows, the system store), or the bundle given.
 *
 * Without TLS (PlainConnector), only for a merchant's Bitcoin node on a local network over
 * `http://` (SPEC.md §31): the SDK asks it for no other host.
 */
final class TlsConnector implements Connector, PlainConnector
{
    /**
     * @param string|null $caFile a PEM bundle of the certificate authorities to trust instead
     */
    public function __construct(private readonly ?string $caFile = null)
    {
        if ($caFile !== null && !is_readable($caFile)) {
            throw new \InvalidArgumentException('Unreadable certificate authority bundle');
        }
    }

    public function connect(string $host, int $port, int $timeoutMs): mixed
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        return self::open($host, $port, $timeoutMs, $ssl);
    }

    public function connectPlain(string $host, int $port, int $timeoutMs): mixed
    {
        return self::open($host, $port, $timeoutMs, null);
    }

    /**
     * A non-blocking TCP connection to $host:$port, with TLS established when $ssl is given.
     *
     * @param array<string, mixed>|null $ssl the context's "ssl" options
     *
     * @return resource
     *
     * @internal also for tests' connectors
     */
    public static function open(string $host, int $port, int $timeoutMs, ?array $ssl): mixed
    {
        $deadline = Loop::now() + $timeoutMs / 1000;
        // TCP_NODELAY: a relay's messages are small, one after another (CLOSE, then EVENT): Nagle's
        // algorithm would hold each behind the peer's delayed ACK (40 ms or more).
        $options = ['socket' => ['tcp_nodelay' => true]];
        if ($ssl !== null) {
            $options['ssl'] = $ssl;
        }
        $context = stream_context_create($options);
        $error = null;
        set_error_handler(static function (int $type, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $stream = stream_socket_client(
                'tcp://' . $host . ':' . $port,
                $code,
                $message,
                max(0.001, $timeoutMs / 1000),
                \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port}: " . ($message !== '' ? $message : (string) $error));
        }
        if (Loop::now() >= $deadline) {
            // The name lookup, which blocks, took the request's time.
            fclose($stream);
            throw new \RuntimeException("Connection to {$host}:{$port} timed out");
        }
        stream_set_blocking($stream, false);
        $loop = Loop::get();
        try {
            if (!$loop->awaitWritable($stream, $deadline)) {
                throw new \RuntimeException("Connection to {$host}:{$port} timed out");
            }
            if (stream_socket_get_name($stream, true) === false) {
                throw new \RuntimeException("Cannot connect to {$host}:{$port}");
            }
            if ($ssl !== null) {
                self::handshake($stream, $host, $port, $deadline);
            }
        } catch (\Throwable $e) {
            $loop->forget($stream);
            fclose($stream);
            throw $e;
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function handshake(mixed $stream, string $host, int $port, float $deadline): void
    {
        $loop = Loop::get();
        while (true) {
            $error = null;
            set_error_handler(static function (int $type, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });
            try {
                $done = stream_socket_enable_crypto($stream, true, \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            } finally {
                restore_error_handler();
            }
            if ($done === true) {
                return;
            }
            if ($done === false) {
                throw new \RuntimeException("TLS with {$host}:{$port} failed" . ($error !== null ? ": {$error}" : ''));
            }
            // More of the handshake to read (rarely to write: polled every 100 ms at most).
            if (Loop::now() >= $deadline) {
                throw new \RuntimeException("TLS with {$host}:{$port} timed out");
            }
            $loop->awaitReadable($stream, min($deadline, Loop::now() + 0.1));
        }
    }
}
