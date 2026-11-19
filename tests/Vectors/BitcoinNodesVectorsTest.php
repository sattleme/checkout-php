<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Channel;
use Sattleme\Checkout\Internal\Checkout\CheckoutLink;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\LightningAddress;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Onchain\BitcoinNode;
use Sattleme\Checkout\Internal\Onchain\Esplora;
use Sattleme\Checkout\Internal\Onchain\Explorer;
use Sattleme\Checkout\Internal\Onchain\LiveSource;
use Sattleme\Checkout\Internal\Onchain\NodeFailures;
use Sattleme\Checkout\Internal\Onchain\NodeUrl;
use Sattleme\Checkout\Internal\Onchain\Watch;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\RelayList;
use Sattleme\Checkout\Internal\Url;
use Sattleme\Checkout\Tests\Support\Net\FakeHttpServer;
use Sattleme\Checkout\Tests\Support\Net\ServerWebSocket;
use Sattleme\Checkout\Tests\Support\Net\TestCertificates;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\Vectors as V;
use Sattleme\Checkout\Tests\Support\World;

/**
 * The merchant's Bitcoin node (vectors/bitcoin-nodes.json, SPEC.md §31, "The merchant's Bitcoin
 * node"), through the package's own functions (PORTING.md, Steps, 4): its `urls` through the check
 * of a node URL (NodeUrl) and the URLs the node is read at, its `channels` through the reading of
 * the channel (Channel::validate()), its `reads` through the call's explorer (Explorer: which
 * explorers a read asks, in what order, and whose answer it gives; a REST read, the two reads of
 * a check's step 1, and a WebSocket's connection while waitForPayment waits), its `writes`
 * through the making of a checkout link's record (CheckoutLink::valueV1(), valueV2() with
 * BitcoinNode::forRecord()), and its `records` through its reading, as a page reads it
 * (CheckoutLink::read()).
 */
final class BitcoinNodesVectorsTest extends TestCase
{
    /** A node on the merchant's network, as the reads' cases have it. */
    public const NODE = 'http://192.168.1.10:3006';

    private const FILE = 'vectors/bitcoin-nodes.json';

    private const NOW = 1791378000;

