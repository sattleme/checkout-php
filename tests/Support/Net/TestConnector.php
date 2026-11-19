<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\Net\PlainConnector;
use Sattleme\Checkout\Net\TlsConnector;

/**
 * Reaches the tests' local servers under the names the SDK asks (relay.example, breez.tips,
 * mempool.space…): never the network. A name no server took fails, as an unreachable host.
 */
final class TestConnector implements Connector, PlainConnector
{
    /** @var list<string> every "host:port" asked without TLS, in order (also in $asked) */
    public array $askedPlain = [];

    /** @var array<string, array{port: int, tls: bool}> by "host:port" */
    private array $routes = [];

    /** @var list<string> every "host:port" asked, in order */
    public array $asked = [];

    public function __construct(private readonly ?TestCertificates $certificates = null) {}

    public function route(string $host, int $port, LocalServer $server): void
    {
        $this->routes[$host . ':' . $port] = ['port' => $server->port, 'tls' => $server->tls];
    }

    /** Routes $host:$port to a plain TCP server of another process, on $localPort. */
    public function routePort(string $host, int $port, int $localPort): void
    {
        $this->routes[$host . ':' . $port] = ['port' => $localPort, 'tls' => false];
    }

    public function forget(string $host, int $port): void
    {
        unset($this->routes[$host . ':' . $port]);
    }

    public function connectPlain(string $host, int $port, int $timeoutMs): mixed
    {
        $this->asked[] = $host . ':' . $port;
        $this->askedPlain[] = $host . ':' . $port;
        $route = $this->routes[$host . ':' . $port] ?? null;
        if ($route === null) {
            throw new \RuntimeException("No test server for {$host}:{$port}");
        }
        if ($route['tls']) {
            // A TLS server asked without TLS: no answer it can read.
            throw new \RuntimeException("{$host}:{$port} asked without TLS");
        }

        return TlsConnector::open('127.0.0.1', $route['port'], $timeoutMs, null);
    }

    public function connect(string $host, int $port, int $timeoutMs): mixed
    {
        $this->asked[] = $host . ':' . $port;
        $route = $this->routes[$host . ':' . $port] ?? null;
        if ($route === null) {
            throw new \RuntimeException("No test server for {$host}:{$port}");
        }
        $ssl = null;
        if ($route['tls']) {
            if ($this->certificates === null) {
                throw new \LogicException('A TLS server without test certificates');
            }
            $ssl = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'cafile' => $this->certificates->caFile(),
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ];
        }

        return TlsConnector::open('127.0.0.1', $route['port'], $timeoutMs, $ssl);
    }
}
