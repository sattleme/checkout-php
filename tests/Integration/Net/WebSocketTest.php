<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Net;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Internal\Net\TransportException;
use Sattleme\Checkout\Internal\Net\WebSocket;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Tests\Support\Net\LocalServer;
use Sattleme\Checkout\Tests\Support\Net\ServerWebSocket;
use Sattleme\Checkout\Tests\Support\Net\TestCertificates;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;

/** The WebSocket client: messages capped at 1 MiB (SPEC.md §13), a bounded handshake, TLS. */
final class WebSocketTest extends TestCase
{
    private const MIB = 1048576;

    /** @var list<LocalServer> */
    private array $servers = [];

    private TestConnector $connector;

    private static TestCertificates $certificates;

    public static function setUpBeforeClass(): void
    {
        self::$certificates = new TestCertificates(['relay.example']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$certificates->remove();
    }

    protected function setUp(): void
    {
        $this->connector = new TestConnector(self::$certificates);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
    }

    public function testMessagesBothWaysFragmentsAndPings(): void
    {
        $this->serve(static function (ServerWebSocket $ws): void {
            while (($message = $ws->receive()) !== null) {
                $ws->sendFrame(0x9, 'ping?');
                $ws->send('echo:' . $message, 3);
            }
        });
        $received = Loop::get()->run(function (): array {
            $ws = WebSocket::connect($this->connector, 'wss://relay.example/nostr', TestTime::deadline(2));
            $big = str_repeat('é', 40000);
            $ws->send('["REQ","x"]', TestTime::deadline(2));
            $first = $ws->receive(TestTime::deadline(2));
            $ws->send($big, TestTime::deadline(2));
            $second = $ws->receive(TestTime::deadline(2));
            $ws->close();

            return [$first, $second === 'echo:' . $big];
        });
        self::assertSame(['echo:["REQ","x"]', true], $received);
    }

    public function testAMessageOfOneMebibyteAtMost(): void
    {
        $this->serve(static function (ServerWebSocket $ws): void {
            $ws->send(str_repeat('a', self::MIB));
            $ws->send(str_repeat('b', self::MIB / 2) . str_repeat('c', self::MIB / 2 + 1), 2);
            $ws->receive();
        });
        $result = Loop::get()->run(function (): array {
            $ws = WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2));
            $first = $ws->receive(TestTime::deadline(5));
            try {
                $ws->receive(TestTime::deadline(5));

                return [\strlen((string) $first), 'taken'];
            } catch (TransportException $e) {
                return [\strlen((string) $first), $e->getMessage()];
            }
        });
        self::assertSame([self::MIB, 'Message larger than 1 MiB'], $result);
    }

    public function testTheHandshakeIsBounded(): void
    {
        $this->servers[] = $server = new LocalServer(static function (Socket $socket): void {
            Loop::get()->sleepUntil(TestTime::deadline(5));
        });
        $this->connector->route('relay.example', 443, $server);
        $start = Loop::now();
        try {
            Loop::get()->run(fn(): WebSocket => WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(0.2)));
            self::fail('No timeout');
        } catch (TransportException) {
            self::assertLessThan(TestTime::seconds(1), Loop::now() - $start);
        }
    }

    public function testTheAnswerToTheHandshakeIsBounded(): void
    {
        // SPEC.md §15: what the client holds of the answer to the opening handshake, its status
        // line and headers, is bounded too (here 16 KiB): a header line of 11 MiB fails the
        // connection, before it all came; a relay that answers so failed (network, §13).
        $this->servers[] = $server = new LocalServer(static function (Socket $socket): void {
            $socket->read(TestTime::deadline(2));
            $socket->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nX-Large: " . str_repeat('a', 11 * self::MIB) . "\r\n\r\n", TestTime::deadline(10));
        });
        $this->connector->route('relay.example', 443, $server);
        try {
            Loop::get()->run(fn(): WebSocket => WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(10)));
            self::fail('A handshake answered with a header of 11 MiB');
        } catch (TransportException $e) {
            self::assertSame('WebSocket handshake too large', $e->getMessage());
        }
        $context = TestContext::create($this->connector, null, null, 10000);
        $this->expectException(NetworkException::class);
        Loop::get()->run(static fn(): array => (new RelayPool($context))->read(['wss://relay.example'], ['kinds' => [30078]], false, static fn(): string => str_repeat("\x11", 32), static fn(\stdClass $event): bool => true));
    }

    public function testAHandshakeThatIsNoWebSocketIsRefused(): void
    {
        $this->servers[] = $server = new LocalServer(static function (Socket $socket): void {
            $socket->read(TestTime::deadline(2));
            $socket->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: wrong\r\n\r\n", TestTime::deadline(2));
        });
        $this->connector->route('relay.example', 443, $server);
        $this->expectException(TransportException::class);
        Loop::get()->run(fn(): WebSocket => WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2)));
    }

    public function testAHandshakeAnsweredWithARedirectFailsNeverFollowed(): void
    {
        $this->servers[] = $server = new LocalServer(static function (Socket $socket): void {
            $socket->read(TestTime::deadline(2));
            $socket->write("HTTP/1.1 301 Moved Permanently\r\nLocation: wss://other.example/\r\nContent-Length: 0\r\n\r\n", TestTime::deadline(2));
        });
        $this->connector->route('relay.example', 443, $server);
        $this->connector->route('other.example', 443, $server);
        try {
            Loop::get()->run(fn(): WebSocket => WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2)));
            self::fail('A redirect is a failed connection (SPEC.md §13)');
        } catch (TransportException) {
            self::assertSame(['relay.example:443'], $this->connector->asked);
        }
    }

    public function testNoCompressionIsOfferedNorTaken(): void
    {
        // A relay that accepts permessage-deflate (RFC 7692) when offered: none is offered
        // (SPEC.md §13, Limits), and one the relay takes without being offered fails the connection.
        $heads = [];
        $answer = static function (Socket $socket, bool $always) use (&$heads): void {
            $head = '';
            while (!str_contains($head, "\r\n\r\n")) {
                $chunk = $socket->read(TestTime::deadline(2));
                if ($chunk === null) {
                    return;
                }
                $head .= $chunk;
            }
            $heads[] = $head;
            $key = preg_match('/^Sec-WebSocket-Key: *(\S+)/mi', $head, $match) === 1 ? $match[1] : '';
            $accept = Base64::encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $offered = preg_match('/^Sec-WebSocket-Extensions:.*permessage-deflate/mi', $head) === 1;
            $deflate = $offered || $always ? "Sec-WebSocket-Extensions: permessage-deflate\r\n" : '';
            $socket->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n{$deflate}\r\n\x81\x05hello", TestTime::deadline(2));
            $socket->read(TestTime::deadline(2));
        };
        $this->servers[] = $server = new LocalServer(static fn(Socket $socket) => $answer($socket, false));
        $this->connector->route('relay.example', 443, $server);
        $connect = fn(): ?string => Loop::get()->run(fn(): ?string => WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2))->receive(TestTime::deadline(2)));
        self::assertSame('hello', $connect());
        self::assertCount(1, $heads);
        self::assertDoesNotMatchRegularExpression('/^Sec-WebSocket-Extensions:/mi', $heads[0]);

        $this->servers[] = $taking = new LocalServer(static fn(Socket $socket) => $answer($socket, true));
        $this->connector->route('relay.example', 443, $taking);
        $this->expectException(TransportException::class);
        $connect();
    }

    public function testTheHandshakeCarriesTheUserAgent(): void
    {
        // As nostr.wine behind Cloudflare's bot protection: a handshake without a User-Agent is
        // refused (403), one with the SDK's (SPEC.md §15) is answered.
        $heads = [];
        $this->servers[] = $server = new LocalServer(static function (Socket $socket) use (&$heads): void {
            $head = '';
            while (!str_contains($head, "\r\n\r\n")) {
                $chunk = $socket->read(TestTime::deadline(2));
                if ($chunk === null) {
                    return;
                }
                $head .= $chunk;
            }
            $heads[] = $head;
            if (preg_match('/^User-Agent: *\S/mi', $head) !== 1) {
                $socket->write("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n", TestTime::deadline(2));

                return;
            }
            $key = preg_match('/^Sec-WebSocket-Key: *(\S+)/mi', $head, $match) === 1 ? $match[1] : '';
            $ws = ServerWebSocket::upgraded($socket, $key, '/', substr($head, (int) strpos($head, "\r\n\r\n") + 4));
            $ws->send('hello');
            $ws->receive();
        });
        $this->connector->route('relay.example', 443, $server);
        self::assertSame('hello', Loop::get()->run(fn(): ?string => WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2))->receive(TestTime::deadline(2))));
        self::assertCount(1, $heads);
        self::assertMatchesRegularExpression('/^User-Agent: sattleme-Checkout\/1\r$/mi', $heads[0]);
    }

    public function testAMaskedFrameFromTheServerBreaksTheConnection(): void
    {
        $this->serve(static function (ServerWebSocket $ws): void {
            $ws->sendFrame(0x1, 'x'); // unmasked: fine
            // A raw masked frame: "\x81\x81" + mask + payload.
            $ws->sendFrame(0x1, 'y');
            $ws->receive();
        }, rawMasked: true);
        $this->expectException(TransportException::class);
        Loop::get()->run(function (): void {
            $ws = WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2));
            $ws->receive(TestTime::deadline(2));
            $ws->receive(TestTime::deadline(2));
        });
    }

    public function testAWriteToATlsConnectionThePeerClosedFailsAtOnce(): void
    {
        // A TLS connection the peer closed reads writable, and each fwrite() gives 0: the write
        // fails as lost, never tried again until its deadline (nor, without one, for ever).
        $this->serve(static function (ServerWebSocket $ws): void {
            $ws->close();
        }, true);
        $failed = Loop::get()->run(function (): float {
            $ws = WebSocket::connect($this->connector, 'wss://relay.example/', TestTime::deadline(2));
            Loop::get()->sleepUntil(TestTime::deadline(0.2)); // Closed by now.
            $start = Loop::now();
            try {
                for ($i = 0; $i < 100; ++$i) {
                    $ws->send(str_repeat('x', 1000), Loop::now() + 30);
                }
            } catch (TransportException) {
                return Loop::now() - $start;
            }
            self::fail('Lost');
        });
        self::assertLessThan(TestTime::seconds(5), $failed);
    }

    public function testOverTls(): void
    {
        $this->serve(static function (ServerWebSocket $ws): void {
            $ws->send('hello over tls');
            $ws->receive();
        }, tls: true);
        $message = Loop::get()->run(function (): ?string {
            $ws = WebSocket::connect($this->connector, 'wss://relay.example', TestTime::deadline(2));
            $message = $ws->receive(TestTime::deadline(2));
            $ws->close();

            return $message;
        });
        self::assertSame('hello over tls', $message);
    }

    /** @param \Closure(ServerWebSocket): void $handler */
    private function serve(\Closure $handler, bool $tls = false, bool $rawMasked = false): void
    {
        $this->servers[] = $server = new LocalServer(static function (Socket $socket) use ($handler, $rawMasked): void {
            $ws = ServerWebSocket::accept($socket);
            if ($ws === null) {
                return;
            }
            if ($rawMasked) {
                $socket->write("\x81\x01x\x81\x81abcd" . ('y' ^ 'a'), TestTime::deadline(2));
                $ws->receive();

                return;
            }
            $handler($ws);
        }, $tls, self::$certificates);
        $this->connector->route('relay.example', 443, $server);
    }
}
