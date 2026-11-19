<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Checkout\CheckoutLink;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Net\HttpResponseSpec;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * The merchant's Bitcoin node (SPEC.md §31, "The merchant's Bitcoin node"), end to end against
 * local relays and fakes of the Lightning address, of mempool.space and of a node (PORTING.md,
 * Steps, 5): every read of an address and of the chain's height asked of the node, and never of
 * mempool.space while it answers; its `fallback` when it does not; the 60 s after a failure; a
 * check's two reads of one explorer; an invalid `bitcoinNode` read as none; waitForPayment's
 * WebSocket, the node's, with the same fallback and 60 s, following the channel the last check read;
 * the node in a checkout link's record with `payers` true (SPEC.md §30).
 */
final class BitcoinNodeTest extends TestCase
{
    /** @var array{url: string, fallback: string, payers: bool} */
    private const ONCE = ['url' => World::INTERNET_NODE, 'fallback' => 'mempool', 'payers' => false];

    /** @var array{url: string, fallback: string, payers: bool} */
    private const NEVER_MEMPOOL = ['url' => World::INTERNET_NODE, 'fallback' => 'none', 'payers' => false];

    private World $world;

    protected function setUp(): void
    {
        $this->world = new World();
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    public function testANodeThatAnswersIsTheOnlyExplorerAsked(): void
    {
        // The lease and every check: the node, with the User-Agent of every request; paid at 3
        // confirmations on it.
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'N-1', 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        // The lease: whether the address was paid already, of the node.
        self::assertSame(["node address/{$address}/txs"], $this->explorersAsked());
        $txid = $this->pay($address, 49000);
        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($invoice)->state);
        $this->world->transactions[$address][0]['status'] = ['confirmed' => true, 'block_height' => $this->world->height - 2];
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
        $asked = $this->explorersAsked();
        self::assertSame([], array_filter($asked, static fn(string $read): bool => str_starts_with($read, 'mempool')), 'mempool.space never asked');
        self::assertCount(2, array_keys($asked, 'node blocks/tip/height', true));
        $node = $this->world->http->requestsTo('node.example');
        self::assertNotSame([], $node);
        foreach ($node as $request) {
            self::assertSame(Protocol::USER_AGENT, $request->headers['user-agent'] ?? null);
            self::assertSame('GET', $request->method);
        }
    }

    public function testARedirectOfTheNodeIsAFailureAndMempoolSpaceIsAsked(): void
    {
        // SPEC.md §15: no redirect is followed; a redirect is a failure of the node.
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'N-2', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        $since = \count($this->world->http->requests);
        $this->world->nodeAnswer = static fn(HttpRequest $request): HttpResponseSpec => new HttpResponseSpec(302, '', 'length', ['Location' => 'https://mempool.space/api' . substr($request->path(), \strlen('/mempool/api'))]);
        self::assertSame(PaymentStatus::OPEN, $client->getStatus($invoice)->state);
        $asked = $this->explorersAsked($since);
        sort($asked);
        self::assertSame(["mempool address/{$address}/txs", 'mempool blocks/tip/height', "node address/{$address}/txs", 'node blocks/tip/height'], $asked);
    }

    public function testFallbackMempoolReadsMempoolSpaceAndNetworkWhenBothFail(): void
    {
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $address = World::address();
        $this->world->lot([$address]);
        // A server outside the merchant's network: no connection to the node.
        $this->world->connector->forget('node.example', 443);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'F-1', 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        self::assertSame(["mempool address/{$address}/txs"], $this->explorersAsked());
        $txid = $this->pay($address, 49000, $this->world->height - 2);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
        self::assertContains('node.example:443', $this->world->connector->asked);
        $this->world->explorerDown = true;
        try {
            $client->getStatus($invoice);
            self::fail('Neither explorer answers');
        } catch (NetworkException $e) {
            self::assertStringContainsString('Neither the merchant\'s Bitcoin node nor mempool.space answered', $e->getMessage());
        }
    }