    private const ADDRESS = 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4';

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function urls(): array
    {
        return V::named(V::l(V::object(self::FILE)['urls']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('urls')]
    public function testANodeUrl(array $case): void
    {
        $url = NodeUrl::parse($case['url']);
        self::assertSame($case['expected'], $url === null ? null : ['rest' => $url->rest, 'webSocket' => $url->webSocket]);
        if ($url !== null) {
            self::assertSame($case['url'], $url->url);
            // The default HTTP client asks it (StreamHttpClient), without TLS for http alone.
            $request = NodeUrl::request($url->rest . '/blocks/tip/height');
            self::assertNotNull($request);
            self::assertSame(str_starts_with($url->url, 'https://'), $request['tls']);
            self::assertStringEndsWith('/api/blocks/tip/height', $request['target']);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function channels(): array
    {
        return V::named(V::l(V::object(self::FILE)['channels']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('channels')]
    public function testTheChannelsBitcoinNode(array $case): void
    {
        $index = array_search($case['name'], array_column(V::l(V::object(self::FILE)['channels']), 'name'), true);
        \assert(\is_int($index));
        $raw = V::json(self::FILE, ['channels', $index]);
        \assert($raw instanceof \stdClass);
        $channel = self::channelWith(Json::has($raw, 'value'), Json::get($raw, 'value'));
        // Valid either way (channelWith checks it): a node invalid in any way is none (SPEC.md §14).
        $node = $channel->bitcoinNode;
        self::assertSame($case['expected'], $node === null ? null : ['url' => $node->url->url, 'fallback' => $node->fallback, 'payers' => $node->payers]);
        self::assertEquals($node, BitcoinNode::of(Json::get($raw, 'value')));
    }

    public function testEveryOtherUrlKeepsItsOwnRule(): void
    {
        // SPEC.md §15, §9, §30: the node URLs only the node's rule allows (http, or an IPv4 host)
        // are no https URL, no checkout origin, no relay; an IPv4 address is never a relay's host
        // nor a Lightning address's.
        $nodeOnly = [];
        foreach (V::l(V::object(self::FILE)['urls']) as $case) {
            $case = V::a($case);
            $url = V::s($case['url']);
            if ($case['expected'] !== null && (str_starts_with($url, 'http://') || preg_match('~^https://[0-9.]+(?::|/|$)~D', $url) === 1)) {
                $nodeOnly[] = $url;
            }
        }
        self::assertGreaterThan(15, \count($nodeOnly));
        foreach ($nodeOnly as $url) {
            self::assertFalse(Url::isHttps($url), $url);
            self::assertNull(Url::request($url), $url);
            self::assertNull(Channel::checkoutOrigin($url), $url);
            self::assertFalse(RelayList::isRelayUrl('ws' . substr($url, 4)), $url);
            self::assertSame(1, preg_match('~^https?://([^/:]+)~', $url, $host));
            if (preg_match('/^[0-9.]+$/D', $host[1]) === 1) {
                self::assertFalse(RelayList::isRelayUrl('wss://' . $host[1]), $url);
                self::assertNull(LightningAddress::parse('sattleme@' . $host[1]), $url);
            }
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function otherSpellings(): array
    {
        return [
            'a line end ($)' => ["http://192.168.1.10\n"],
            'a line end after a path' => ["https://node.example/mempool\n"],
            'a NUL byte' => ["https://node.example\0"],
            'digits of other scripts' => ["http://192.168.1.\u{0661}\u{0660}"],
            'a port of other digits' => ["https://node.example:\u{0663}\u{0660}\u{0660}\u{0666}"],
            'full-width digits' => ["http://\u{FF11}\u{FF19}\u{FF12}.168.1.10"],
            'a host in upper case' => ['https://NODE.example'],
            'a port with a sign' => ['https://node.example:+80'],
            'a tab' => ["https://node.example\t"],
            'null' => [null],
            'an array' => [['https://node.example']],
            'a number' => [1],
            'one character too many' => ['https://node.example/' . str_repeat('p', 180)],
        ];
    }

    #[DataProvider('otherSpellings')]
    public function testNoOtherSpellingIsANodeUrl(mixed $value): void
    {
        self::assertNull(NodeUrl::parse($value));
    }

    public function testANodeUrlOf200CharactersIsOne(): void
    {
        $url = 'https://node.example/' . str_repeat('p', 179);
        self::assertSame(200, \strlen($url));
        self::assertNotNull(NodeUrl::parse($url));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function writes(): array
    {
        return V::named(V::l(V::object(self::FILE)['writes']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('writes')]
    public function testACheckoutLinksRecord(array $case): void
    {
        // The channel createInvoice read, then the record of its checkout link.
        $index = array_search($case['name'], array_column(V::l(V::object(self::FILE)['writes']), 'name'), true);
        \assert(\is_int($index));
        $raw = V::json(self::FILE, ['writes', $index]);
        \assert($raw instanceof \stdClass);
        $channel = self::channelWith(Json::has($raw, 'channel'), Json::get($raw, 'channel'));
        $node = BitcoinNode::forRecord($channel->bitcoinNode);
        $app = V::object('vectors/app/app.json');
        $value = $case['address'] === true
            ? CheckoutLink::valueV2('Sunrise Bakery', null, null, self::ADDRESS, 150000000, 1791381280, 1791382180, null, 'Order 1042', null, null, $node)
            : CheckoutLink::valueV1('Sunrise Bakery', V::s(V::a($app['realInvoice'])['invoice']), 'https://breez.tips/lnurlp/verify/x', null, 'Order 1042', null);
        self::assertSame($case['address'] === true ? 2 : 1, $value['version']);
        // Absent, never null, when it has none.
        self::assertSame($case['expected'] !== null, \array_key_exists('bitcoinNode', $value));
        self::assertSame($case['expected'], $value['bitcoinNode'] ?? null);
        // The page reads it back as written.
        $read = CheckoutLink::read(Json::decode(Json::encode($value)));
        self::assertNotNull($read);
        self::assertSame('ok', $read[0]);
        self::assertSame($case['expected'], $read[1]['bitcoinNode']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function records(): array
    {
        return V::named(V::l(V::object(self::FILE)['records']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('records')]
    public function testAPageReadsTheNodeOfARecord(array $case): void
    {
        $index = array_search($case['name'], array_column(V::l(V::object(self::FILE)['records']), 'name'), true);
        \assert(\is_int($index));
        $read = CheckoutLink::read(V::json(self::FILE, ['records', $index, 'value']));
        // Valid in every case: a node set aside leaves the record valid.
        self::assertNotNull($read);
        self::assertSame('ok', $read[0]);
        self::assertSame($case['expected'], $read[1]['bitcoinNode']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function reads(): array
    {
        return V::named(V::l(V::object(self::FILE)['reads']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('reads')]
    public function testTheExplorersAReadAsksFirst(array $case): void
    {
        // Every explorer a read may ask; the read stops at the first that answers.
        $node = $case['node'] === null ? null : V::a($case['node']);
        $order = Explorer::toAsk($node === null ? null : self::fallback($node['fallback']), $node !== null && $node['failedRecently'] === true);
        $asked = V::l(V::a($case['expected'])['asked']);
        self::assertSame($asked, \array_slice($order, 0, \count($asked)));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('reads')]
    public function testARestRead(array $case): void
    {
        $asked = [];
        $explorer = self::explorerOf($case);
        $result = Loop::get()->run(static function () use ($explorer, $case, &$asked): string {
            try {
                return $explorer->read(static function (Esplora $esplora) use ($case, &$asked): string {
                    $name = self::nameOf($esplora);
                    $asked[] = $name;
                    if (!self::answers($case, $name)) {
                        throw new NetworkException("{$name} did not answer");
                    }

                    return $name;
                });
            } catch (NetworkException) {
                return 'network';
            }
        });
        self::assertSame(V::a($case['expected']), ['asked' => $asked, 'result' => $result]);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function stepOneReads(): iterable
    {
        foreach (self::reads() as $name => [$case]) {
            foreach (['transactions', 'height'] as $failing) {
                yield "{$name}, its {$failing} failing" => [$case, $failing];
            }
        }
    }

    /**
     * The address's transactions and the chain's height, of one explorer (LiveSource::explorer()):
     * when either fails, both are asked of the next one, never one from each.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('stepOneReads')]
    public function testTheTwoReadsOfACheckAreOneRead(array $case, string $failing): void
    {
        /** @var \ArrayObject<int, string> $heard */
        $heard = new \ArrayObject();
        $http = new class ($case, $failing, $heard) implements HttpClient {
            /**
             * @param array<string, mixed>      $case
             * @param \ArrayObject<int, string> $asked
             */
            public function __construct(private readonly array $case, private readonly string $failing, private readonly \ArrayObject $asked) {}

            public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
            {
                $name = str_starts_with($url, BitcoinNodesVectorsTest::NODE . '/api/') ? Explorer::NODE : Explorer::MEMPOOL;
                $what = str_ends_with($url, '/blocks/tip/height') ? 'height' : 'transactions';
                $this->asked->append("{$name} {$what}");
                if ($what === $this->failing && !($name === Explorer::NODE ? V::a($this->case['node'])['answers'] === true : $this->case['mempoolAnswers'] === true)) {
                    return new HttpResponse(503, 'down');
                }

                return $what === 'height' ? new HttpResponse(200, $name === Explorer::NODE ? '970001' : '970002') : new HttpResponse(200, '[]');
            }
        };
        $context = TestContext::create(new TestConnector(), self::NOW, $http);
        $source = new LiveSource($context, new RelayPool($context), IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']), [], self::explorerOf($case, $http));
        $result = Loop::get()->run(static function () use ($source): string {
            try {
                return match ($source->explorer(self::ADDRESS)[1]) {
                    970001 => Explorer::NODE,
                    970002 => Explorer::MEMPOOL,
                    default => 'another',
                };
            } catch (NetworkException) {
                return 'network';
            }
        });
        $asked = $heard->getArrayCopy();
        $expected = V::a($case['expected']);
        self::assertSame($expected['result'], $result);
        $each = [];
        foreach (V::l($expected['asked']) as $name) {
            $name = V::s($name);
            $pair = ["{$name} transactions", "{$name} height"];
            $each[] = $pair;
        }
        // The two requests of one explorer at once, in either order; the next explorer's after.
        self::assertCount(2 * \count($each), $asked);
        foreach ($each as $index => $pair) {
            $got = \array_slice($asked, 2 * $index, 2);
            sort($got);
            sort($pair);
            self::assertSame($pair, $got);
        }
    }

    /**
     * A WebSocket's connection (Watch): one that fails, or is lost before any message, goes to the
     * next explorer at once; every one failing, the watch waits before it connects again
     * (`network`). Against local servers of mempool.space and of a node of the Internet: one
     * that answers sends a message on each connection, one that does not refuses it.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('reads')]
    public function testAWebSocketsConnection(array $case): void
    {
        $certificates = new TestCertificates(['mempool.space', 'node.example']);
        $server = new FakeHttpServer(true, $certificates);
        $connector = new TestConnector($certificates);
        $connected = [];
        foreach (['mempool.space' => Explorer::MEMPOOL, 'node.example' => Explorer::NODE] as $host => $name) {
            if (($name === Explorer::MEMPOOL || $case['node'] !== null) && self::answers($case, $name)) {
                $connector->route($host, 443, $server->server);
            }
            $server->onWebSocket($host, static function (ServerWebSocket $socket) use (&$connected, $name): void {
                $connected[] = $name;
                $socket->send('{"block":{"height":970000}}');
                while ($socket->receive() !== null) {
                    // Held open.
                }
            });
        }
        $expected = V::a($case['expected']);
        try {
            $context = TestContext::create($connector, self::NOW);
            $watch = new Watch($context, self::ADDRESS);
            $explorer = self::explorerOf($case, null, $context, World::INTERNET_NODE);
            Loop::get()->run(static function () use ($watch, $explorer, $connector, $expected, &$connected): void {
                $watch->follow($explorer);
                // Until one is connected, or every one asked failed (the watch then waits 1 s).
                $deadline = TestTime::deadline(5);
                while ($connected === [] && \count($connector->asked) < \count(V::l($expected['asked'])) && Loop::now() < $deadline) {
                    Loop::get()->sleepUntil(Loop::now() + 0.01);
                }
                Loop::get()->sleepUntil(Loop::now() + 0.3); // Not one more at once (under the SDK's own 1 s).
                $watch->stop();
            });
        } finally {
            $server->stop();
            $certificates->remove();
        }
        $asked = array_map(static fn(string $hostPort): string => $hostPort === 'node.example:443' ? Explorer::NODE : Explorer::MEMPOOL, $connector->asked);
        self::assertSame($expected, ['asked' => $asked, 'result' => $connected === [] ? 'network' : $connected[0]]);
        self::assertLessThanOrEqual(1, \count($connected));
    }

    public function testANodeThatFailedIsAskedAfterMempoolSpaceFor60Seconds(): void
    {
        // Less than 60 s after the node failed a read, by the call's clock, mempool.space first;
        // from 60 s on, the node first again; a failure of mempool.space alone changes nothing.
        $now = self::NOW;
        $failures = new NodeFailures();
        $context = TestContext::create(new TestConnector(), static function () use (&$now): int {
            return $now;
        });
        $explorer = new Explorer($context, self::node(BitcoinNode::MEMPOOL), $failures);
        self::assertSame([Explorer::NODE, Explorer::MEMPOOL], array_column($explorer->sources(), 'name'));
        $explorer->nodeFailed();
        self::assertTrue($failures->recently(self::NODE, self::NOW));
        $now = self::NOW + 59;
        self::assertSame([Explorer::MEMPOOL, Explorer::NODE], array_column($explorer->sources(), 'name'));
        // Another explorer of the call (another channel read) shares its failures.
        self::assertSame([Explorer::MEMPOOL, Explorer::NODE], array_column((new Explorer($context, self::node(BitcoinNode::MEMPOOL), $failures))->sources(), 'name'));
        self::assertSame([Explorer::NODE, Explorer::MEMPOOL], array_column((new Explorer($context, self::node(BitcoinNode::MEMPOOL), new NodeFailures()))->sources(), 'name'), 'another call');
        $now = self::NOW + 60;
        self::assertSame([Explorer::NODE, Explorer::MEMPOOL], array_column($explorer->sources(), 'name'));
        $read = Loop::get()->run(static fn(): string => $explorer->read(static function (Esplora $esplora): string {
            if (self::nameOf($esplora) === Explorer::MEMPOOL) {
                throw new NetworkException('mempool.space did not answer');
            }

            return $esplora->base;
        }));
        self::assertSame(self::NODE . '/api', $read);
        $now = self::NOW + 61;
        self::assertSame([Explorer::NODE, Explorer::MEMPOOL], array_column($explorer->sources(), 'name'), 'the node did not fail again');
    }

    /**
     * The explorer of a case of `reads`: its node failed 1 s inside the 60 s when `failedRecently`.
     *
     * @param array<string, mixed> $case
     */
    private static function explorerOf(array $case, ?HttpClient $http = null, ?Context $context = null, string $url = self::NODE): Explorer
    {
        $failures = new NodeFailures();
        $node = $case['node'] === null ? null : V::a($case['node']);
        if ($node !== null && $node['failedRecently'] === true) {
            $failures->failed($url, self::NOW - Protocol::NODE_FAILED_RECENTLY_SECONDS + 1);
        }

        return new Explorer($context ?? TestContext::create(new TestConnector(), self::NOW, $http), $node === null ? null : self::node(self::fallback($node['fallback']), $url), $failures);
    }

    /** @return BitcoinNode::MEMPOOL|BitcoinNode::NONE */
    private static function fallback(mixed $value): string
    {
        return match ($value) {
            BitcoinNode::MEMPOOL => BitcoinNode::MEMPOOL,
            BitcoinNode::NONE => BitcoinNode::NONE,
            default => throw new \LogicException('Not a fallback of the vectors'),
        };
    }

    private static function node(string $fallback, string $url = self::NODE): BitcoinNode
    {
        $node = BitcoinNode::of(Json::decode(Json::encode(['url' => $url, 'fallback' => $fallback, 'payers' => false])));
        self::assertNotNull($node);

        return $node;
    }

    /** @return Explorer::NODE|Explorer::MEMPOOL */
    private static function nameOf(Esplora $esplora): string
    {
        return match ($esplora->base) {
            self::NODE . '/api' => Explorer::NODE,
            Protocol::MEMPOOL => Explorer::MEMPOOL,
            default => throw new \LogicException("Not an explorer of the case: {$esplora->base}"),
        };
    }

    /** @param array<string, mixed> $case */
    private static function answers(array $case, string $name): bool
    {
        return $name === Explorer::NODE ? V::a($case['node'])['answers'] === true : $case['mempoolAnswers'] === true;
    }

    /**
     * The app's channel, valid, with `bitcoinNode` $value ($present false: without it).
     */
    private static function channelWith(bool $present, mixed $value): Channel
    {
        $channel = V::json('vectors/app/app.json', ['channel', 'value', 'v']);
        \assert($channel instanceof \stdClass);
        unset($channel->bitcoinNode);
        if ($present) {
            $channel->bitcoinNode = $value;
        }
        $event = Event::fromJson(V::json('vectors/app/app.json', ['channel', 'event']));
        self::assertNotNull($event);
        $read = Channel::validate($channel, $event);
        self::assertNotNull($read);
        self::assertSame('ok', $read[0]);

        return $read[1];
    }
}
