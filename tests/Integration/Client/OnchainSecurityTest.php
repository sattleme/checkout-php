<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Invoice;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Onchain\Scenarios;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;

/**
 * The flaws S1 to S6 of on-chain payments found in a draft of the specification, fixed before its
 * first release (SPEC.md §13, §21, §31), end to end against local relays and fakes of the
 * explorers, as checkout-node's test/onchain-security.test.ts reproduces them. Each test states
 * what must hold under SPEC.md.
 */
final class OnchainSecurityTest extends TestCase
{
    use Scenarios;

    private World $world;

    /** @var list<string> the lot's addresses, as the tests use them */
    private array $lot;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->channel();
        $this->lot = [World::address(), World::address(), World::address()];
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    // S5: a paged read of the leases misses a lease every relay holds.

    public function testS5ControlAnotherOrdersLeaseKeepsTheAddress(): void
    {
        $this->world->lot([$this->lot[0]]);
        $this->everywhere($this->leaseBy('HOLDER', $this->lot[0]));
        self::assertNull($this->client()->createInvoice(['ref' => 'S5-0', 'sats' => '49000'])->onchainAddress);
    }

    public function testS5TiesAPageOfRecordsOfTheLeasesVerySecondWithLowerIds(): void
    {
        $this->world->lot([$this->lot[0]]);
        $this->cap(500);
        // At equal created_at, the lowest id first (NIP-01; strfry: the last stored first).
        do {
            $lease = $this->leaseBy('HOLDER', $this->lot[0]);
        } while (strcmp($lease->id, 'c') < 0);
        $this->everywhere($lease);
        $d = $this->registryAddress($this->lot[0]);
        for ($made = 0; $made < 500;) {
            $record = $this->junk($d, $lease->createdAt);
            if (strcmp(self::idOf($record), $lease->id) < 0) {
                $this->store($record);
                ++$made;
            }
        }
        self::assertNull($this->client()->createInvoice(['ref' => 'S5-1', 'sats' => '49000'])->onchainAddress);
    }

    public function testS5UnusableDatesAPageOfRecordsDatedBeyond2Pow53(): void
    {
        $this->world->lot([$this->lot[0]]);
        $this->cap(500);
        $this->everywhere($this->leaseBy('HOLDER', $this->lot[0]));
        $d = $this->registryAddress($this->lot[0]);
        for ($i = 0; $i < 500; ++$i) {
            // As a relay that keeps any date stores it (strfry refuses one more than 900 s ahead).
            $this->store($this->junk($d, 9007199254740992 + 2 * ($i + 1)));
        }
        self::assertNull($this->client()->createInvoice(['ref' => 'S5-2', 'sats' => '49000'])->onchainAddress);
    }

    public function testS5VolumeMoreThanTwentyPagesOfRecordsNewerThanTheLease(): void
    {
        $this->world->lot([$this->lot[0]]);
        $this->cap(100);
        $this->everywhere($this->leaseBy('HOLDER', $this->lot[0], $this->world->now - 600));
        $d = $this->registryAddress($this->lot[0]);
        for ($i = 0; $i < 2001; ++$i) {
            $this->store($this->junk($d, $this->world->now - 599 + $i % 500));
        }
        self::assertNull($this->client()->createInvoice(['ref' => 'S5-3', 'sats' => '49000'])->onchainAddress);
    }

    public function testS5OrderARelayThatAnswersAFilterWithoutALimitOldestFirst(): void
    {
        $this->world->lot([$this->lot[0]]);
        foreach ($this->relays() as $relay) {
            $relay->oldestFirstWithoutLimit = true;
        }
        $this->everywhere($this->leaseBy('HOLDER', $this->lot[0]));
        $d = $this->registryAddress($this->lot[0]);
        for ($i = 0; $i < 1000; ++$i) {
            $this->store($this->junk($d, $this->world->now - 7000 + $i));
        }
        self::assertNull($this->client()->createInvoice(['ref' => 'S5-4', 'sats' => '49000'])->onchainAddress);
    }

    // S4: records of a newer format, signed by any key, hold every address.

    public function testS4AnEmptyContentAtEachRegistryAddressTakesNothingAway(): void
    {
        $this->world->lot($this->lot);
        foreach ($this->lot as $address) {
            $this->everywhere($this->junk($this->registryAddress($address), $this->world->now, ''));
        }
        self::assertNotNull($this->client()->createInvoice(['ref' => 'S4-1', 'sats' => '49000'])->onchainAddress);
    }