    public function testANodeThatFailedIsAskedAfterMempoolSpaceFor60SecondsInTheCall(): void
    {
        // Two addresses, the first paid already (skipped), the second leased: the node fails the
        // first's read, so the second's is asked of mempool.space first (SPEC.md §31, `fallback`).
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $first = World::address();
        $second = World::address();
        $this->world->lot([$first, $second]);
        $this->world->nodeDown = true;
        $this->pay($first, 1000, 1);
        // The shuffle's draws keep the lot's order: the paid one first.
        $client = $this->world->client(['random' => static fn(int $bytes): string => $bytes === 2 ? "\x00\x01" : random_bytes(max(1, $bytes))]);
        $invoice = $client->createInvoice(['ref' => 'F-2', 'sats' => '49000']);
        self::assertSame($second, $invoice->onchainAddress);
        self::assertSame(["node address/{$first}/txs", "mempool address/{$first}/txs", "mempool address/{$second}/txs"], $this->explorersAsked());
        // Another call: the node first again.
        $since = \count($this->world->http->requests);
        $client->getStatus($invoice);
        $asked = \array_slice($this->explorersAsked($since), 0, 2);
        sort($asked);
        self::assertSame(["node address/{$second}/txs", 'node blocks/tip/height'], $asked);
    }

    public function testACheckReadsTheTransactionsAndTheHeightOfOneExplorer(): void
    {
        // The node gives the address's transactions and fails the chain's height: both are asked
        // of mempool.space, never one from each.
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'O-1', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        $txid = $this->pay($address, 49000, $this->world->height - 2);
        // The node's chain is 2 blocks behind, so that a height read of it would leave the payment
        // unconfirmed.
        $this->world->nodeAnswer = static fn(HttpRequest $request): ?HttpResponseSpec => str_ends_with($request->path(), '/blocks/tip/height') ? new HttpResponseSpec(500, 'down') : null;
        $since = \count($this->world->http->requests);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
        $asked = $this->explorersAsked($since);
        sort($asked);
        self::assertSame(["mempool address/{$address}/txs", 'mempool blocks/tip/height', "node address/{$address}/txs", 'node blocks/tip/height'], $asked);
    }

    public function testFallbackNoneLeasesNoAddressWhileTheNodeIsDown(): void
    {
        // `network` for an order paid on-chain only, a Lightning invoice alone otherwise;
        // mempool.space never asked (SPEC.md §31).
        $this->world->channel(['bitcoinNode' => self::NEVER_MEMPOOL]);
        $this->world->lot([World::address()]);
        $this->world->nodeDown = true;
        $this->world->payRequest['maxSendable'] = 1000000;
        try {
            $this->world->client()->createInvoice(['ref' => 'X-1', 'sats' => '49000']);
            self::fail('No address, no Lightning');
        } catch (NetworkException) {
        }
        $this->world->payRequest['maxSendable'] = 4000000000;
        $invoice = $this->world->client()->createInvoice(['ref' => 'X-2', 'sats' => '49000']);
        self::assertNull($invoice->onchainAddress);
        self::assertNotNull($invoice->bolt11);
        $asked = $this->explorersAsked();
        self::assertNotSame([], $asked);
        self::assertSame([], array_filter($asked, static fn(string $read): bool => str_starts_with($read, 'mempool')), 'mempool.space never asked');
    }

