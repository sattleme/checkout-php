<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Net;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Http\StreamHttpClient;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\Tests\Support\Net\FakeHttpServer;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Net\HttpResponseSpec;
use Sattleme\Checkout\Tests\Support\Net\TestCertificates;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestTime;

/** SPEC.md §15 on a local server: no redirect followed, 1 MiB of body, one timeout for all. */
final class HttpClientTest extends TestCase
{
    private const MIB = 1048576;

    private static TestCertificates $certificates;

    private FakeHttpServer $plain;

    private FakeHttpServer $tls;

    private TestConnector $connector;

    public static function setUpBeforeClass(): void
    {
        self::$certificates = new TestCertificates(['api.example', 'other.example']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$certificates->remove();
    }

    protected function setUp(): void
    {
        $this->plain = new FakeHttpServer();
        $this->tls = new FakeHttpServer(true, self::$certificates);
        $this->connector = new TestConnector(self::$certificates);
        $this->connector->route('api.example', 443, $this->plain->server);
        $this->connector->route('secure.example', 443, $this->tls->server);
        $this->connector->route('api.example', 8443, $this->tls->server);
    }

    protected function tearDown(): void
    {
        $this->plain->stop();
        $this->tls->stop();
    }

    public function testEveryBodyFramingAndTheRequestSent(): void
    {
        foreach (['length', 'chunked', 'close'] as $framing) {
            $this->plain->on('api.example', static fn(HttpRequest $r): HttpResponseSpec => HttpResponseSpec::json(['path' => $r->target, 'framing' => $framing], 200, $framing));
            $response = $this->get('https://api.example/v1/prices?x=1&y=caf%C3%A9#frag', ['x-cg-demo-api-key' => 'k']);
            self::assertSame(200, $response->status);
            self::assertSame('{"path":"/v1/prices?x=1&y=caf%C3%A9","framing":"' . $framing . '"}', $response->body);
        }
        $request = $this->plain->requests[0];
        self::assertSame('GET', $request->method);
        self::assertSame('api.example', $request->headers['host']);
        self::assertSame('application/json', $request->headers['accept']);
        self::assertSame('k', $request->headers['x-cg-demo-api-key']);
    }

    public function testARedirectIsReturnedNeverFollowed(): void
    {
        $this->plain->on('api.example', static fn(): HttpResponseSpec => new HttpResponseSpec(301, '', 'length', ['Location' => 'https://api.example/elsewhere']));
        $response = $this->get('https://api.example/start');
        self::assertSame(301, $response->status);
        self::assertCount(1, $this->plain->requests);
    }

    public function testTheBodyIsCappedAtOneMebibyte(): void
    {
        foreach (['length', 'chunked', 'close'] as $framing) {
            $this->plain->on('api.example', static fn(): HttpResponseSpec => new HttpResponseSpec(200, str_repeat('a', self::MIB), $framing));
            self::assertSame(self::MIB, \strlen($this->get('https://api.example/')->body), $framing);
            $this->plain->on('api.example', static fn(): HttpResponseSpec => new HttpResponseSpec(200, str_repeat('a', self::MIB + 1), $framing));
            try {
                $this->get('https://api.example/');
                self::fail("A body of 1 MiB + 1 byte was read ({$framing})");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('1 MiB', $e->getMessage());
            }
        }
    }

    public function testReadingAChunkedBodyTakesTimeLinearInItsSize(): void
    {
        // SPEC.md §13, §15: reading what a server sends costs time linear in its size, however
        // small its chunks: a body of 1 MiB in chunks of one byte (6 MiB with their size lines),
        // each byte read once (each chunk copied the rest of what was read with it: 2 s of CPU
        // here, 2.6 s on a busy machine, against 0.6 s and 0.9 to 1.5 s now). The process's CPU
        // time, the local server's included: not the time other processes take, but a busy
        // machine still slows the CPU it gives (shared caches and cores), so the least of three
        // reads counts.
        $chunked = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . str_repeat("1\r\na\r\n", self::MIB) . "0\r\n\r\n";
        $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::raw($chunked));
        $cpu = static function (): float {
            $usage = getrusage();
            self::assertIsArray($usage);
            $seconds = 0.0;
            foreach (['ru_utime', 'ru_stime'] as $kind) {
                self::assertIsInt($usage["{$kind}.tv_sec"]);
                self::assertIsInt($usage["{$kind}.tv_usec"]);
                $seconds += $usage["{$kind}.tv_sec"] + $usage["{$kind}.tv_usec"] / 1e6;
            }

            return $seconds;
        };
        $least = \INF;
        for ($read = 0; $read < 3 && $least >= 1.5; ++$read) {
            $start = $cpu();
            self::assertSame(str_repeat('a', self::MIB), $this->get('https://api.example/', [], 60000)->body);
            $least = min($least, $cpu() - $start);
        }
        self::assertLessThan(1.5, $least, 'a body of one-byte chunks');
    }

