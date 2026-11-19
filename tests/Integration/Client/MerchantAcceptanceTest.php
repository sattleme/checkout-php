<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Onchain\Acceptance;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptance;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptanceKeys;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Invoice;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * The merchant's acceptance (SPEC.md §31, "The merchant's acceptance"), end to end against local
 * relays and fakes of breez.tips and of the explorers: the team's record, read on every relay with
 * the witnesses, pays an order underpaid on-chain down to what the merchant accepted, only when a
 * majority of the merchant's relays hold it, whichever majority answers; the pages of the order's
 * checkout links told by an acceptance record (the server's); the check publishing nothing of it.
 */
final class MerchantAcceptanceTest extends TestCase
{
    private const SATS = 49000;

    /** What came on-chain, and what the merchant accepted. */
    private const CAME = 30000;

    private World $world;

    private Client $client;

    private string $address;

    private Invoice $invoice;

    /** The transaction that came. */
    private string $txid;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->channel();
        $this->address = World::address();
        $this->world->lot([$this->address]);
        $this->client = $this->world->client(['relayTimeoutMs' => 1000]);
        $this->invoice = $this->createInvoice('R-1', $this->address);
        $this->world->now += 60;
        $this->txid = $this->pay($this->address, self::CAME);
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    // A merchant's acceptance a majority of the relays hold.

    public function testItPaysTheOrderDownToItTheAcceptanceRecordPublishedNothingOfIt(): void
    {
        $this->hold($this->acceptance(self::CAME), [0, 1]);
        self::assertSame($this->unconfirmed(), $this->statusNow());
        // No rest asked: the order is paid.
        self::assertSame([], $this->restInvoices(self::SATS - self::CAME));
        // The acceptance record (the server's, for the pages of the order's checkout links) on a
        // majority: what was received, nothing of the merchant's acceptance itself.
        self::assertSame([(string) self::CAME], $this->acceptanceRecords());
        self::assertGreaterThanOrEqual(2, \count(array_filter([0, 1, 2], fn(int $relay): bool => $this->acceptanceRecords($relay) === [(string) self::CAME])));
        // The merchant's acceptance never published again, nor anything of the team's.
        self::assertSame([], $this->sentOfTheTeam());
        self::assertSame([], array_filter($this->world->relay(2)->events, fn(\stdClass $event): bool => $event->pubkey === $this->world->team && $event->tags === [['d', $this->acceptanceAddress()]]));

        $this->confirm($this->txid);
        $this->world->now = $this->invoice->expiresAt + 3600;
        $paid = ['state' => 'paid', 'method' => 'onchain', 'received' => (string) self::CAME, 'txids' => [$this->txid]];
        self::assertSame($paid, $this->statusNow());
        try {
            $this->client->createInvoice(['ref' => 'R-1', 'sats' => (string) self::SATS]);
            self::fail('The order is paid');
        } catch (OrderPaidException $e) {
            self::assertSame($paid, $e->getStatus()->toArray());
        }
        self::assertSame([], $this->world->warnings);
    }

    /** @return iterable<string, array{0: list<int>, 1: list<int>}> */
    public static function majorities(): iterable
    {
        yield 'the third failing' => [[0, 1], [2]];
        yield 'the first failing, the 2 others holding it' => [[1, 2], [0]];
        yield 'every relay answering' => [[0, 1], []];
    }

    /**
     * @param list<int> $holders
     * @param list<int> $failing
     */
    #[DataProvider('majorities')]
    public function testItCountsWhicheverMajorityAnswers(array $holders, array $failing): void
    {
        $this->hold($this->acceptance(self::CAME), $holders);
        $this->muteRead($failing);
        self::assertSame($this->unconfirmed(), $this->statusNow());
    }

    public function testItWaitsForARelayThatHoldsItAndAnswersLate(): void
    {
        $this->hold($this->acceptance(self::CAME), [0, 1]);
        $this->world->relay(0)->queryDelayMs = 400;
        self::assertSame($this->unconfirmed(), $this->statusNow());
    }

    public function testOneMadeBeforeTheEndCountsAfterTheLeasesEndNeverExpired(): void
    {
        $this->assertUnderpaid();
        $this->hold($this->acceptance(self::CAME, $this->invoice->expiresAt - 60), [1, 2]);
        $this->world->now = $this->invoice->expiresAt + 3600;
        self::assertSame($this->unconfirmed(), $this->statusNow());
    }

    public function testOneOfLessThanWhatCameCountsWhatCamePays(): void
    {
        $this->hold($this->acceptance(20000));
        self::assertSame($this->unconfirmed(), $this->statusNow());
        self::assertSame([(string) self::CAME], $this->acceptanceRecords());
    }

    public function testOneOfMoreThanWhatCameAsksTheWholeRest(): void
    {
        $this->hold($this->acceptance(35000));
        $this->assertUnderpaid();
        self::assertSame([], $this->acceptanceRecords());
    }

    // A merchant's acceptance only a minority of the relays holds.

    public function testOneAMinorityHoldsNeverCountsWhicheverRelaysAnswer(): void
    {
        $this->hold($this->acceptance(self::CAME), [0]);
        $this->assertUnderpaid();
        // The relay that holds it and one that does not answer: still a minority's.
        $this->muteRead([2]);
        $this->assertUnderpaid();
        $this->muteRead([]);
        $this->world->now = $this->invoice->expiresAt + 60;
        $status = $this->client->getStatus($this->invoice);
        self::assertSame([PaymentStatus::OPEN, null], [$status->state, $status->rest]);
        $this->world->now = $this->invoice->expiresAt + 3600;
        self::assertSame(['state' => 'expired', 'received' => (string) self::CAME], $this->statusNow());
        self::assertSame([], $this->acceptanceRecords());
    }

    public function testARelayThatHoldsItFailingWhileTheOtherHoldersAreAMinority(): void
    {
        // SPEC.md §27: that check does not count it; a later one, which that relay answers, does.
        $this->hold($this->acceptance(self::CAME), [0, 1]);
        $this->muteRead([0]);
        $this->assertUnderpaid();
        $this->muteRead([]);
        self::assertSame($this->unconfirmed(), $this->statusNow());
    }

    public function testARelayCountsOnceWhateverCopiesOfItItSends(): void
    {
        $event = $this->acceptance(self::CAME);
        // The same event under another signature (BIP-340: any of them verifies): the same record.
        $resigned = $this->world->resigned($event);
        self::assertSame($event->id, $resigned->id);
        self::assertNotSame($event->sig, $resigned->sig);
        $relay = $this->world->relay(0);
        $relay->store($event);
        $relay->store($event);
        $relay->store($resigned);
        $this->assertUnderpaid();
        // The count itself, of what the relays sent (the pool already reads a relay's copies of
        // one id once): one relay, whatever copies it sent; a copy that does not check out counts
        // for no relay; then a second relay, a majority.
        $keys = MerchantAcceptanceKeys::of($this->world->integration(), Sale::of($this->world->integration(), 'R-1')->author, $this->address, $this->invoice->expiresAt);
        $damaged = $event->toJson();
        $damaged->sig = self::damaged($event->sig);
        $answers = ['a' => [$event->toJson(), $event->toJson(), $resigned->toJson(), $resigned->toJson()], 'b' => [$damaged], 'c' => []];
        self::assertEquals(new MerchantAcceptance(null, false), MerchantAcceptance::of($answers, $keys, (string) self::SATS, $this->invoice->expiresAt, 3));
        $answers['b'] = [$damaged, $resigned->toJson()];
        self::assertEquals(new MerchantAcceptance((string) self::CAME, false), MerchantAcceptance::of($answers, $keys, (string) self::SATS, $this->invoice->expiresAt, 3));
    }

    // A record that is not this invoice's merchant's acceptance.

    /** @return iterable<string, array{0: string}> */
    public static function others(): iterable
    {
        yield 'of another invoice of the order (another expiresAt)' => ['expiresAt'];
        yield 'of another order' => ['ref'];
        yield 'of another address' => ['address'];
        yield 'of another team' => ['signer'];
        yield 'of the whole amount' => ['whole'];
        yield 'of 0 sats' => ['zero'];
        yield 'without a version' => ['no version'];
    }

    #[DataProvider('others')]
    public function testARecordThatIsNotThisInvoicesMerchantsAcceptanceIsNotCounted(string $other): void
    {
        $value = match ($other) {
            'whole' => ['version' => 1, 'received' => self::SATS],
            'zero' => ['version' => 1, 'received' => 0],
            'no version' => ['received' => self::CAME],
            default => ['version' => 1, 'received' => self::CAME],
        };
        $this->hold($this->world->merchantAcceptance(
            $other === 'ref' ? 'R-2' : 'R-1',
            $other === 'address' ? World::address() : $this->address,
            $other === 'expiresAt' ? $this->invoice->expiresAt + 3600 : $this->invoice->expiresAt,
            $value,
            $this->world->now - 60,
            $other === 'signer' ? self::secret() : null,
        ));
        $this->assertUnderpaid();
        self::assertSame([], $this->world->warnings);
    }

    public function testOneWithADamagedSignatureIsNotCounted(): void
    {
        $event = $this->acceptance(self::CAME);
        $damaged = $event->toJson();
        $damaged->sig = self::damaged($event->sig);
        foreach ($this->world->relays->relays as $relay) {
            $relay->store($damaged);
        }
        $this->assertUnderpaid();
    }

    /** @return iterable<string, array{0: int, 1: bool}> */
    public static function dates(): iterable
    {
        yield "at the lease's end: counted" => [120, true];
        yield "a second after the lease's end: not counted" => [121, false];
    }

    /**
     * Dated after now: its `created_at` is never compared with now, only with the lease's end.
     */
    #[DataProvider('dates')]
    public function testOneDatedAfterTheInvoicesEnd(int $after, bool $counted): void
    {
        $this->hold($this->acceptance(self::CAME, $this->invoice->expiresAt + $after));
        if ($counted) {
            self::assertSame($this->unconfirmed(), $this->statusNow());
        } else {
            $this->assertUnderpaid();
        }
    }

    // A merchant's acceptance of a newer format.

    public function testOneOfANewerFormatNewerThanAValidOneLeavesTheOrderWithoutOne(): void
    {
        $this->newerWith($this->world->now - 30, [$this->acceptance(self::CAME, $this->world->now - 60)]);
        $this->assertUnderpaid();
        self::assertCount(1, $this->warnedOfANewerFormat());
    }

    public function testOneOfANewerFormatOlderThanAValidOneLeavesItCounted(): void
    {
        $this->newerWith($this->world->now - 90, [$this->acceptance(self::CAME, $this->world->now - 60)]);
        self::assertSame($this->unconfirmed(), $this->statusNow());
        self::assertCount(1, $this->warnedOfANewerFormat());
    }

    public function testOneOfANewerFormatDatedAfterTheLeasesEndIsSetAside(): void
    {
        $this->newerWith($this->invoice->expiresAt + 121, [$this->acceptance(self::CAME)]);
        self::assertSame($this->unconfirmed(), $this->statusNow());
        self::assertCount(1, $this->warnedOfANewerFormat());
    }

    // A merchant's acceptance with rest invoices, the tolerance, the Lightning part.

    public function testWhatRestInvoicesPaidIsTakenFromItOnceUnconfirmedThenPaidMixed(): void
    {
        // 40 000 on-chain: a rest invoice of 9 000, paid; then the transaction replaced (RBF) by
        // one paying 21 000: 30 000 in all, 19 000 asked.
        $first = bin2hex(random_bytes(32));
        $coin = bin2hex(random_bytes(32)) . ':0';
        $this->world->transactions[$this->address] = [World::transaction($first, [$coin], [[40000, $this->address], [5000, World::address()]])];
        $this->client->getStatus($this->invoice);
        [$hash] = $this->restInvoice(9000);
        $this->world->settle($hash);
        ++$this->world->now;
        $bump = bin2hex(random_bytes(32));
        $this->world->transactions[$this->address] = [World::transaction($bump, [$coin], [[21000, $this->address], [5000, World::address()]])];
        $status = $this->client->getStatus($this->invoice);
        self::assertSame([PaymentStatus::OPEN, '30000', '19000'], [$status->state, $status->received, $status->rest?->sats]);
        // The merchant accepts what came: 21 000 due on-chain (30 000 less the 9 000 of the rest).
        $this->hold($this->acceptance(self::CAME));
        self::assertSame(['state' => 'unconfirmed', 'received' => '30000', 'confirmations' => 0, 'txids' => [$bump]], $this->statusNow());
        self::assertSame(['30000'], $this->acceptanceRecords());
        $this->confirm($bump);
        $this->world->now = $this->invoice->expiresAt + 3600;
        self::assertSame(['state' => 'paid', 'method' => 'mixed', 'received' => '30000', 'txids' => [$bump], 'preimages' => [$this->world->invoices[$hash]['preimage']]], $this->statusNow());
    }

    /** @return iterable<string, array{0: int, 1: int}> */
    public static function lowers(): iterable
    {
        yield "the merchant's acceptance's, lower" => [self::CAME, self::CAME];
        yield "the tolerance's, lower" => [48000, 46550];
    }

    #[DataProvider('lowers')]
    public function testWithTheToleranceTheLowerJudges(int $accepted, int $came): void
    {
        $this->world->channel(['onchainTolerance' => 500], null, $this->world->now - 100);
        $address = World::address();
        ++$this->world->now; // A newer lot than the last one.
        $this->world->lot([$address], $this->world->now - 1);
        $invoice = $this->createInvoice('R-2', $address);
        $this->world->now += 60;
        $txid = $this->pay($address, $came);
        $this->hold($this->world->merchantAcceptance('R-2', $address, $invoice->expiresAt, ['version' => 1, 'received' => $accepted], $this->world->now - 60));
        self::assertSame(['state' => 'unconfirmed', 'received' => (string) $came, 'confirmations' => 0, 'txids' => [$txid]], $this->client->getStatus($invoice)->toArray());
        self::assertSame([(string) $came], $this->acceptanceRecords(0, $invoice));
    }

    public function testItChangesNothingOnceTheLightningInvoiceIsPaid(): void
    {
        $this->hold($this->acceptance(self::CAME));
        $this->world->settle((string) $this->invoice->paymentHash);
        $status = $this->client->getStatus($this->invoice);
        self::assertSame([PaymentStatus::PAID, 'lightning'], [$status->state, $status->method]);
    }

    public function testFewerThanAMajorityAnsweringItsReadIsNetwork(): void
    {
        // A first check published the witness and asked the rest: the next one publishes nothing
        // before it reads (the relays that fail its read fail every later read on every relay too).
        $this->assertUnderpaid();
        $this->hold($this->acceptance(self::CAME));
        $this->muteRead([1, 2]);
        try {
            $this->client->getStatus($this->invoice);
            self::fail('network');
        } catch (NetworkException) {
            // Fewer than a majority answered the read of the merchant's acceptance.
        }
        // Its Lightning invoice paid: paid all the same.
        $this->world->settle((string) $this->invoice->paymentHash);
        $status = $this->client->getStatus($this->invoice);
        self::assertSame([PaymentStatus::PAID, 'lightning'], [$status->state, $status->method]);
    }

    public function testWaitForPaymentSeesItAtItsNextCheckOfTheOnchainPartTheRestGivenTillThen(): void
    {
        // The full checks: each asks the explorer for the address's transactions once.
        $fullChecks = 0;
        $this->world->onRequest = function (string $host, HttpRequest $request) use (&$fullChecks): void {
            if ($host === 'mempool.space' && $request->path() === "/api/address/{$this->address}/txs") {
                ++$fullChecks;
            }
        };
        $statuses = [];
        $phase = 'connecting';
        $connectedAt = null;
        $heldAt = null;
        $checksAtHold = null;
        $underpaid = null;
        $status = $this->client->waitForPayment($this->invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => function (PaymentStatus $status) use (&$statuses, &$phase, &$connectedAt, &$heldAt, &$checksAtHold, &$underpaid, &$fullChecks): void {
            $statuses[] = $status->toArray();
            if ($phase === 'connecting') {
                // The socket connected, and the check its connection starts ended: two statuses later.
                $connectedAt ??= $this->world->sockets === [] ? null : \count($statuses);
                if ($connectedAt !== null && \count($statuses) >= $connectedAt + 2) {
                    $underpaid = $this->underpaid();
                    TestCase::assertSame($underpaid, $status->toArray());
                    // The merchant accepts: no message of the explorer, no check of the on-chain part.
                    $this->hold($this->acceptance(self::CAME));
                    $heldAt = \count($statuses);
                    $checksAtHold = $fullChecks;
                    $phase = 'held';
                }
            } elseif ($phase === 'held' && \is_int($heldAt) && \count($statuses) >= $heldAt + 3) {
                // The checks of the Lightning invoices give the status with its rest still.
                TestCase::assertSame($checksAtHold, $fullChecks);
                TestCase::assertSame(array_fill(0, \count($statuses) - $heldAt, $underpaid), \array_slice($statuses, $heldAt));
                // The next check of the on-chain part (a block here; every 60 s at least) counts it.
                $this->world->push(['block' => ['height' => $this->world->height]]);
                $phase = 'block';
            } elseif ($phase === 'block' && $status->state === PaymentStatus::UNCONFIRMED) {
                TestCase::assertSame($this->unconfirmed(), $status->toArray());
                $this->confirm($this->txid);
                $this->world->push(['block' => ['height' => $this->world->height]]);
                $phase = 'confirmed';
            }
        }], new TimeoutCancellation(30));
        self::assertSame('confirmed', $phase);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => (string) self::CAME, 'txids' => [$this->txid]], $status->toArray());
        self::assertSame([(string) self::CAME], $this->acceptanceRecords());
    }

    private function createInvoice(string $ref, string $address): Invoice
    {
        $invoice = $this->client->createInvoice(['ref' => $ref, 'sats' => (string) self::SATS, 'durationSeconds' => 600, 'note' => 'Order 1', 'checkout' => true]);
        self::assertSame($address, $invoice->onchainAddress);

        return $invoice;
    }

    /**
     * The status of the order's invoice, as an array.
     *
     * @return array<string, mixed>
     */
    private function statusNow(): array
    {
        return $this->client->getStatus($this->invoice)->toArray();
    }

    /**
     * The merchant's acceptance of the invoice at $received, as the merchant's app makes it, dated
     * $createdAt (a minute ago by default).
     */
    private function acceptance(int $received, ?int $createdAt = null): Event
    {
        return $this->world->merchantAcceptance('R-1', $this->address, $this->invoice->expiresAt, ['version' => 1, 'received' => $received], $createdAt ?? $this->world->now - 60);
    }

    /** Where the merchant's acceptance of the invoice is. */
    private function acceptanceAddress(): string
    {
        return Derive::merchantAcceptanceAddress($this->world->integration()->key->reveal(), Sale::of($this->world->integration(), 'R-1')->author, $this->address, $this->invoice->expiresAt);
    }

    /**
     * The relays at $indexes (all by default) take $event, as a relay keeps it.
     *
     * @param list<int> $indexes
     */
    private function hold(Event $event, array $indexes = [0, 1, 2]): void
    {
        foreach ($indexes as $index) {
            $this->world->relay($index)->take($event);
        }
    }

    /**
     * The relays at $indexes never answer the read of the merchant's acceptance of the invoice;
     * the others answer every read.
     *
     * @param list<int> $indexes
     */
    private function muteRead(array $indexes): void
    {
        $address = $this->acceptanceAddress();
        foreach ([0, 1, 2] as $index) {
            $relay = $this->world->relay($index);
            $relay->queryDelayMs = 0;
            $relay->onQuery = \in_array($index, $indexes, true) ? static function (\stdClass $filter, LocalRelay $relay) use ($address): void {
                $relay->queryDelayMs = \in_array($address, (array) ($filter->{'#d'} ?? []), true) ? 60000 : 0;
            } : null;
        }
    }

    /**
     * A record of version 2 dated $createdAt, on every relay with $others (relays send them all).
     *
     * @param list<Event> $others
     */
    private function newerWith(int $createdAt, array $others): void
    {
        $newer = $this->world->merchantAcceptance('R-1', $this->address, $this->invoice->expiresAt, ['version' => 2], $createdAt);
        foreach ($this->world->relays->relays as $relay) {
            foreach ([...$others, $newer] as $event) {
                $relay->store($event);
            }
        }
    }

    /** @return list<string> */
    private function warnedOfANewerFormat(): array
    {
        return $this->world->warned('A merchant\'s acceptance of this order has a newer format');
    }

    /**
     * The `received` of the acceptance records of $invoice (the order's by default) relay $relay
     * holds, as a checkout link's page opens them.
     *
     * @return list<string>
     */
    private function acceptanceRecords(int $relay = 0, ?Invoice $invoice = null): array
    {
        $invoice ??= $this->invoice;
        $keys = RestKeys::of($this->world->integration(), $invoice->ref, (string) $invoice->onchainAddress, $invoice->expiresAt);
        $received = [];
        foreach ($this->world->relay($relay)->events as $value) {
            $read = Acceptance::read($value, $keys, $invoice->sats);
            if ($read !== null && $read[0] === 'ok') {
                $received[] = $read[1];
            }
        }

        return $received;
    }

    /**
     * What the checks sent the relays of the team's, or at the merchant's acceptance's address.
     *
     * @return list<\stdClass>
     */
    private function sentOfTheTeam(): array
    {
        $address = $this->acceptanceAddress();
        $sent = [];
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->received as $event) {
                if (Json::get($event, 'pubkey') === $this->world->team || str_contains(Json::encode(Json::get($event, 'tags')), $address)) {
                    $sent[] = $event;
                }
            }
        }

        return $sent;
    }

    /**
     * The status of the order underpaid: 30 000 of 49 000 in time, the rest asked of 19 000.
     *
     * @return array<string, mixed>
     */
    private function underpaid(): array
    {
        [, $pr] = $this->restInvoice(self::SATS - self::CAME);

        return [
            'state' => 'open',
            'expiresAt' => $this->invoice->expiresAt,
            'received' => (string) self::CAME,
            'rest' => ['sats' => '19000', 'bolt11' => $pr, 'paymentUri' => "bitcoin:{$this->address}?amount=0.00019&lightning={$pr}"],
        ];
    }

    /** The order's status is underpaid(): its rest asked by that check, or before. */
    private function assertUnderpaid(): void
    {
        $status = $this->statusNow();
        self::assertSame($this->underpaid(), $status);
    }

    /** @return array<string, mixed> */
    private function unconfirmed(): array
    {
        return ['state' => 'unconfirmed', 'received' => (string) self::CAME, 'confirmations' => 0, 'txids' => [$this->txid]];
    }

    /** A transaction paying $address $sats (and change elsewhere), in the mempool: its txid. */
    private function pay(string $address, int $sats): string
    {
        $txid = bin2hex(random_bytes(32));
        $this->world->transactions[$address][] = World::transaction($txid, [bin2hex(random_bytes(32)) . ':0'], [[$sats, $address], [5000, World::address()]]);

        return $txid;
    }

    /** $txid 3 deep. */
    private function confirm(string $txid): void
    {
        foreach ($this->world->transactions[$this->address] as $index => $transaction) {
            if ($transaction['txid'] === $txid) {
                $this->world->transactions[$this->address][$index]['status'] = ['confirmed' => true, 'block_height' => $this->world->height - 2];
            }
        }
    }

    /**
     * The invoices the Lightning address made of $sats sats.
     *
     * @return list<array{pr: string, preimage: string, sats: int, settled: bool, comment: string|null, expiry: int}>
     */
    private function restInvoices(int $sats): array
    {
        return array_values(array_filter($this->world->invoices, static fn(array $invoice): bool => $invoice['sats'] === $sats));
    }

    /**
     * The payment hash and the invoice of the first invoice of $sats sats the Lightning address made.
     *
     * @return array{0: string, 1: string}
     */
    private function restInvoice(int $sats): array
    {
        $made = array_filter($this->world->invoices, static fn(array $invoice): bool => $invoice['sats'] === $sats);
        self::assertNotSame([], $made, "an invoice of {$sats} sats");
        $hash = array_key_first($made);

        return [$hash, $made[$hash]['pr']];
    }

    /** $sig with its last digit changed. */
    private static function damaged(string $sig): string
    {
        return substr($sig, 0, -1) . (str_ends_with($sig, '0') ? '1' : '0');
    }

    /** A secret key of another team. */
    private static function secret(): string
    {
        do {
            $secret = random_bytes(32);
        } while (!Secp256k1::isSecretKey($secret));

        return $secret;
    }
}