    public function testFallbackNoneIsNetworkNeverExpiredThenPaidOnceTheNodeAnswers(): void
    {
        $this->world->channel(['bitcoinNode' => self::NEVER_MEMPOOL]);
        $this->world->lot([World::address()]);
        $this->world->payRequest['maxSendable'] = 1000000;
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'X-3', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        self::assertNotSame('', $address);
        $this->world->nodeDown = true;
        // Paid while the lease ran, which no check saw: BlockCypher tells it in time.
        $txid = $this->pay($address, 49000, $this->world->height - 5);
        $this->world->received[$txid] = World::rfc3339(($invoice->createdAt + 60) * 1000);
        $this->world->now = $invoice->expiresAt + 3600;
        foreach ([1, 2] as $day) {
            try {
                $client->getStatus($invoice);
                self::fail("Not expired, day {$day}");
            } catch (NetworkException) {
            }
            try {
                // A new invoice of the order: refused while its earlier one cannot be told.
                $client->createInvoice(['ref' => 'X-3', 'sats' => '49000']);
                self::fail("No new invoice, day {$day}");
            } catch (NetworkException) {
            }
            $this->world->now += 86400;
        }
        self::assertSame([], array_filter($this->explorersAsked(), static fn(string $read): bool => str_starts_with($read, 'mempool')), 'mempool.space never asked');
        $this->world->nodeDown = false;
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidNodes(): array
    {
        return [
            'http to a public host' => [['url' => 'http://node.example', 'fallback' => 'mempool', 'payers' => false]],
            'a fallback of another spelling' => [['url' => World::INTERNET_NODE, 'fallback' => 'Mempool', 'payers' => false]],
            'payers absent' => [['url' => World::INTERNET_NODE, 'fallback' => 'none']],
            'payers not a boolean' => [['url' => World::INTERNET_NODE, 'fallback' => 'none', 'payers' => 'true']],
            'payers true for a local node' => [['url' => 'https://umbrel.local', 'fallback' => 'none', 'payers' => true]],
            'its API\'s URL' => [['url' => World::INTERNET_NODE . '/api', 'fallback' => 'none', 'payers' => false]],
            'null' => [null],
            'an array' => [[self::NEVER_MEMPOOL]],
        ];
    }

    #[DataProvider('invalidNodes')]
    public function testAnInvalidBitcoinNodeReadsMempoolSpace(mixed $node): void
    {
        // The channel still valid; the node never asked.
        $this->world->channel(['bitcoinNode' => $node]);
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'I-1', 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        self::assertSame(PaymentStatus::OPEN, $client->getStatus($invoice)->state);
        $asked = $this->explorersAsked();
        sort($asked);
        self::assertSame(["mempool address/{$address}/txs", "mempool address/{$address}/txs", 'mempool blocks/tip/height'], $asked);
        self::assertSame([], $this->world->http->requestsTo('node.example'));
        self::assertNotContains('node.example:443', $this->world->connector->asked);
        self::assertNotContains('node.example:80', $this->world->connector->asked);
    }

    public function testAWaitWatchesTheNodesWebSocketNeverMempoolSpaces(): void
    {
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'W-1', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        $start = Loop::now();
        // The SDK's longest interval, not scaled (TestTime): no periodic check within the test's 30 s,
        // only the WebSockets wake the wait. Likewise in the tests below.
        $status = $client->waitForPayment($invoice, ['intervalMs' => Protocol::MAX_INTERVAL_MS, 'onStatus' => function (PaymentStatus $status) use ($address): void {
            if ($status->state !== PaymentStatus::OPEN || $this->world->transactions !== []) {
                return;
            }
            self::until(fn(): bool => \count($this->world->nodeSent) === 2);
            self::assertSame([Json::encode(['action' => 'want', 'data' => ['blocks']]), Json::encode(['track-address' => $address])], $this->world->nodeSent);
            $txid = $this->pay($address, 49000, $this->world->height - 2);
            $this->world->push(['address-transactions' => [['txid' => $txid]]], true);
        }], new TimeoutCancellation(30));
        self::assertSame([PaymentStatus::PAID, 'onchain'], [$status->state, $status->method]);
        // Not scaled: under the SDK's own 60 s, which no scale changes.
        self::assertLessThan(25, Loop::now() - $start, 'woken by the node\'s WebSocket');
        self::assertSame([], $this->world->sockets, 'mempool.space\'s WebSocket never opened');
        self::assertSame([], array_filter($this->explorersAsked(), static fn(string $read): bool => str_starts_with($read, 'mempool')), 'mempool.space never asked');
    }

    /** @return array<string, array{0: string}> */
    public static function webSocketFailures(): array
    {
        return ['refused' => ['refused'], 'lost before any message' => ['dropped']];
    }

    #[DataProvider('webSocketFailures')]
    public function testFallbackMempoolWatchesMempoolSpacesWebSocketWhenTheNodesFails(string $failure): void
    {
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'W-2', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        if ($failure === 'refused') {
            $this->world->connector->forget('node.example', 443);
        } else {
            // Accepted, then closed: the node's REST reads still answer.
            $this->world->nodeSocketDrops = true;
        }
        $status = $client->waitForPayment($invoice, ['intervalMs' => Protocol::MAX_INTERVAL_MS, 'onStatus' => function (PaymentStatus $status) use ($address, $failure): void {
            if ($status->state !== PaymentStatus::OPEN || $this->world->transactions !== []) {
                return;
            }
            // The node's WebSocket fails: mempool.space's at once.
            self::until(fn(): bool => $this->world->sockets !== []);
            if ($failure === 'dropped') {
                self::assertCount(1, $this->world->nodeSockets);
            }
            self::until(fn(): bool => $this->world->sockets[0]->path === '/api/v1/ws');
            $txid = $this->pay($address, 49000, $this->world->height - 2);
            Loop::get()->sleepUntil(TestTime::deadline(0.2)); // Its messages sent.
            $this->world->push(['address-transactions' => [['txid' => $txid]]]);
        }], new TimeoutCancellation(30));
        self::assertSame([PaymentStatus::PAID, 'onchain'], [$status->state, $status->method]);
    }

    public function testFallbackNoneWatchesTheNodesWebSocketAloneAgainWhileItFails(): void
    {
        $this->world->channel(['bitcoinNode' => self::NEVER_MEMPOOL]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'W-3', 'sats' => '49000']);
        $this->world->nodeSocketDrops = true;
        try {
            // The SDK's own 1 s before it connects again, which no scale changes.
            $client->waitForPayment($invoice, ['intervalMs' => Protocol::MAX_INTERVAL_MS], new TimeoutCancellation(3.5));
            self::fail('Cancelled');
        } catch (CancelledException) {
        }
        self::assertGreaterThanOrEqual(2, \count($this->world->nodeSockets), 'connected again');
        self::assertSame([], $this->world->sockets, 'mempool.space\'s WebSocket never opened');
        self::assertSame([], array_filter($this->explorersAsked(), static fn(string $read): bool => str_starts_with($read, 'mempool')), 'mempool.space never asked');
    }

