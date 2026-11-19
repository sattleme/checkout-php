<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Tests\Support\TestTime;

/**
 * An HTTP/1.1 server for the tests: each request goes to the route of its Host, which answers it
 * (its delays and waits scaled: TestTime). Every request is kept, to check what the SDK asked.
 */
final class FakeHttpServer
{
    public readonly LocalServer $server;

    /** @var array<string, \Closure(HttpRequest): HttpResponseSpec> by host */
    private array $routes = [];

    /** @var array<string, \Closure(ServerWebSocket, HttpRequest): void> by host */
    private array $webSockets = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    public function __construct(bool $tls = false, ?TestCertificates $certificates = null)
    {
        $this->server = new LocalServer(function (Socket $socket): void {
            $this->serve($socket);
        }, $tls, $certificates);
    }

    /** @param \Closure(HttpRequest): HttpResponseSpec $handler */
    public function on(string $host, \Closure $handler): void
    {
        $this->routes[$host] = $handler;
    }

    /** @param \Closure(ServerWebSocket, HttpRequest): void $handler serves the WebSocket connections to $host */
    public function onWebSocket(string $host, \Closure $handler): void
    {
        $this->webSockets[$host] = $handler;
    }

    /** @return list<HttpRequest> the requests to $host */
    public function requestsTo(string $host): array
    {
        return array_values(array_filter($this->requests, static fn(HttpRequest $r): bool => $r->host() === $host));
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    private function serve(Socket $socket): void
    {
        $buffer = '';
        while (true) {
            $end = strpos($buffer, "\r\n\r\n");
            if ($end !== false) {
                break;
            }
            $chunk = $socket->read(TestTime::deadline(10));
            if ($chunk === null) {
                return;
            }
            $buffer .= $chunk;
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        $requestLine = explode(' ', array_shift($lines));
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower($name)] = trim($value);
        }
        $request = new HttpRequest($requestLine[0], $requestLine[1] ?? '', $headers);
        $this->requests[] = $request;
        $webSocket = $this->webSockets[$request->host()] ?? null;
        if ($webSocket !== null && strtolower($headers['upgrade'] ?? '') === 'websocket' && isset($headers['sec-websocket-key'])) {
            $webSocket(ServerWebSocket::upgraded($socket, $headers['sec-websocket-key'], $request->target, substr($buffer, $end + 4)), $request);

            return;
        }
        $handler = $this->routes[$request->host()] ?? null;
        $response = $handler !== null ? $handler($request) : new HttpResponseSpec(404, 'no route');
        if ($response->delayMs > 0) {
            Loop::get()->sleepUntil(TestTime::deadline($response->delayMs / 1000));
        }
        if ($response->silent) {
            Loop::get()->sleepUntil(TestTime::deadline(60));

            return;
        }
        if ($response->hangUp) {
            return;
        }
        if ($response->raw !== null) {
            $socket->write($response->raw, TestTime::deadline(30));

            return;
        }
        $head = "HTTP/1.1 {$response->status} Test\r\n";
        foreach ($response->headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        $body = $response->body;
        if ($response->framing === 'length') {
            $socket->write($head . 'Content-Length: ' . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body, TestTime::deadline(10));
        } elseif ($response->framing === 'chunked') {
            $chunks = '';
            foreach ($body === '' ? [] : str_split($body, 7) as $part) {
                $chunks .= dechex(\strlen($part)) . ";ext=1\r\n" . $part . "\r\n";
            }
            $socket->write($head . "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n" . $chunks . "0\r\n\r\n", TestTime::deadline(10));
        } else {
            $socket->write($head . "Connection: close\r\n\r\n" . $body, TestTime::deadline(10));
        }
    }
}
