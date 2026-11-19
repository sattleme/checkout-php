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
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\Witness;
use Sattleme\Checkout\Internal\Onchain\WitnessKeys;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Invoice;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\Onchain\Scenarios;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;

/**
 * What the adversarial reviews of the specification's drafts found (F1 to F3, G3, G4, H1, H2, I1,
 * L1, L2, R6, R7, and S6 for witnesses), end to end against local relays and fakes of the
 * explorers, as checkout-node's test/onchain-security-review.test.ts states them. Each test
 * states what must hold under SPEC.md (OnchainSecurityTest reproduces the flaws of the first
 * draft); L3 is RelayReadsVectorsTest's and CompleteReadTest's.
 */
final class OnchainSecurityReviewTest extends TestCase
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
        // Above the Lightning address's maximum (1 000 sats): 49 000 sats are paid on-chain only.
        $this->world->payRequest['maxSendable'] = 1000000;
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    public function testF1AWithdrawalOnOneRelayOnlyFreesTheAddressThereForAnotherOrder(): void
    {
        $this->world->lot([$this->lot[0]]);
        [$r1, $r2, $r3] = $this->relays();
        $start = $this->world->now;
        $d = $this->registryAddress($this->lot[0]);
        $author = $this->sale('F1-R')->author;
        // Another server's short lease of the address reaches every relay right after R's: R's
        // lease is not confirmed. R's withdrawal of it reaches r1 only.
        $raced = false;
        $taken = [];
        foreach ($this->relays() as $index => $relay) {
            $taken[$index] = 0;
            $relay->onEvent = function (Event $event) use (&$raced, $author, $start): void {
                if ($raced || $event->pubkey !== $author) {
                    return;
                }
                $raced = true;
                $this->everywhere($this->leaseBy('F1-C', $this->lot[0], $start, 60));
            };
            $relay->refuseEvent = static function (Event $event) use ($index, &$taken, $author, $d): ?string {
                return $index !== 0 && $event->pubkey === $author && $event->tag('d') === $d && $taken[$index]++ > 0 ? 'blocked: rate-limited' : null;
            };
        }
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'F1-R', 'sats' => '49000'])));
        $this->reset();
        self::assertSame(['withdrawn'], $this->leaseKinds($r1, $d, $author));
        self::assertSame(['lease'], $this->leaseKinds($r2, $d, $author));
        self::assertSame(['lease'], $this->leaseKinds($r3, $d, $author));
        // The other lease over; R's still running on r2 and r3.
        $this->world->now = $start + 600;
        $r1->queryDelayMs = 500;
        $again = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'F1-R', 'sats' => '49000']));
        // R's lease was never confirmed: it gives R no address, and still holds it where it is.
        self::assertInstanceOf(OnchainUnavailableException::class, $again);
        $r1->queryDelayMs = 0;
        $r2->queryDelayMs = 500;
        $other = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'F1-D', 'sats' => '49000']));
        // Read on r1 and r3, R's withdrawal is its newest record: the address is free for D.
        self::assertInstanceOf(Invoice::class, $other);
        self::assertSame($this->lot[0], $other->onchainAddress);
    }

    public function testF2AnInvoiceShownAndPaidKeepsItsAddressWhenTheOrderAsksAgain(): void
    {
        $this->world->lot([$this->lot[0], $this->lot[1]]);
        [$r1] = $this->relays();
        $client = $this->client();
        $a = $client->createInvoice(['ref' => 'F2-A', 'sats' => '49000', 'durationSeconds' => 900]);
        $x = $a->onchainAddress;
        self::assertNotNull($x);
        $this->broadcast($x, self::payment($x, 49000));
        $status = $client->getStatus($a);
        self::assertSame(['unconfirmed', '49000'], [$status->state, $status->received]);
        // Another server's lease of x, its withdrawal on r1 only (a blip).
        $this->everywhere($this->leaseBy('F2-B', $x, $this->world->now + 1));
        $r1->take(Lease::withdrawal($this->sale('F2-B'), $this->world->registry, $x, $this->world->now + 2, TestContext::create(new TestConnector())));
        $this->world->now += 30;
        $r1->queryDelayMs = 500;
        $again = self::outcome(fn(): Invoice => $client->createInvoice(['ref' => 'F2-A', 'sats' => '49000', 'durationSeconds' => 900]));
        self::assertInstanceOf(InvoicePendingException::class, $again);
        self::assertSame($x, $again->getInvoice()->onchainAddress);
    }

    public function testG3TheLeaseAFloodKeptFromBeingConfirmedIsWithdrawn(): void
    {
        // A flood at a lease's d tag keeps the address only while it lasts.
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        $author = $this->sale('G3-1')->author;
        // Whoever watches the relays sees the new lease, and fills its d tag at once: 101 records
        // of one second, so that no read of it is complete.
        $flood = [];
        foreach ($this->relays() as $relay) {
            $relay->onEvent = function (Event $event) use (&$flood, $author, $d): void {
                if ($flood !== [] || $event->pubkey !== $author) {
                    return;
                }
                for ($i = 0; $i < 101; ++$i) {
                    $record = $this->junk($d, $this->world->now);
                    $flood[self::idOf($record)] = true;
                    $this->everywhere($record);
                }
            };
        }
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'G3-1', 'sats' => '49000'])));
        self::assertCount(101, $flood);
        foreach ($this->relays() as $relay) {
            self::assertSame(['withdrawn'], $this->leaseKinds($relay, $d, $author));
        }
        // The flood over (its records gone): the address is free at once, not at the lease's end.
        $this->reset();
        foreach ($this->relays() as $relay) {
            $relay->hide = static fn(\stdClass $event): bool => isset($flood[self::idOf($event)]);
        }
        self::assertSame($this->lot[0], $this->client()->createInvoice(['ref' => 'G3-2', 'sats' => '49000'])->onchainAddress);
    }

    public function testG4OfTwoInvoicesThatMayStillBePaidTheOneShownAndPaidWins(): void
    {
        // A mark a minority took brings back an invoice never shown.
        $this->world->lot([$this->lot[0], $this->lot[1]]);
        [$r1, $r2, $r3] = $this->relays();
        $author = $this->sale('G4-R')->author;
        // The lease's mark (its second record) reaches r1 only: NetworkException, nothing shown.
        $first = null;
        $taken = [1 => 0, 2 => 0];
        foreach ([1 => $r2, 2 => $r3] as $index => $relay) {
            $relay->refuseEvent = static function (Event $event) use ($index, &$first, &$taken, $author): ?string {
                if ($event->pubkey !== $author) {
                    return null;
                }
                $d = $event->tag('d') ?? '';
                $first ??= $d;

                return $d === $first && $taken[$index]++ > 0 ? 'blocked: rate-limited' : null;
            };
        }
        $client = $this->client();
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $client->createInvoice(['ref' => 'G4-R', 'sats' => '49000'])));
        // A confirmed lease is never withdrawn, whatever fails after: its mark included.
        self::assertSame([], $this->withdrawals((string) $first, $author));
        // Asked again while r1 is slow: its lease reads as not confirmed, so another invoice is
        // made, shown and paid.
        $this->reset();
        $r1->queryDelayMs = 500;
        $shown = $client->createInvoice(['ref' => 'G4-R', 'sats' => '49000']);
        $address = $shown->onchainAddress;
        self::assertNotNull($address);
        $r1->queryDelayMs = 0;
        $t = self::payment($address, 49000);
        $this->broadcast($address, $t);
        self::assertSame('unconfirmed', $client->getStatus($shown)->state);
        $this->mine($address, $t['txid'], $this->world->height - 5);
        // Asked again, every relay answering: the first invoice is confirmed on r1, older, and may
        // still be paid; the order was paid with the one shown. r3 answers 0.2 s late, so that the
        // first read is r1's and r2's, the majority that shows both invoices (r2 and r3 alone show
        // the one shown only, handed out as it is).
        $r3->queryDelayMs = 200;
        $again = self::outcome(fn(): Invoice => $client->createInvoice(['ref' => 'G4-R', 'sats' => '49000']));
        self::assertInstanceOf(OrderPaidException::class, $again);
        self::assertSame($address, $again->getInvoice()->onchainAddress);
    }

    public function testH1TheCallThatLosesNeverEndsTheLeaseOfTheOneThatWon(): void
    {
        // Two calls of one order at once (its lock failed: two processes, or two servers). The
        // other call, the winner, wrote its lease and its mark (its createdAt c + 1) as this one's
        // lease reached the relays, then two seconds passed; every relay answers.
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        $author = $this->sale('H1-R')->author;
        $c = $this->world->now;
        $lease = $this->leaseBy('H1-R', $this->lot[0], $c, 900, false, null, 49000);
        $mark = $this->leaseBy('H1-R', $this->lot[0], $c, 900, true, $c + 1, 49000);
        $raced = false;
        foreach ($this->relays() as $relay) {
            $relay->onEvent = function (Event $event) use (&$raced, $author, $d, $lease, $mark, $c): void {
                if ($raced || $event->pubkey !== $author || $event->tag('d') !== $d) {
                    return;
                }
                $raced = true;
                $this->everywhere($lease);
                $this->everywhere($mark);
                $this->world->now = $c + 2;
            };
        }
        $loser = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'H1-R', 'sats' => '49000']));
        $this->reset();
        self::assertTrue($raced);
        self::assertInstanceOf(OnchainUnavailableException::class, $loser);
        // It withdrew nothing (a withdrawal of the second of the mark could have replaced it):
        // the winner's mark stays the order's newest record there, on every relay.
        self::assertSame([], $this->withdrawals($d, $author));
        foreach ($this->relays() as $relay) {
            self::assertSame(['lease'], $this->leaseKinds($relay, $d, $author));
        }
        $this->world->now = $c + 30;
        // Another order gets no address; the order's invoice is still the one handed out.
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'H1-D', 'sats' => '49000'])));
        $again = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'H1-R', 'sats' => '49000']));
        self::assertInstanceOf(InvoicePendingException::class, $again);
        self::assertSame($this->lot[0], $again->getInvoice()->onchainAddress);
    }

    public function testH2TheFailedCallsServersClockThreeSecondsAhead(): void
    {
        $this->twoPending(3);
    }

    public function testH2BothOfOneSecond(): void
    {
        // The two invoices of one createdAt: sorted by address, either one last.
        $this->twoPending(0);
    }

    public function testI1AnObjectThatOnlyCarriesTheIdOfTheMarkNeverShownCountsForNothing(): void
    {
        // A relay counts for a record only with a copy of it.
        [$x, $y] = [$this->lot[0], $this->lot[1]];
        $this->world->lot([$x]);
        [$r1, $r2, $r3] = $this->relays();
        $author = $this->sale('I1-R')->author;
        $c = $this->world->now;
        // Call 1: its mark reaches r1 only, NetworkException.
        $later = static fn(Event $event): ?string => $event->pubkey === $author && $event->createdAt > $c ? 'error: down' : null;
        $r2->refuseEvent = $later;
        $r3->refuseEvent = $later;
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'I1-R', 'sats' => '49000'])));
        // Call 2: r1 slow and refusing the order's records; its invoice, on y, is shown.
        $this->world->lot([$x, $y], $this->world->now - 800);
        $this->world->now = $c + 30;
        $this->reset();
        $r1->queryDelayMs = 500;
        $r1->refuseEvent = static fn(Event $event): ?string => $event->pubkey === $author ? 'error: down' : null;
        $shown = $this->client()->createInvoice(['ref' => 'I1-R', 'sats' => '49000']);
        self::assertSame($y, $shown->onchainAddress);
        $markX = $this->recordAt($r1, $author, $x);
        $markY = $this->recordAt($r2, $author, $y);
        // Call 3: r3 hides y's mark, and sends an object that only carries x's mark's id; it
        // answers 0.2 s late, so that the first read is r1's and r2's, the majority that shows
        // both invoices (r1 and r3 alone show x's only, handed out as it is), and the read on
        // every relay is the one it takes part in.
        $this->reset();
        $r3->queryDelayMs = 200;
        $r3->hide = static fn(\stdClass $event): bool => $event->id === $markY->id;
        $fake = Json::decode(Json::encode($markX->toJson()));
        \assert($fake instanceof \stdClass);
        $fake->content = 'not the event';
        $fake->sig = str_repeat('00', 64);
        $r3->store($fake);
        $this->world->now = $c + 60;
        $again = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'I1-R', 'sats' => '49000']));
        self::assertInstanceOf(InvoicePendingException::class, $again);
        self::assertSame($y, $again->getInvoice()->onchainAddress);
    }

    public function testS6WitnessesReadAgainOnEveryRelayStillShowAnotherOrdersWitness(): void
    {
        // A relay hides another order's witness: the witnesses read again on every relay still
        // show it, and the transaction counts for nothing here.
        $this->world->lot([$this->lot[0]]);
        $this->world->payRequest['maxSendable'] = 4000000000;
        $client = $this->client(['relayTimeoutMs' => 4000]);
        $p = $client->createInvoice(['ref' => 'W6-P', 'sats' => '49000']);
        $x = $p->onchainAddress;
        self::assertNotNull($x);
        $t = self::payment($x, 49000);
        // Another order's witness of t, in time for it: on r1 (which hides it) and r2 (late), not r3.
        $witness = Witness::event(WitnessKeys::of($this->world->integration()), $x, $t['txid'], $this->sale('W6-N')->author, 49000, $t['inputs'], false, $p->expiresAt, $this->world->now, TestContext::create(new TestConnector()));
        [$r1, $r2] = $this->relays();
        $r1->take($witness);
        $r2->take($witness);
        $r1->hide = static fn(\stdClass $event): bool => $event->id === $witness->id;
        $r2->queryDelayMs = 1500;
        $this->broadcast($x, $t);
        // The first read (r1 and r3) misses it, so this check publishes its own witness; read
        // again on every relay, r2 shows the other's: t is another invoice's, and counts for
        // nothing here.
        $status = $client->getStatus($p);
        self::assertSame(['open', '0'], [$status->state, $status->received]);
    }

    public function testR6TwoConfirmationsOnEveryRelayOneRelayMuteOneTimeoutInAll(): void
    {
        // A relay that failed is waited for once per call.
        $this->world->lot([$this->lot[0], $this->lot[1]]);
        [$r1, $r2, $r3] = $this->relays();
        $author = $this->sale('R6-R')->author;
        // Each lease of the order meets another server's lease of its address at once: not confirmed.
        $competing = [];
        foreach ([$r1, $r2] as $relay) {
            $relay->onEvent = function (Event $event) use (&$competing, $author, $r1, $r2): void {
                $d = $event->tag('d');
                if ($event->pubkey !== $author || $d === null || isset($competing[$d])) {
                    return;
                }
                $competing[$d] = true;
                foreach ($this->lot as $address) {
                    if ($this->registryAddress($address) === $d) {
                        $other = $this->leaseBy("R6-OTHER-{$address}", $address);
                        $r1->take($other);
                        $r2->take($other);
                    }
                }
            };
        }
        $r3->mute = true;
        $started = microtime(true);
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client(['relayTimeoutMs' => 2000])->createInvoice(['ref' => 'R6-R', 'sats' => '49000'])));
        $seconds = microtime(true) - $started;
        self::assertCount(2, $competing);
        // The first confirmation waits for r3 until its timeout (2 s); the second counts it failed
        // at once: well under two timeouts, whatever the machine.
        self::assertLessThan(TestTime::seconds(3.5), $seconds);
    }

    public function testR7ARelayThatHoldsTheLeaseStillPagingAtTheCutStillShowsIt(): void
    {
        // A read on every relay keeps the pages a relay sent before the cut.
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        [, $h, $r2] = $this->relays();
        // Anybody's records at the address's d tag, older than the lease: 20 pages to read.
        for ($i = 0; $i < 1950; ++$i) {
            $record = $this->junk($d, $this->world->now - 10 - $i);
            foreach ($this->relays() as $relay) {
                $relay->store($record);
            }
        }
        $lease = $this->leaseBy('R7-A', $this->lot[0]);
        // r1 missed the lease; h hides it; r2 answers each page in 150 ms, its 20 pages outlasting
        // the one timeout the read waits after a majority.
        $h->take($lease);
        $r2->take($lease);
        $h->hide = static fn(\stdClass $event): bool => $event->id === $lease->id;
        $r2->queryDelayMs = 150;
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client(['relayTimeoutMs' => 1000])->createInvoice(['ref' => 'R7-D', 'sats' => '49000'])));
    }

    public function testR8ARelayStillReadingWhenTheConfirmationGoesOnHasNotAnswered(): void
    {
        // Of a read on every relay, only a relay whose read ended answered (SPEC.md §13): one
        // failed, one read incompletely, one still reading is one answer of three, `network`,
        // never a lease judged not confirmed on what fewer than a majority said.
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        [$r1, $r2, $r3] = $this->relays();
        // Anybody's records at the address's d tag, older than the lease: a full first page.
        for ($i = 0; $i < 150; ++$i) {
            $this->everywhere($this->junk($d, $this->world->now - 10 - $i));
        }
        $author = $this->sale('R8')->author;
        // The order's lease confirmed: r1 and r3 never answer a second page of the address's
        // leases, r3 its first one 1 s late; r2 never answers. Once r1's second page and r2's
        // first run out of time (3 s), a majority can no longer read completely: the read goes on
        // with r3, its first page sent, waiting for its second (1 s from its own deadline).
        $holds = static function (LocalRelay $relay) use ($author, $d): bool {
            foreach ($relay->events as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->pubkey === $author && $event->tag('d') === $d) {
                    return true;
                }
            }

            return false;
        };
        foreach ([$r1, $r3] as $relay) {
            $relay->onQuery = static function (\stdClass $filter, LocalRelay $relay) use ($d, $holds, $r3): void {
                $leases = Json::get($filter, '#d');
                $confirming = \is_array($leases) && \in_array($d, $leases, true) && $holds($relay);
                $relay->queryDelayMs = !$confirming ? 0 : (isset($filter->until) ? 60000 : ($relay === $r3 ? 1000 : 0));
            };
        }
        $r2->mute = true;
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $this->client(['relayTimeoutMs' => 3000])->createInvoice(['ref' => 'R8', 'sats' => '49000'])));
    }

    public function testF3ARecordWithoutTheFieldsOfAWitnessNamesNoAddress(): void
    {
        // A record of the seen author without `expiresAt` nor the address tag, as a draft of the
        // specification wrote witnesses: it is no witness (SPEC.md §31, Witnesses, "Opening
        // one"), so it names no address, and the address is leased again once its lease ended.
        $this->world->lot([$this->lot[0]]);
        $this->world->payRequest['maxSendable'] = 4000000000;
        $client = $this->client();
        $p = $client->createInvoice(['ref' => 'F3-P', 'sats' => '49000', 'durationSeconds' => 600]);
        $x = $p->onchainAddress;
        self::assertNotNull($x);
        $t = self::payment($x, 49000);
        // No `expiresAt`, its order tag alone.
        $keys = WitnessKeys::of($this->world->integration());
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $sale = $this->sale('F3-P')->author;
        $value = Witness::value($x, $t['txid'], $sale, 49000, $t['inputs'], false, null);
        unset($value['expiresAt']);
        $this->everywhere(SealedRecord::seal(
            Protocol::WITNESS_TYPE,
            $value,
            $keys->seenSecret->reveal(),
            $keys->encryptionKey->reveal(),
            Derive::witnessAddress($seenAddressKey, $x, $t['txid'], $sale),
            $this->world->now + 10,
            static fn(int $bytes): string => random_bytes(max(1, $bytes)),
            [['o', Derive::orderTag($seenAddressKey, $x, $sale)]],
        ));
        $this->world->now = $p->expiresAt + 120 + 301;
        $n = $client->createInvoice(['ref' => 'F3-N', 'sats' => '49000', 'durationSeconds' => 600]);
        self::assertSame($x, $n->onchainAddress);
    }

    public function testL1FewerThanAMajorityAnswerTheConfirmationTheMinorityShowingTheMarkNotWithdrawn(): void
    {
        // A lease not confirmed beside another call's mark (the order's lock failed).
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        $author = $this->sale('L1-R')->author;
        $mark = $this->leaseBy('L1-R', $this->lot[0], $this->world->now, 900, true, $this->world->now + 1, 49000);
        [$r1, $r2, $r3] = $this->relays();
        // Once this call's lease is taken, the other call's mark is everywhere, and r2 and r3
        // answer reads too late (their writes at once).
        $marked = false;
        foreach ($this->relays() as $relay) {
            $relay->onEvent = function (Event $event) use (&$marked, $author, $mark, $r2, $r3): void {
                if ($marked || $event->pubkey !== $author) {
                    return;
                }
                $marked = true;
                $this->everywhere($mark);
                $r2->queryDelayMs = 5000;
                $r3->queryDelayMs = 5000;
            };
        }
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'L1-R', 'sats' => '49000'])));
        $this->reset();
        // r1 showed the mark: no withdrawal, which could have replaced it (same second, a lower id).
        self::assertSame([], $this->withdrawals($d, $author));
        foreach ([$r1, $r2, $r3] as $relay) {
            self::assertSame(['lease'], $this->leaseKinds($relay, $d, $author));
        }
        $this->world->now += 30;
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'L1-D', 'sats' => '49000'])));
    }

    public function testL2ACopyOfTheMarkWithADamagedSignatureSentFirstHidesNoValidCopy(): void
    {
        $this->world->lot([$this->lot[0]]);
        $d = $this->registryAddress($this->lot[0]);
        $author = $this->sale('L2-R')->author;
        $mark = $this->leaseBy('L2-R', $this->lot[0], $this->world->now, 900, true, $this->world->now + 1, 49000);
        $damaged = $mark->toJson();
        $damaged->sig = ($mark->sig[0] === '0' ? '1' : '0') . substr($mark->sig, 1);
        [$r1, $r2, $r3] = $this->relays();
        // r1 sends the damaged copy alone, first; r2 and r3 the valid one, 100 ms later.
        $marked = false;
        foreach ($this->relays() as $relay) {
            $relay->onEvent = function (Event $event) use (&$marked, $author, $mark, $damaged, $r1, $r2, $r3): void {
                if ($marked || $event->pubkey !== $author) {
                    return;
                }
                $marked = true;
                $this->everywhere($mark);
                $r1->store($damaged);
                $r1->hide = static fn(\stdClass $e): bool => $e->id === $mark->id && $e->sig === $mark->sig;
                $r2->queryDelayMs = 100;
                $r3->queryDelayMs = 100;
            };
        }
        self::assertInstanceOf(OnchainUnavailableException::class, self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'L2-R', 'sats' => '49000'])));
        $this->reset();
        self::assertSame([], $this->withdrawals($d, $author));
        foreach ([$r2, $r3] as $relay) {
            self::assertSame(['lease'], $this->leaseKinds($relay, $d, $author));
        }
    }

    /**
     * Call 1 (its server's clock $skew s ahead): its mark reaches r1 only, NetworkException. Call
     * 2 (r1 slow) makes and shows another invoice. Call 3 (r2 slow) reads both: of two invoices
     * that may still be paid, the one shown is handed out.
     */
    private function twoPending(int $skew): void
    {
        [$x, $y] = [$this->lot[0], $this->lot[1]];
        $this->world->lot([$x]);
        [$r1, $r2, $r3] = $this->relays();
        $author = $this->sale('H2-R')->author;
        $c1 = $this->world->now + $skew;
        $refuse = static fn(Event $event): ?string => $event->pubkey === $author && $event->createdAt > $c1 ? 'error: down' : null;
        $r2->refuseEvent = $refuse;
        $r3->refuseEvent = $refuse;
        self::assertInstanceOf(NetworkException::class, self::outcome(fn(): Invoice => $this->client(['now' => fn(): int => $this->world->now + $skew])->createInvoice(['ref' => 'H2-R', 'sats' => '49000'])));
        $this->world->lot([$x, $y], $this->world->now - 800);
        $this->reset();
        $r1->queryDelayMs = 500;
        $shown = $this->client()->createInvoice(['ref' => 'H2-R', 'sats' => '49000']);
        self::assertSame($y, $shown->onchainAddress);
        $r1->queryDelayMs = 0;
        $r2->queryDelayMs = 500;
        $again = self::outcome(fn(): Invoice => $this->client()->createInvoice(['ref' => 'H2-R', 'sats' => '49000']));
        self::assertInstanceOf(InvoicePendingException::class, $again);
        self::assertSame($shown->onchainAddress, $again->getInvoice()->onchainAddress);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Client
    {
        return $this->world->client($options + ['relayTimeoutMs' => 1000]);
    }

    /** Every relay back to normal: no hook, no refusal, no delay, nothing hidden. */
    private function reset(): void
    {
        foreach ($this->relays() as $relay) {
            $relay->onEvent = null;
            $relay->refuseEvent = null;
            $relay->queryDelayMs = 0;
            $relay->hide = null;
        }
    }

    /** The record $relay holds by $author at the registry address of $address. */
    private function recordAt(LocalRelay $relay, string $author, string $address): Event
    {
        $d = $this->registryAddress($address);
        foreach ($relay->events as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->pubkey === $author && $event->tag('d') === $d) {
                return $event;
            }
        }
        self::fail("No record of {$author} at {$d}");
    }
}