    public function testAWaitAsksTheNodeAfterMempoolSpaceWithin60SecondsOfItsFailure(): void
    {
        // In every check of one waitForPayment, and its WebSocket: one call (SPEC.md §31).
        $this->world->channel(['bitcoinNode' => self::ONCE]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'W-4', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        $this->world->nodeDown = true;
        $this->world->nodeSocketDrops = true;
        $mark = \count($this->world->http->requests);
        $asked = function () use (&$mark): array {
            $kinds = array_map(static fn(string $read): string => explode(' ', $read)[0], $this->explorersAsked($mark));
            $mark = \count($this->world->http->requests);

            return $kinds;
        };
        $heard = [];
        $status = $client->waitForPayment($invoice, ['intervalMs' => Protocol::MAX_INTERVAL_MS, 'onStatus' => function (PaymentStatus $status) use (&$heard, $asked, $address): void {
            $heard[] = $asked();
            switch (\count($heard)) {
                case 1:
                    // The first check: the node, then mempool.space. Its WebSocket: the node's
                    // lost before any message, mempool.space's connected, which starts a check.
                    break;
                case 2:
                    // mempool.space failing within the 60 s: the node after it, which answers.
                    $this->world->nodeDown = false;
                    $this->world->explorerDown = true;
                    $this->world->push(['block' => ['height' => $this->world->height]]);
                    break;
                case 3:
                    self::assertSame(PaymentStatus::OPEN, $status->state);
                    // 60 s after its failure: the node first again.
                    $this->world->now += 60;
                    $this->world->explorerDown = false;
                    $this->world->push(['block' => ['height' => $this->world->height]]);
                    break;
                case 4:
                    $txid = $this->pay($address, 49000, $this->world->height - 2);
                    $this->world->push(['address-transactions' => [['txid' => $txid]]]);
                    break;
            }
        }], new TimeoutCancellation(30));
        self::assertSame(PaymentStatus::PAID, $status->state);
        self::assertSame(['node', 'node', 'mempool', 'mempool'], $heard[0]);
        self::assertSame(['mempool', 'mempool'], $heard[1]);
        self::assertSame(['mempool', 'mempool', 'node', 'node'], $heard[2]);
        self::assertSame(['node', 'node'], $heard[3]);
        self::assertSame([], array_filter(\array_slice($heard, 4), static fn(array $kinds): bool => \in_array('mempool', $kinds, true)), 'the node answers: mempool.space not asked');
    }

    public function testAWaitsWebSocketFollowsTheNodeTheLastChannelReadNames(): void
    {
        // The merchant names a node while the wait runs: the next check reads it, and the wait
        // watches the node's WebSocket instead of mempool.space's.
        $this->world->channel();
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'W-5', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        $named = false;
        $status = $client->waitForPayment($invoice, ['intervalMs' => Protocol::MAX_INTERVAL_MS, 'onStatus' => function (PaymentStatus $status) use (&$named, $address): void {
            if ($status->state !== PaymentStatus::OPEN) {
                return;
            }
            if (!$named) {
                $named = true;
                self::until(fn(): bool => $this->world->sockets !== []);
                $this->world->channel(['bitcoinNode' => self::ONCE], null, $this->world->now - 500);
                Loop::get()->sleepUntil(TestTime::deadline(0.2)); // mempool.space's sent its messages.
                $this->world->push(['block' => ['height' => $this->world->height]]);

                return;
            }
            if ($this->world->transactions === [] && $this->world->nodeSockets !== []) {
                self::until(fn(): bool => \count($this->world->nodeSent) === 2);
                $txid = $this->pay($address, 49000, $this->world->height - 2);
                $this->world->push(['address-transactions' => [['txid' => $txid]]], true);
            }
        }], new TimeoutCancellation(30));
        self::assertSame([PaymentStatus::PAID, 'onchain'], [$status->state, $status->method]);
        self::assertCount(1, $this->world->sockets);
        self::assertCount(1, $this->world->nodeSockets);
        self::until(fn(): bool => !$this->world->sockets[0]->open);
        self::assertFalse($this->world->sockets[0]->open, 'mempool.space\'s WebSocket closed');
    }

    public function testANodeOnTheMerchantsNetworkIsAskedOverHttp(): void
    {
        // SPEC.md §31: http://192.168.1.10:3006 read at http://192.168.1.10:3006/api, every rule
        // of §15 but the https URL's (the User-Agent, no TLS): the lease and every check.
        $this->world->channel(['bitcoinNode' => ['url' => World::NODE, 'fallback' => 'none', 'payers' => false]]);
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'H-1', 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        $txid = $this->pay($address, 49000, $this->world->height - 2);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
        $node = array_map(static fn(HttpRequest $request): string => $request->path(), $this->world->plain->requestsTo('192.168.1.10:3006'));
        sort($node);
        self::assertSame(["/api/address/{$address}/txs", "/api/address/{$address}/txs", '/api/blocks/tip/height'], $node);
        foreach ($this->world->plain->requestsTo('192.168.1.10:3006') as $request) {
            self::assertSame(Protocol::USER_AGENT, $request->headers['user-agent'] ?? null);
        }
        self::assertSame(['192.168.1.10:3006', '192.168.1.10:3006', '192.168.1.10:3006'], $this->world->connector->askedPlain, 'without TLS, to it alone');
        self::assertNotContains('192.168.1.10:3006', array_diff($this->world->connector->asked, $this->world->connector->askedPlain));
        self::assertSame([], $this->explorersAsked(), 'mempool.space never asked');
    }

    public function testAWaitWatchesANodeOnTheMerchantsNetworkOverWs(): void
    {
        $this->world->channel(['bitcoinNode' => ['url' => World::NODE, 'fallback' => 'mempool', 'payers' => false]]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'H-2', 'sats' => '49000']);
        $address = (string) $invoice->onchainAddress;
        $status = $client->waitForPayment($invoice, ['intervalMs' => Protocol::MAX_INTERVAL_MS, 'onStatus' => function (PaymentStatus $status) use ($address): void {
            if ($status->state !== PaymentStatus::OPEN || $this->world->transactions !== []) {
                return;
            }
            self::until(fn(): bool => \count($this->world->nodeSent) === 2);
            $txid = $this->pay($address, 49000, $this->world->height - 2);
            $this->world->push(['address-transactions' => [['txid' => $txid]]], true);
        }], new TimeoutCancellation(30));
        self::assertSame([PaymentStatus::PAID, 'onchain'], [$status->state, $status->method]);
        self::assertSame('/api/v1/ws', $this->world->nodeSockets[0]->path);
        self::assertSame([], $this->world->sockets, 'mempool.space\'s WebSocket never opened');
    }