    public function testS4NoSuchRecordCostsASignatureCheck(): void
    {
        // 1 000 records whose content is of a newer NIP-44 version (`#`), by keys of their own: a
        // signature check each would take some 10 s (about 10 ms each, with gmp or bcmath alone);
        // ignored where anybody writes, they take none, and hold nothing.
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        for ($i = 0; $i < 1000; ++$i) {
            $this->store($this->junk($d, $this->world->now - $i, '#'));
        }
        $started = microtime(true);
        $invoice = $this->client()->createInvoice(['ref' => 'S4-2', 'sats' => '49000']);
        self::assertLessThan(TestTime::seconds(4.0), microtime(true) - $started);
        self::assertSame($this->lot[0], $invoice->onchainAddress);
    }

    public function testS4ALeaseOfANewerFormatSealedWithTheRegistryKeyHoldsItsAddressAndIsWarnedOf(): void
    {
        // A lease of a newer version (its outside's version above the one known), which only a
        // holder of the registry key seals: it holds its address (SPEC.md §31, "Leases"), and a
        // record of a newer format is warned of (SPEC.md §12).
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        $secret = hash('sha256', 'a sale of a newer version', true);
        $author = Derive::author($secret);
        $context = TestContext::create(new TestConnector());
        $this->everywhere(SealedRecord::seal(Protocol::LEASE_TYPE, ['version' => 2], $secret, Derive::outerKey($this->world->registry->key->reveal(), $d, $author), $d, $this->world->now - 60, $context->random(...)));
        $this->world->warnings = [];
        self::assertNull($this->client()->createInvoice(['ref' => 'S4-3', 'sats' => '49000'])->onchainAddress);
        self::assertSame(["A lease of the integration's addresses has a newer format than this version of sattleme/checkout: update it"], $this->world->warned('A lease of'));
    }

    // S1: an earlier invoice whose lease was never confirmed is handed out again.

