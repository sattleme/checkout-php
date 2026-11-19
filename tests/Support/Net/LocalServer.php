<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Task;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Tests\Support\TestTime;

/**
 * A TCP server on a free port of 127.0.0.1, plain or TLS, served by the SDK's own loop while a call
 * runs: one task per connection, which $handler runs. A server and the SDK share the loop, so a
 * call that blocked it would hang its test: what these tests catch.
 */
final class LocalServer
{
    public readonly int $port;

    /** @var resource */
    private mixed $listener;

    private Task $accepting;

    /** @var list<Task> */
    private array $connections = [];

    /** @var list<Socket> */
    private array $sockets = [];

    /** How many connections it accepted. */
    public int $accepted = 0;

    /**
     * @param \Closure(Socket, LocalServer): void $handler
     */
    public function __construct(\Closure $handler, public readonly bool $tls = false, ?TestCertificates $certificates = null)
    {
        $context = stream_context_create($tls && $certificates !== null ? ['ssl' => [
            'local_cert' => $certificates->serverFile(),
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | \STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        ]] : []);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
        if ($listener === false) {
            throw new \RuntimeException("Cannot listen: {$message}");
        }
        stream_set_blocking($listener, false);
        $this->listener = $listener;
        $name = (string) stream_socket_get_name($listener, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $loop = Loop::get();
        $this->accepting = $loop->spawnDetached(function () use ($loop, $handler, $tls): void {
            while (\is_resource($this->listener)) {
                if (!$loop->awaitReadable($this->listener, null)) {
                    continue;
                }
                $client = @stream_socket_accept($this->listener, 0);
                if ($client === false) {
                    continue;
                }
                ++$this->accepted;
                $this->connections[] = $loop->spawnDetached(function () use ($loop, $client, $handler, $tls): void {
                    stream_set_blocking($client, false);
                    if ($tls && !self::serverHandshake($loop, $client)) {
                        fclose($client);

                        return;
                    }
                    $socket = new Socket($client);
                    $this->sockets[] = $socket;
                    try {
                        $handler($socket, $this);
                    } catch (\Throwable) {
                        // A connection the client dropped, or a handler that gave up.
                    } finally {
                        $socket->close();
                    }
                });
            }
        });
    }

    /** Closes the server and every connection it holds. */
    public function stop(): void
    {
        $loop = Loop::get();
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        foreach ($this->connections as $task) {
            $loop->cancel($task);
        }
        $loop->cancel($this->accepting);
        if (\is_resource($this->listener)) {
            $loop->forget($this->listener);
            fclose($this->listener);
        }
    }

    /** Closes the connections it holds, the server listening on. */
    public function dropConnections(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        $this->sockets = [];
    }

    /** @param resource $client */
    private static function serverHandshake(Loop $loop, mixed $client): bool
    {
        $deadline = TestTime::deadline(5);
        while (Loop::now() < $deadline) {
            set_error_handler(static fn(): bool => true);
            try {
                $done = stream_socket_enable_crypto($client, true, \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | \STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
            } finally {
                restore_error_handler();
            }
            if ($done === true) {
                return true;
            }
            if ($done === false) {
                return false;
            }
            $loop->awaitReadable($client, min($deadline, Loop::now() + 0.002));
        }

        return false;
    }
}