    public function testAConnectorWithoutConnectionsInClearLeavesANodeOnTheMerchantsNetworkToItsFallback(): void
    {
        // An integrator whose connector only makes TLS connections (SPEC.md §27: a server kept to
        // public addresses) cannot reach the node: its fallback applies.
        $connector = $this->world->connector;
        $tlsOnly = new class ($connector) implements Connector {
            public function __construct(private readonly Connector $connector) {}

            public function connect(string $host, int $port, int $timeoutMs): mixed
            {
                return $this->connector->connect($host, $port, $timeoutMs);
            }
        };
        $this->world->channel(['bitcoinNode' => ['url' => World::NODE, 'fallback' => 'mempool', 'payers' => false]]);
        $address = World::address();
        $this->world->lot([$address]);
        $invoice = $this->world->client(['connector' => $tlsOnly])->createInvoice(['ref' => 'H-3', 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        self::assertSame(["mempool address/{$address}/txs"], $this->explorersAsked());
        $this->world->channel(['bitcoinNode' => ['url' => World::NODE, 'fallback' => 'none', 'payers' => false]], null, $this->world->now - 500);
        $this->world->lot([World::address()]);
        $other = $this->world->client(['connector' => $tlsOnly])->createInvoice(['ref' => 'H-4', 'sats' => '49000']);
        self::assertNull($other->onchainAddress, 'with "none": Lightning only');
        self::assertSame([], $this->world->connector->askedPlain);
        self::assertSame([], $this->world->plain->requests);
    }

    public function testALinksRecordHoldsTheNodesUrlAndFallbackWithPayersTrue(): void
    {
        // SPEC.md §30: never `payers`, nor the channel's other members; a new link for
        // invoice_pending too.
        $this->world->channel(['bitcoinNode' => ['url' => World::INTERNET_NODE, 'fallback' => 'none', 'payers' => true, 'name' => 'Shop node']]);
        $this->world->lot([World::address()]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'L-1', 'sats' => '49000', 'checkout' => true]);
        $record = $this->recordOf((string) $invoice->checkoutUrl);
        self::assertSame([2, $invoice->onchainAddress], [$record['version'], $record['onchainAddress']]);
        self::assertSame(['url' => World::INTERNET_NODE, 'fallback' => 'none'], $record['bitcoinNode']);
        self::assertSame('bitcoinNode', array_key_last($record));
        $read = CheckoutLink::read(Json::decode(Json::encode($record)));
        self::assertSame(['url' => World::INTERNET_NODE, 'fallback' => 'none'], $read[1]['bitcoinNode'] ?? null, 'as the page reads it');
        try {
            $client->createInvoice(['ref' => 'L-1', 'sats' => '49000', 'checkout' => true]);
            self::fail('Pending');
        } catch (InvoicePendingException $e) {
            $again = (string) $e->getCheckoutUrl();
            self::assertNotSame($invoice->checkoutUrl, $again);
            self::assertSame(['url' => World::INTERNET_NODE, 'fallback' => 'none'], $this->recordOf($again)['bitcoinNode'] ?? null);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function nodesForTheServerOnly(): array
    {
        return [
            'payers false' => [['url' => World::INTERNET_NODE, 'fallback' => 'mempool', 'payers' => false]],
            'a node on the merchant\'s network' => [['url' => World::NODE, 'fallback' => 'mempool', 'payers' => false]],
            'payers true on the merchant\'s network (invalid)' => [['url' => World::NODE, 'fallback' => 'mempool', 'payers' => true]],
            'no node' => [null],
        ];
    }

    #[DataProvider('nodesForTheServerOnly')]
    public function testALinksRecordHoldsNoNodeWithoutPayers(mixed $node): void
    {
        $this->world->channel($node === null ? [] : ['bitcoinNode' => $node]);
        $this->world->lot([World::address()]);
        $invoice = $this->world->client()->createInvoice(['ref' => 'L-2', 'sats' => '49000', 'checkout' => true]);
        $record = $this->recordOf((string) $invoice->checkoutUrl);
        self::assertSame(2, $record['version']);
        self::assertArrayNotHasKey('bitcoinNode', $record);
    }

    public function testALinksRecordOfVersion1HoldsNoNode(): void
    {
        // An order without an address (below the on-chain floor): Lightning only.
        $this->world->channel(['bitcoinNode' => ['url' => World::INTERNET_NODE, 'fallback' => 'mempool', 'payers' => true]]);
        $this->world->lot([World::address()]);
        $invoice = $this->world->client()->createInvoice(['ref' => 'L-3', 'sats' => '999', 'checkout' => true]);
        self::assertNull($invoice->onchainAddress);
        $record = $this->recordOf((string) $invoice->checkoutUrl);
        self::assertSame(1, $record['version']);
        self::assertArrayNotHasKey('bitcoinNode', $record);
    }

    /**
     * The value of the record of the checkout link at $url, as its page opens it.
     *
     * @return array<string, mixed>
     */
    private function recordOf(string $url): array
    {
        $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
        self::assertSame('link', $fragment[0]);
        $record = null;
        foreach ($this->world->relay(0)->events as $value) {
            $record ??= SealedRecord::open($value, 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
        }
        self::assertNotNull($record);
        $value = json_decode(Json::encode($record->value), true);
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Waits, in the loop, until $condition holds (10 s at most, scaled).
     *
     * @param \Closure(): bool $condition
     */
    private static function until(\Closure $condition): void
    {
        $deadline = TestTime::deadline(10);
        while (!$condition() && Loop::now() < $deadline) {
            Loop::get()->sleepUntil(Loop::now() + 0.02);
        }
        self::assertTrue($condition(), 'in time');
    }

    /**
     * Each explorer request since the $since-th HTTP request: "node <what>" or "mempool <what>"
     * (mempool.space's rate source left out).
     *
     * @return list<string>
     */
    private function explorersAsked(int $since = 0): array
    {
        $asked = [];
        foreach (\array_slice($this->world->http->requests, $since) as $request) {
            $path = $request->path();
            if (str_ends_with($path, '/v1/ws')) {
                continue; // A WebSocket's handshake.
            }
            if ($request->host() === 'node.example' && str_starts_with($path, '/mempool/api/')) {
                $asked[] = 'node ' . substr($path, \strlen('/mempool/api/'));
            } elseif ($request->host() === 'mempool.space' && str_starts_with($path, '/api/') && !str_starts_with($path, '/api/v1/prices')) {
                $asked[] = 'mempool ' . substr($path, \strlen('/api/'));
            }
        }

        return $asked;
    }

    /** A transaction paying $address $sats (mined at $height), on the chain of every explorer: its txid. */
    private function pay(string $address, int $sats, ?int $height = null): string
    {
        $txid = bin2hex(random_bytes(32));
        $this->world->transactions[$address][] = World::transaction($txid, [bin2hex(random_bytes(32)) . ':0'], [[$sats, $address], [5000, World::address()]], $height);

        return $txid;
    }
}