    public function testS1AnOrderPaidOnchainOnlyWhoseLeaseReachedOneRelay(): void
    {
        $this->world->lot([$this->lot[0]]);
        $this->onchainOnly();
        [$r1, $r2, $r3] = $this->relays();
        $author = $this->sale('S1-R')->author;
        $start = $this->world->now;
        // r1 takes the order's lease and nothing after it (its withdrawal is lost); r2 and r3 refuse.
        $r1->refuseEvent = static fn(Event $e): ?string => $e->pubkey === $author && $e->createdAt > $start ? 'error: down' : null;
        $refuse = static fn(Event $e): ?string => $e->pubkey === $author ? 'error: down' : null;
        $r2->refuseEvent = $refuse;
        $r3->refuseEvent = $refuse;
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'S1-R', 'sats' => '49000'])));

        // Another order while r1 is slow: r2 and r3, which never got that lease, answer first.
        $r1->refuseEvent = null;
        $r1->queryDelayMs = 500;
        $r2->refuseEvent = null;
        $r3->refuseEvent = null;
        $x = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'S1-X', 'sats' => '49000']));
        $xHolds = $x instanceof Invoice && $x->onchainAddress === $this->lot[0];

        // The first order again while r2 is slow: r1, which holds its lease, answers.
        $r1->queryDelayMs = 0;
        $r2->queryDelayMs = 500;
        $again = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'S1-R', 'sats' => '49000']));
        // Never both: the address handed out to this order while the other holds it.
        self::assertFalse($xHolds && self::handedOut($again) === $this->lot[0]);
        // The confirmation of X's lease reads r1 too, which shows R's lease: X withdraws
        // its own; and R's lease, never confirmed, gives its order no address.
        self::assertInstanceOf(OnchainUnavailableException::class, $x);
        self::assertInstanceOf(OnchainUnavailableException::class, $again);
    }

    public function testS1AnInvoiceWithLightningWhoseDeclarationFailedItsWithdrawalOnOneRelay(): void
    {
        $this->world->lot([$this->lot[0]]);
        [$r1, $r2, $r3] = $this->relays();
        $author = $this->sale('S1-L')->author;
        $d = $this->registryAddress($this->lot[0]);
        $start = $this->world->now;
        $mine = static fn(Event $e): bool => $e->pubkey === $author;
        $atD = static fn(Event $e): bool => $e->tag('d') === $d;
        // r1: the lease and the declaration, not the withdrawal. r2: the lease and the withdrawal,
        // not the declaration. r3: the lease only.
        $r1->refuseEvent = static fn(Event $e): ?string => $mine($e) && $atD($e) && $e->createdAt > $start ? 'error: down' : null;
        $r2->refuseEvent = static fn(Event $e): ?string => $mine($e) && !$atD($e) ? 'error: down' : null;
        $r3->refuseEvent = static fn(Event $e): ?string => $mine($e) && (!$atD($e) || $e->createdAt > $start) ? 'error: down' : null;
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'S1-L', 'sats' => '49000'])));

        // Another order while r1 is slow: r2's withdrawal frees the address.
        $r1->refuseEvent = null;
        $r1->queryDelayMs = 500;
        $r2->refuseEvent = null;
        $r3->refuseEvent = null;
        $x = $this->client()->createInvoice(['ref' => 'S1-X', 'sats' => '49000']);
        $xHolds = $x->onchainAddress === $this->lot[0];

        // The first order again while r2 is slow.
        $r1->queryDelayMs = 0;
        $r2->queryDelayMs = 500;
        $again = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'S1-L', 'sats' => '49000']));
        self::assertFalse($xHolds && self::handedOut($again) === $this->lot[0]);
    }

    // S2: a transaction evicted, then broadcast again during a later lease, pays two orders.

    public function testS2TheFirstOrdersWitnessNamesIt(): void
    {
        $this->world->lot([$this->lot[0]]);
        $client = $this->client();
        $p = $client->createInvoice(['ref' => 'S2-P', 'sats' => '49000', 'durationSeconds' => 600]);
        $address = $p->onchainAddress;
        self::assertNotNull($address);
        // Paid with a very low fee, seen while P's lease runs: P's witness.
        $t = self::payment($address, 49000);
        $this->broadcast($address, $t);
        self::assertSame('unconfirmed', $client->getStatus($p)->state);
        // Evicted from the mempools: the explorer lists nothing for the address.
        $this->evict($address, $t['txid']);
        $this->world->now = $p->expiresAt + 120 + 301;
        self::assertSame(['expired', '0'], self::stateOf($client->getStatus($p)));

        $n = $client->createInvoice(['ref' => 'S2-N', 'sats' => '49000', 'durationSeconds' => 600]);
        // The payer's wallet broadcasts T again during N's lease; it is mined.
        $this->broadcast($address, $t);
        if ($n->onchainAddress === $address) {
            $client->getStatus($n);
        }
        $this->mine($address, $t['txid'], $this->world->height - 5);
        // The payer asks for P again: the shop checks P's earlier invoice.
        $pAgain = self::outcome(fn(): Invoice => $client->createInvoice(['ref' => 'S2-P', 'sats' => '49000']));
        $nPaid = $n->onchainAddress === $address && $client->getStatus($n)->state === 'paid';
        // One transaction, one order.
        self::assertFalse($nPaid && $pAgain instanceof OrderPaidException);
    }

    public function testS2NoCheckSawItWhileTheFirstLeaseRanBlockCyphersTime(): void
    {
        $this->world->lot([$this->lot[0]]);
        $client = $this->client();
        $p = $client->createInvoice(['ref' => 'S2-Q', 'sats' => '49000', 'durationSeconds' => 600]);
        $address = $p->onchainAddress;
        self::assertNotNull($address);
        $t = self::payment($address, 49000);
        // BlockCypher received it while P's lease ran; no check of P saw it; it is evicted.
        $this->world->received[$t['txid']] = World::rfc3339(($p->createdAt + 10) * 1000);
        $this->world->now = $p->expiresAt + 120 + 301;
        self::assertSame(['expired', '0'], self::stateOf($client->getStatus($p)));

        $n = $client->createInvoice(['ref' => 'S2-M', 'sats' => '49000', 'durationSeconds' => 600]);
        self::assertSame($address, $n->onchainAddress);
        $this->broadcast($address, $t);
        self::assertSame(['unconfirmed', '49000'], self::stateOf($client->getStatus($n)));
        $this->mine($address, $t['txid'], $this->world->height - 5);
        $pAgain = self::outcome(fn(): Invoice => $client->createInvoice(['ref' => 'S2-Q', 'sats' => '49000']));
        $nPaid = $client->getStatus($n)->state === 'paid';
        self::assertFalse($nPaid && $pAgain instanceof OrderPaidException);
    }

    // S3: the witness of an earlier invoice of the order counts for a later one.

    public function testS3AnUnderpaymentOfAnExpiredInvoiceCountsAgainForTheNextOne(): void
    {
        $this->world->lot([$this->lot[0]]);
        // On-chain only: no rest invoice is asked.
        $this->onchainOnly();
        $client = $this->client();
        $first = $client->createInvoice(['ref' => 'S3-R', 'sats' => '49000', 'durationSeconds' => 600]);
        $address = $first->onchainAddress;
        self::assertNotNull($address);
        $t = self::payment($address, 30000);
        $this->broadcast($address, $t);
        self::assertSame(['open', '30000'], self::stateOf($client->getStatus($first)));
        $this->world->now = $first->expiresAt + 121;
        // Underpaid for good: the merchant settles the 30 000 sats with the customer.
        self::assertSame(['expired', '30000'], self::stateOf($client->getStatus($first)));
        // T leaves the mempool (evicted).
        $this->evict($address, $t['txid']);
        $this->world->now = $first->expiresAt + 120 + 301;
        $second = self::outcome(fn(): Invoice => $client->createInvoice(['ref' => 'S3-R', 'sats' => '49000', 'durationSeconds' => 600]));
        if (!$second instanceof Invoice) {
            // No address for it (the fix: its witness names the address): nothing can count twice.
            self::assertInstanceOf(OnchainUnavailableException::class, $second);

            return;
        }
        self::assertSame($address, $second->onchainAddress);
        // T broadcast again after the second invoice's end: late, whatever its witness.
        $this->world->now = $second->expiresAt + 3600;
        $this->broadcast($address, $t);
        self::assertSame(['expired', '0'], self::stateOf($client->getStatus($second)));
    }

    public function testS3AWitnessOfTheEarlierInvoiceMadeAfterTheLaterOneLeasedTheAddress(): void
    {
        $this->world->lot([$this->lot[0]]);
        $this->onchainOnly();
        $client = $this->client();
        $first = $client->createInvoice(['ref' => 'S3-Q', 'sats' => '49000', 'durationSeconds' => 600]);
        $address = $first->onchainAddress;
        self::assertNotNull($address);
        $t = self::payment($address, 30000);
        // Received by BlockCypher while the first lease ran; no check saw it; then evicted.
        $this->world->received[$t['txid']] = World::rfc3339(($first->createdAt + 10) * 1000);
        // Beyond the reads of leases (7200 s), where the order's own records keep it off the address.
        $this->world->now = $first->createdAt + 7300;
        self::assertSame(['expired', '0'], self::stateOf($client->getStatus($first)));
        $second = $client->createInvoice(['ref' => 'S3-Q', 'sats' => '49000', 'durationSeconds' => 600]);
        self::assertSame($address, $second->onchainAddress);
        // T comes back after the second invoice's end; the first invoice is checked first.
        $this->world->now = $second->expiresAt + 3600;
        $this->broadcast($address, $t);
        $counted = array_filter([$client->getStatus($first), $client->getStatus($second)], static fn($status): bool => $status->received === '30000');
        // T was received once: it counts for one invoice at most.
        self::assertLessThanOrEqual(1, \count($counted));
    }

    // S6: a hostile relay hides a lease from the majority that reads.

    public function testS6TheLeaseReachedHAndR2OnlyHAndR1AnswerFirst(): void
    {
        // The confirmation reads on every relay (SPEC.md §13): r2, late but in time, shows the lease.
        $this->world->lot([$this->lot[0]]);
        [, $h, $r2] = $this->relays();
        $lease = $this->leaseBy('S6-A', $this->lot[0]);
        // r1 refused it.
        $h->take($lease);
        $r2->take($lease);
        $h->hide = static fn(\stdClass $event): bool => $event->id === $lease->id;
        // r2 answers its reads 1.5 s late, well within the timeout (4 s): H and r1 answer first,
        // whatever the load of the machine running the test.
        $r2->queryDelayMs = 1500;
        $invoice = $this->client(['relayTimeoutMs' => 4000])->createInvoice(['ref' => 'S6-B', 'sats' => '49000']);
        self::assertNull($invoice->onchainAddress);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Client
    {
        return $this->world->client($options + ['relayTimeoutMs' => 1000]);
    }

    /** Above the Lightning address's maximum (1 000 sats): 49 000 sats are paid on-chain only. */
    private function onchainOnly(): void
    {
        $this->world->payRequest['maxSendable'] = 1000000;
    }

    /** Every relay sends at most $cap events a query. */
    private function cap(int $cap): void
    {
        foreach ($this->relays() as $relay) {
            $relay->cap = $cap;
        }
    }

    /** Every relay holds $record as it is (records anybody writes, of distinct authors). */
    private function store(\stdClass $record): void
    {
        foreach ($this->relays() as $relay) {
            $relay->store($record);
        }
    }

    /** The address an outcome of createInvoice hands out: its invoice's, or the pending one's. */
    private static function handedOut(mixed $outcome): ?string
    {
        return match (true) {
            $outcome instanceof Invoice => $outcome->onchainAddress,
            $outcome instanceof InvoicePendingException => $outcome->getInvoice()->onchainAddress,
            default => null,
        };
    }

    /** @return array{0: string, 1: string|null} */
    private static function stateOf(\Sattleme\Checkout\PaymentStatus $status): array
    {
        return [$status->state, $status->received];
    }
}