    public function testTheHeadIsBoundedToo(): void
    {
        // SPEC.md §15: an answer's head (its status line, its headers, a chunked body's size
        // lines and trailers) is bounded too, 10 MiB at most in all: a larger head is no answer.
        // One header line of 11 MiB; a chunked body of 3 000 bytes whose size lines, each within
        // its own bound, take 12 MB in all; 11 MiB of trailers; size lines and trailers past
        // 10 MiB together.
        $line = "HTTP/1.1 200 OK\r\nX-Large: " . str_repeat('a', 11 * self::MIB) . "\r\nContent-Length: 2\r\n\r\n{}";
        $chunks = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . str_repeat('1;x=' . str_repeat('a', 3996) . "\r\na\r\n", 3000) . "0\r\n\r\n";
        $trailers = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\n{}\r\n0\r\n" . str_repeat('X-T: ' . str_repeat('a', 1017) . "\r\n", 11 * 1024) . "\r\n";
        // The status line and its header, 2 620 size lines of 4 002 bytes and the last chunk's
        // line: 470 bytes short of 10 MiB; then a trailer section of $section bytes.
        $together = static fn(int $section): string => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . str_repeat('1;x=' . str_repeat('a', 3996) . "\r\na\r\n", 2620) . "0\r\nX-T: " . str_repeat('a', $section - 9) . "\r\n\r\n";
        $before = \strlen("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n") + 2620 * 4002 + 3;
        // What the client counts: all but the chunks' data and their CRLFs (3 bytes each).
        self::assertSame([10 * self::MIB - 470, $before + 470], [$before, \strlen($together(470)) - 2620 * 3]);
        foreach (['one header line' => $line, 'the size lines of a chunked body' => $chunks, 'its trailers' => $trailers, 'its size lines and trailers together' => $together(471)] as $case => $answer) {
            $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::raw($answer));
            $error = null;
            try {
                $this->get('https://api.example/', [], 30000);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
            self::assertNotNull($error, "A head of more than 10 MiB was read: {$case}");
            self::assertStringContainsString('HTTP head', $error, $case);
        }
        // Their bound is the head's: 3 000 size lines of 2 000 bytes, and chunks of a body of 1 MiB;
        // the size lines and trailers within 10 MiB together.
        $chunks = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . str_repeat('1;x=' . str_repeat('a', 1996) . "\r\na\r\n", 3000) . "0\r\n\r\n";
        $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::raw($chunks));
        self::assertSame(str_repeat('a', 3000), $this->get('https://api.example/', [], 30000)->body);
        $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::raw($together(470)));
        self::assertSame(str_repeat('a', 2620), $this->get('https://api.example/', [], 30000)->body);
    }

    public function testAChunkedBodyEndsWithItsTrailers(): void
    {
        // RFC 9112 §7.1: a chunked body ends with its trailer section and an empty line. Its
        // trailers are read (SPEC.md §15: they are of the head) and kept by no one; an answer cut
        // short before its empty line is no answer.
        $head = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\n{}\r\n0\r\n";
        foreach (['no trailer' => "\r\n", 'two trailers' => "X-T: 1\r\nX-U: 2\r\n\r\n"] as $case => $end) {
            $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::raw($head . $end));
            self::assertSame('{}', $this->get('https://api.example/')->body, $case);
        }
        foreach (['before its empty line' => 'X-T: 1\r\n', 'in a trailer' => 'X-T: 1'] as $case => $end) {
            $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::raw($head . $end));
            try {
                $this->get('https://api.example/');
                self::fail("An answer cut short {$case} was read");
            } catch (\RuntimeException $e) {
                self::assertSame('HTTP body cut short', $e->getMessage(), $case);
            }
        }
    }

    public function testTheTimeoutCoversTheWholeRequest(): void
    {
        $this->plain->on('api.example', static fn(): HttpResponseSpec => HttpResponseSpec::json([], 200, 'length', 400));
        $start = Loop::now();
        try {
            $this->get('https://api.example/', [], 200);
            self::fail('No timeout');
        } catch (\RuntimeException) {
            self::assertLessThan(TestTime::seconds(0.39), Loop::now() - $start);
        }
        $this->plain->on('api.example', static fn(): HttpResponseSpec => new HttpResponseSpec(silent: true));
        $this->expectException(\RuntimeException::class);
        $this->get('https://api.example/', [], 150);
    }

    public function testAnAnswerCutShortIsNoAnswer(): void
    {
        $this->plain->on('api.example', static fn(): HttpResponseSpec => new HttpResponseSpec(hangUp: true));
        $this->expectException(\RuntimeException::class);
        $this->get('https://api.example/');
    }

    public function testTlsIsVerifiedForTheHost(): void
    {
        $this->tls->on('secure.example', static fn(): HttpResponseSpec => HttpResponseSpec::json(['ok' => true]));
        $this->tls->on('api.example:8443', static fn(): HttpResponseSpec => HttpResponseSpec::json(['port' => 8443]));
        // The certificate names api.example and other.example: not secure.example.
        try {
            $this->get('https://secure.example/');
            self::fail('A certificate for another host was taken');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('TLS', $e->getMessage());
        }
        self::assertSame('{"port":8443}', $this->get('https://api.example:8443/')->body);
        // Without the test authority, the server's certificate is no one's.
        $untrusting = new TestConnector(null);
        $untrusting->route('api.example', 8443, $this->tls->server);
        $client = new StreamHttpClient(new class ($this->tls->server->port) implements \Sattleme\Checkout\Net\Connector {
            public function __construct(private readonly int $port) {}

            public function connect(string $host, int $port, int $timeoutMs): mixed
            {
                return \Sattleme\Checkout\Net\TlsConnector::open('127.0.0.1', $this->port, $timeoutMs, ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'allow_self_signed' => false]);
            }
        });
        try {
            Loop::get()->run(static fn(): HttpResponse => $client->get('https://api.example:8443/', [], TestTime::ms(2000)));
            self::fail('An unknown authority was trusted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('TLS', $e->getMessage());
        }
    }

    public function testAHostWithoutServerFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->get('https://nowhere.example/');
    }

    public function testOnlyHttpsUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->get('http://api.example/');
    }

    /** @return array<string, array{0: string}> */
    public static function refusedUrls(): array
    {
        return [
            'http to a public name' => ['http://node.example/api/blocks/tip/height'],
            'http to a public IPv4 address' => ['http://8.8.8.8/api/blocks/tip/height'],
            'http to the machine itself' => ['http://127.0.0.1:3006/api/blocks/tip/height'],
            'https to the machine itself' => ['https://127.0.0.1/api/blocks/tip/height'],
            'http to localhost' => ['http://localhost:3006/api/blocks/tip/height'],
            'https to this network' => ['https://0.0.0.0/api'],
            'http to a local address with a user' => ['http://user@192.168.1.10:3006/api'],
            'http to an IPv6 address' => ['http://[fd00::1]:3006/api'],
            'http to a host in upper case' => ['http://UMBREL.local/api'],
            'ws, not http' => ['ws://192.168.1.10:3006/api'],
        ];
    }

    /**
     * SPEC.md §15, §31: http only to a merchant's Bitcoin node on a local network, an IP address
     * only as a node URL's host; every other URL refused, before any connection.
     */
    #[DataProvider('refusedUrls')]
    public function testHttpAndIpAddressesOnlyForALocalNode(string $url): void
    {
        try {
            $this->get($url);
            self::fail('Refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->connector->asked, 'no connection');
    }

    public function testALocalNodeIsAskedOverHttp(): void
    {
        // The node URL's own rule (SPEC.md §31), every other rule of §15: the request as for an
        // https URL, a redirect returned, never followed.
        $this->connector->route('192.168.1.10', 3006, $this->plain->server);
        $this->connector->route('umbrel.local', 80, $this->plain->server);
        $this->plain->on('192.168.1.10:3006', static fn(HttpRequest $r): HttpResponseSpec => HttpResponseSpec::json(['target' => $r->target, 'agent' => $r->headers['user-agent'] ?? null]));
        $this->plain->on('umbrel.local', static fn(HttpRequest $r): HttpResponseSpec => new HttpResponseSpec(302, '', 'length', ['Location' => 'https://mempool.space/api']));
        $response = $this->get('http://192.168.1.10:3006/api/address/bc1qx/txs?a=b', ['User-Agent' => 'sattleme-Checkout/1']);
        self::assertSame(200, $response->status);
        self::assertSame(['target' => '/api/address/bc1qx/txs?a=b', 'agent' => 'sattleme-Checkout/1'], json_decode($response->body, true));
        self::assertSame(302, $this->get('http://umbrel.local/api/blocks/tip/height')->status);
        self::assertSame(['192.168.1.10:3006', 'umbrel.local:80'], $this->connector->askedPlain);
    }

    public function testAConnectorWithoutPlainConnectionsReachesNoLocalNode(): void
    {
        $connector = $this->connector;
        $tlsOnly = new class ($connector) implements Connector {
            public function __construct(private readonly Connector $connector) {}

            public function connect(string $host, int $port, int $timeoutMs): mixed
            {
                return $this->connector->connect($host, $port, $timeoutMs);
            }
        };
        $client = new StreamHttpClient($tlsOnly);
        try {
            Loop::get()->run(static fn(): HttpResponse => $client->get('http://192.168.1.10:3006/api/blocks/tip/height', [], 1000));
            self::fail('No connection without TLS');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no connection without TLS', $e->getMessage());
        }
        self::assertSame([], $this->connector->asked);
    }

    /**
     * @param array<string, string> $headers
     * @param int                   $timeoutMs scaled (TestTime)
     */
    private function get(string $url, array $headers = [], int $timeoutMs = 2000): HttpResponse
    {
        $client = new StreamHttpClient($this->connector);
        $timeoutMs = TestTime::ms($timeoutMs);

        return Loop::get()->run(static fn(): HttpResponse => $client->get($url, $headers, $timeoutMs));
    }
}
