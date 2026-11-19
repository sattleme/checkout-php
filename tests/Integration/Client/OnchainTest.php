<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Cancellation;
use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Scope;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Nostr\Stamp;
use Sattleme\Checkout\Internal\Onchain\Acceptance;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\LeaseRecord;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\Witness;
use Sattleme\Checkout\Internal\Onchain\WitnessKeys;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Invoice;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Net\HttpResponseSpec;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * On-chain payments end to end (SPEC.md §31): the methods of an order, the lot and its leases,
 * witnesses, statuses, the rest of an underpayment, the merchant's tolerance, waitForPayment.
 */
final class OnchainTest extends TestCase
{
    private World $world;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->channel();
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    public function testTheMethodsOfAnOrderByItsAmount(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();

        // Below 1 000 sats: Lightning only, no address leased.
        $small = $client->createInvoice(['ref' => 'ORD-1', 'sats' => '999']);
        self::assertNull($small->onchainAddress);
        self::assertSame([], $this->leases($address));

        // From 1 000 sats: both, in one QR code.
        $both = $client->createInvoice(['ref' => 'ORD-2', 'sats' => '49000']);
        self::assertSame($address, $both->onchainAddress);
        self::assertNotNull($both->bolt11);
        self::assertSame("bitcoin:{$address}?amount=0.00049&lightning={$both->bolt11}", $both->paymentUri);
        self::assertSame(1, $client->merchant()->onchainAddresses);

        // Above the Lightning address's maximum: on-chain only.
        $this->world->lot([World::address()], $this->world->now - 10);
        $large = $client->createInvoice(['ref' => 'ORD-3', 'sats' => '5000000', 'durationSeconds' => 1200]);
        self::assertNull($large->bolt11);
        self::assertNull($large->paymentHash);
        self::assertNull($large->verifyUrl);
        self::assertNotNull($large->onchainAddress);
        self::assertSame($this->world->now, $large->createdAt);
        self::assertSame($this->world->now + 1200, $large->expiresAt);
        self::assertSame('bitcoin:' . $large->onchainAddress . '?amount=0.05', $large->paymentUri);
        self::assertSame([], $this->declarations('ORD-3'), 'no Lightning invoice, no declaration');
        $found = $client->findInvoices('ORD-3');
        self::assertCount(1, $found);
        self::assertSame($large->onchainAddress, $found[0]->onchainAddress);
        self::assertSame($large->expiresAt, $found[0]->expiresAt);

        // Below 1 000 sats and outside the sendable sats: no method.
        $this->world->payRequest['minSendable'] = 2000000;
        try {
            $client->createInvoice(['ref' => 'ORD-4', 'sats' => '999']);
            self::fail('No method');
        } catch (InvalidOrderException $e) {
            self::assertSame('sats', $e->getParam());
        }
    }

    public function testNoAddressForAnOrderPaidOnchainOnly(): void
    {
        $client = $this->world->client();
        // An empty lot.
        try {
            $client->createInvoice(['ref' => 'ORD-5', 'sats' => '5000000']);
            self::fail('No address to lease');
        } catch (OnchainUnavailableException $e) {
            self::assertTrue($e->isRetryable());
        }
        // The explorer failing.
        $this->world->lot([World::address()]);
        $this->world->explorerDown = true;
        try {
            $client->createInvoice(['ref' => 'ORD-5', 'sats' => '5000000']);
            self::fail('The explorer failed');
        } catch (NetworkException) {
            // Expected.
        }
        // With Lightning, the order goes on without an address.
        $invoice = $client->createInvoice(['ref' => 'ORD-6', 'sats' => '49000']);
        self::assertNull($invoice->onchainAddress);
    }

    public function testTheLeaseHoldsTheOrdersTerms(): void
    {
        $address = World::address();
        $this->world->channel(['onchainTolerance' => 500], null, $this->world->now - 100);
        $this->world->lot([$address]);
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-7', 'fiat' => ['amount' => '49', 'currency' => 'CHF'], 'note' => 'A cake']);
        self::assertSame($address, $invoice->onchainAddress);
        $sale = Sale::of($this->world->integration(), 'ORD-7');
        $leases = $this->leases($address);
        self::assertCount(3, $leases, 'on every relay');
        $opened = Lease::open($leases[0]->toJson(), $sale, $this->world->registry);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        $lease = $opened[1];
        self::assertSame(
            [$address, $invoice->sats, $invoice->bolt11, $invoice->verifyUrl, $invoice->createdAt, $invoice->expiresAt, 'A cake', 500],
            [$lease->address, $lease->sats, $lease->invoice, $lease->verifyUrl, $lease->createdAt, $lease->expiresAt, $lease->note, $lease->tolerance()],
        );
        self::assertSame(['minor' => 4900, 'digits' => 2, 'currency' => 'CHF'], $lease->fiat);
        self::assertSame($sale->takerTag, $lease->taker);
        self::assertSame($invoice->expiresAt + 120 + 300 >= $this->world->now, Lease::held([new LeaseRecord(LeaseRecord::LEASE, $invoice->expiresAt + 120, Stamp::of($lease->event))], $this->world->now));
        // Published with `confirmed` false, confirmed by a read on every relay, then marked: the
        // same lease, `confirmed` true, a second later (it replaces the first): what the relays hold.
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        $sent = [];
        foreach ($this->world->relay(0)->received as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->pubkey === $sale->author && $event->tag('d') === $registryAddress) {
                $opened = Lease::open($value, $sale, $this->world->registry);
                self::assertNotNull($opened);
                self::assertInstanceOf(Lease::class, $opened[1]);
                $sent[] = [$opened[1]->confirmed, $event->createdAt];
            }
        }
        self::assertSame([[false, $this->world->now], [true, $this->world->now + 1]], $sent);
        self::assertTrue($lease->confirmed);
    }

    public function testAnAddressAWitnessNamesIsNotLeased(): void
    {
        // A witness of the integration that names an address of the lot by its address tag (a
        // payment seen there, of an order or none; one of a newer format too): the address is not
        // leased again (SPEC.md §31, "Leasing an address", step 3), until the merchant's app takes
        // it out of the lot.
        [$paid, $newer, $free] = [World::address(), World::address(), World::address()];
        $this->world->lot([$paid, $newer, $free]);
        $keys = WitnessKeys::of($this->world->integration());
        $context = TestContext::create(new TestConnector());
        $this->world->publish(Witness::event($keys, $paid, bin2hex(random_bytes(32)), null, 1000, [bin2hex(random_bytes(32)) . ':0'], false, null, $this->world->now - 60, $context));
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $txid = bin2hex(random_bytes(32));
        $this->world->publish(SealedRecord::seal('integration-onchain-seen', ['version' => 2], $keys->seenSecret->reveal(), $keys->encryptionKey->reveal(), Derive::witnessAddress($seenAddressKey, $newer, $txid, null), $this->world->now - 60, $context->random(...), [['o', Derive::addressTag($seenAddressKey, $newer)]]));
        $client = $this->world->client();
        self::assertSame($free, $client->createInvoice(['ref' => 'ORD-1', 'sats' => '49000'])->onchainAddress);
        self::assertNull($client->createInvoice(['ref' => 'ORD-2', 'sats' => '49000'])->onchainAddress);
        self::assertSame([], $this->leases($paid));
        self::assertSame([], $this->leases($newer));
    }

    public function testAnAddressAnyEventOfTheSeenAuthorNamesIsNotLeased(): void
    {
        // Events of the seen author that check out, with an address's tag as an `o` tag, that do
        // not open as witnesses: a NIP-44 v2 content under another key, a content that is no
        // NIP-44 payload. Each names its address all the same (SPEC.md §31, "Leasing an address",
        // step 3), which is not leased; an event that does not check out names nothing.
        [$sealed, $plain, $forged, $free] = [World::address(), World::address(), World::address(), World::address()];
        $this->world->lot([$sealed, $plain, $forged, $free]);
        $keys = WitnessKeys::of($this->world->integration());
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $named = fn(string $address, string $content): Event => Event::sign($keys->seenSecret->reveal(), 30078, $this->world->now - 60, [['d', bin2hex(random_bytes(32))], ['o', Derive::orderTag($seenAddressKey, $address, null)], ['o', Derive::addressTag($seenAddressKey, $address)]], $content, random_bytes(32));
        $this->world->publish($named($sealed, Nip44::encrypt('{"t":"integration-onchain-seen","v":{"version":1}}', random_bytes(32), random_bytes(32))));
        $this->world->publish($named($plain, 'not a witness'));
        $bad = $named($forged, 'not a witness')->toJson();
        $bad->sig = str_repeat('0', 128);
        foreach ($this->world->relays->relays as $relay) {
            $relay->store($bad);
        }
        $client = $this->world->client();
        $given = [];
        foreach (['ORD-53', 'ORD-54', 'ORD-55'] as $ref) {
            $given[] = $client->createInvoice(['ref' => $ref, 'sats' => '49000'])->onchainAddress;
        }
        $leased = array_values(array_filter($given, static fn(?string $address): bool => $address !== null));
        sort($leased);
        $expected = [$forged, $free];
        sort($expected);
        self::assertSame($expected, $leased);
        self::assertNull($given[2]);
    }

    public function testAConfirmedLeaseIsNeverWithdrawnWhateverFailsAfter(): void
    {
        // Once confirmed, the lease holds its address until its end, whatever fails after it (the
        // declaration, the checkout link): a withdrawal some relays only took would leave them
        // disagreeing on whether an address that may be the order's is held (SPEC.md §31).
        [$declared, $linked] = [World::address(), World::address()];
        $client = $this->world->client();
        foreach ([[$declared, 'ORD-D', false], [$linked, 'ORD-L', true]] as [$address, $ref, $checkout]) {
            $this->world->lot([$address], $this->world->now - ($checkout ? 800 : 900));
            $sale = Sale::of($this->world->integration(), $ref);
            $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
            foreach ([1, 2] as $index) {
                // Its declaration (any record of the sale but its lease), or its checkout link (any
                // event of another key), refused by two relays of three.
                $this->world->relay($index)->refuseEvent = $checkout
                    ? static fn(Event $event): ?string => $event->pubkey !== $sale->author ? 'blocked: test' : null
                    : static fn(Event $event): ?string => $event->pubkey === $sale->author && $event->tag('d') !== $registryAddress ? 'blocked: test' : null;
            }
            try {
                $client->createInvoice(['ref' => $ref, 'sats' => '49000', 'checkout' => $checkout]);
                self::fail('network expected');
            } catch (NetworkException) {
            }
            foreach ([1, 2] as $index) {
                $this->world->relay($index)->refuseEvent = null;
            }
            self::assertSame(['ok', 'ok', 'ok'], $this->recordsOf($sale, $address), $ref);
            foreach ($this->leases($address) as $event) {
                $opened = Lease::open($event->toJson(), $sale, $this->world->registry);
                self::assertNotNull($opened);
                self::assertInstanceOf(Lease::class, $opened[1]);
                self::assertTrue($opened[1]->confirmed, $ref);
            }
            // Another order does not get it.
            self::assertNull($client->createInvoice(['ref' => $ref . '-OTHER', 'sats' => '49000'])->onchainAddress, $ref);
        }
    }

    public function testTwoServersLeasingOneAddressAtOnce(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        // Another server leases it at once: its lease reaches every relay with ours.
        $other = Sale::of($this->world->integration(), 'ORD-OTHER');
        $context = TestContext::create(new TestConnector());
        $injected = false;
        $mine = Sale::of($this->world->integration(), 'ORD-8')->author;
        foreach ($this->world->relays->relays as $relay) {
            $relay->onEvent = function (Event $event) use (&$injected, $other, $address, $context, $mine): void {
                if ($injected || $event->pubkey !== $mine) {
                    return;
                }
                $injected = true;
                $inside = Lease::inside($address, 1000, null, null, $this->world->now, $this->world->now + 900, $other->keyHex(), ['minor' => 1000, 'digits' => 0, 'currency' => 'SAT'], 'Theirs', $other->takerTag, 0);
                $this->world->publish(Lease::event($other, $this->world->registry, $address, $inside, $this->world->now, $context));
            };
        }
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-8', 'sats' => '49000']);
        self::assertNull($invoice->onchainAddress, 'the later read saw both: ours withdrawn');
        $sale = Sale::of($this->world->integration(), 'ORD-8');
        $records = [];
        foreach ($this->leases($address) as $event) {
            $opened = Lease::open($event->toJson(), $sale, $this->world->registry);
            if ($opened !== null) {
                $records[] = $opened[0];
            }
        }
        self::assertContains('withdrawn', $records);
        self::assertNotContains('ok', $records);
        // The other server's lease holds it.
        self::assertCount(3, array_filter($this->leases($address), static fn(Event $e): bool => $e->pubkey === $other->author));
    }

    public function testOnRelaysThatAskForItTheClientAuthenticatesAsNip42Has(): void
    {
        // SPEC.md §13, NIP-42: on relays that ask every connection to authenticate, each read and
        // each write authenticates with its own key as ["AUTH", <event>] (the local relays refuse
        // one sent in an EVENT): an event of kind 22242, `created_at` now, of the relay and its
        // challenge, its content empty. An invoice with an address is made, underpaid and checked:
        // the sale's records, a witness, an invoice of the rest and its record are written so.
        $address = World::address();
        $this->world->lot([$address]);
        foreach ($this->world->relays->relays as $relay) {
            $relay->requireAuth = true;
        }
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-64', 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        $this->pay($address, 20000);
        $status = $client->getStatus($invoice);
        self::assertSame([PaymentStatus::OPEN, '20000'], [$status->state, $status->received]);
        self::assertNotNull($status->rest?->bolt11, 'an invoice of the rest asked and recorded');
        $authors = [Sale::of($this->world->integration(), 'ORD-64')->author, WitnessKeys::of($this->world->integration())->seenAuthor];
        foreach ($this->world->relays->relays as $url => $relay) {
            self::assertNotSame([], $relay->authentications, $url);
            self::assertCount(\count($relay->authentications), $relay->authenticated, "{$url}: every authentication taken");
            foreach ($relay->authentications as $value) {
                $event = Event::fromJson($value);
                self::assertNotNull($event, $url);
                self::assertSame([22242, $url, $this->world->now, ''], [$event->kind, $event->tag('relay'), $event->createdAt, $event->content], $url);
            }
            foreach ($authors as $author) {
                self::assertContains($author, $relay->authenticated, "{$url}: its records written authenticated by their key");
            }
            self::assertSame([], array_filter($relay->received, static fn(\stdClass $event): bool => $event->kind === 22242), "{$url}: no authentication in an EVENT");
            self::assertContains('AUTH', $relay->messages, $url);
        }
    }

    public function testAnInvoiceItsLeaseCannotHoldIsRefusedBeforeAnythingIsPublished(): void
    {
        // The lease seals the invoice twice, as the declaration does, with some 150 bytes more: an
        // invoice of 40 550 characters fits a declaration (its inside within NIP-44's padded 40 960
        // bytes) and not a lease (padded to 49 152 bytes, too large once sealed again). With
        // on-chain, invalid_invoice before anything is published (SPEC.md §17, step 6), whatever
        // the lot holds: none of its addresses, one paid already (its witness `none` would be
        // published), or one free.
        $this->world->invoiceLength = 40550;
        $client = $this->world->client();
        $paid = World::address();
        $this->pay($paid, 20000);
        foreach (['no lot' => [], 'its address paid' => [$paid], 'its address free' => [World::address()]] as $lot => $addresses) {
            if ($addresses !== []) {
                ++$this->world->now; // A newer lot than the last one.
                $this->world->lot($addresses, $this->world->now - 1);
            }
            $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
            $asked = \count($this->world->invoices);
            try {
                $client->createInvoice(['ref' => 'ORD-58', 'sats' => '49000']);
                self::fail("An invoice its lease cannot hold, {$lot}");
            } catch (InvalidInvoiceException $e) {
                self::assertSame('The invoice\'s lease does not fit a NIP-44 plaintext', $e->getMessage(), $lot);
            }
            self::assertCount($asked + 1, $this->world->invoices, "its invoice asked, {$lot}");
            self::assertSame($sent, array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays), "nothing published, {$lot}");
        }

        // Below the on-chain floor, Lightning only: its declaration holds it.
        $invoice = $client->createInvoice(['ref' => 'ORD-59', 'sats' => '500']);
        self::assertSame(40550, \strlen((string) $invoice->bolt11));
        self::assertNull($invoice->onchainAddress);
    }

    public function testALeaseIsMeasuredAsFirstPublished(): void
    {
        // SPEC.md §17, step 6: the lease is measured as first published, `"confirmed": false`
        // (the longer of its two forms), with a deposit address of 62 characters, and the client's
        // random bytes are left alone. An invoice whose lease fits with `"confirmed": true` but
        // not with false is invalid_invoice once the invoice is asked, before anything else is
        // asked or published: no relay is read, no random byte drawn but the ids of the queries
        // made before.
        $this->world->lot([World::address(), World::address(), World::address()]);
        $client = $this->world->client();
        // The inside of a lease as the relays hold it: its mark, `"confirmed": true`.
        $sale = fn(string $ref): Sale => Sale::of($this->world->integration(), $ref);
        $insideOf = function (string $address, Sale $sale): string {
            $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
            $events = array_values(array_filter($this->leases($address), static fn(Event $event): bool => $event->pubkey === $sale->author));
            self::assertNotSame([], $events);
            $record = SealedRecord::open($events[0]->toJson(), Protocol::LEASE_TYPE, $registryAddress, $sale->author, Derive::outerKey($this->world->registry->key->reveal(), $registryAddress, $sale->author));
            self::assertNotNull($record);
            $sealed = Json::get($record->value, 'sealed');
            self::assertIsString($sealed);

            return Nip44::decrypt($sealed, $sale->innerKey($this->world->registry));
        };
        $this->world->invoiceLength = 30000;
        $first = $client->createInvoice(['ref' => 'ORD-70', 'sats' => '49000'])->onchainAddress;
        self::assertNotNull($first);
        $marked = $insideOf($first, $sale('ORD-70'));
        self::assertStringEndsWith(',"confirmed":true}', $marked);
        // The invoice length at which the mark's inside is 40 960 bytes, the most NIP-44 pads to
        // 40 960 (one more byte pads to 49 152, too large once sealed again): its first form, one
        // byte longer, does not fit.
        $boundary = 30000 + 40960 - \strlen($marked);
        $this->world->invoiceLength = $boundary - 1;
        $second = $client->createInvoice(['ref' => 'ORD-71', 'sats' => '49000'])->onchainAddress;
        self::assertNotNull($second, 'its first form of 40 960 bytes fits');
        self::assertSame(40959, \strlen($insideOf($second, $sale('ORD-71'))));

        $this->world->invoiceLength = $boundary;
        $draws = [];
        $counted = $this->world->client(['random' => static function (int $bytes) use (&$draws): string {
            $draws[] = $bytes;

            return $bytes > 0 ? random_bytes($bytes) : '';
        }]);
        $queries = static fn(LocalRelay $relay): int => \count(array_keys($relay->messages, 'REQ', true));
        $before = array_map($queries, $this->world->relays->relays);
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        $atInvoice = null;
        $this->world->onRequest = function (string $host, HttpRequest $request) use (&$atInvoice, $queries): void {
            if ($host === 'breez.tips' && $request->path() === '/lnurlp/shop/invoice') {
                $atInvoice = array_map($queries, $this->world->relays->relays);
            }
        };
        try {
            $counted->createInvoice(['ref' => 'ORD-72', 'sats' => '49000']);
            self::fail('A lease that fits only as its mark');
        } catch (InvalidInvoiceException $e) {
            self::assertSame('The invoice\'s lease does not fit a NIP-44 plaintext', $e->getMessage());
        }
        $this->world->onRequest = null;
        Loop::get()->run(static fn() => Loop::get()->sleepUntil(TestTime::deadline(0.2))); // For the relays to read what came.
        self::assertSame($sent, array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays), 'nothing published');
        self::assertSame($atInvoice, array_map($queries, $this->world->relays->relays), 'no relay read once the invoice was asked');
        $made = array_sum(array_map($queries, $this->world->relays->relays)) - array_sum($before);
        self::assertGreaterThan(0, $made);
        self::assertSame(array_fill(0, $made, 8), $draws, 'a subscription id for each query, nothing else');
    }

    public function testALeasedAddressIsNotLeasedAgain(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-9', 'sats' => '49000'])->onchainAddress);
        self::assertNull($client->createInvoice(['ref' => 'ORD-10', 'sats' => '49000'])->onchainAddress);
    }

    public function testALeaseAMajorityRefusedIsWithdrawnWhereItWasTaken(): void
    {
        // An order paid on-chain only (above the Lightning address's 4 000 000 sats): its lease is
        // all its pending invoice would be.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $sale = Sale::of($this->world->integration(), 'ORD-X');
        foreach ([1, 2] as $index) {
            $this->world->relay($index)->refuseEvent = fn(Event $event): ?string => $event->pubkey === $sale->author
                && (Lease::open($event->toJson(), $sale, $this->world->registry)[0] ?? null) === 'ok' ? 'blocked: test' : null;
        }
        try {
            $client->createInvoice(['ref' => 'ORD-X', 'sats' => '5000000']);
            self::fail('network expected');
        } catch (NetworkException) {
        }
        // Withdrawn where it was taken (and on the others, which take a withdrawal).
        self::assertSame(['withdrawn', 'withdrawn', 'withdrawn'], $this->recordsOf($sale, $address));
        foreach ([1, 2] as $index) {
            $this->world->relay($index)->refuseEvent = null;
        }

        // Another order leases it, even reading the relay that took the lease; the first order,
        // tried again, has no pending invoice holding that address.
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-Y', 'sats' => '5000000'])->onchainAddress);
        $this->expectException(OnchainUnavailableException::class);
        $client->createInvoice(['ref' => 'ORD-X', 'sats' => '5000000']);
    }

    public function testRecordsAnyoneWritesAtALeasesAddressHideNoLease(): void
    {
        // Order A leases the address; then anyone writes records at its registry address (any
        // key: relays take them), newer than the lease, large (11 MB in all, past the bound of a
        // read): they open as nothing and take none of it, and the lease is read past them: order
        // B does not get the address. Each relay sends them in the read of the leases, beside
        // the read of named addresses on its connection: 10 s a request, however busy the machine.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client(['relayTimeoutMs' => 10000]);
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $this->world->now += 60;
        $this->junk($address, 20, 420000);
        $invoice = $client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000']);
        self::assertNotNull($invoice->bolt11);
        self::assertNull($invoice->onchainAddress);
    }

    public function testCopiesOfALeaseUnderIdsOfTheirOwnPileNothingUp(): void
    {
        // Order A leases the address. A relay then sends, with its records, 40 copies of that lease
        // under ids of their own: another date and a large tag each, its content and signature as
        // they are. Each opens as the lease does (a lease opens without its signature): of each, a
        // read keeps its id, author, date and signature and what holding needs of its outside,
        // never its tags nor its content (SPEC.md §13, Limits). Order B does not get the address.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $sale = Sale::of($this->world->integration(), 'ORD-A');
        $lease = array_values(array_filter($this->leases($address), static fn(Event $event): bool => $event->pubkey === $sale->author))[0];
        $copies = [];
        for ($i = 1; $i <= 40; ++$i) {
            $value = $lease->toJson();
            $value->created_at = $lease->createdAt - $i;
            $value->tags = [...$lease->tags, ['x', str_repeat('a', 600000)]];
            $value->id = hash('sha256', Json::encode([0, $value->pubkey, $value->created_at, $value->kind, $value->tags, $value->content]));
            $copies[] = Json::encode($value);
        }
        $this->world->relay(0)->extraEvents = static fn(): array => $copies;
        $this->world->relay(1)->queryDelayMs = $this->world->relay(2)->queryDelayMs = 300;
        $this->world->now += 60;
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $invoice = $client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000']);
        self::assertLessThan(12 * 1048576, memory_get_peak_usage() - $before, '24 MB when each copy is kept as sent');
        self::assertNull($invoice->onchainAddress);
        self::assertNotNull($invoice->bolt11);
    }

    public function testALeaseReadNotToItsEndLeasesNoAddress(): void
    {
        // So many records at a leased address, of one second newer than its lease, that no read
        // of it is complete (a full page of them, then that page again): its lease unseen, it
        // looks free, but no other order's lease of it is ever confirmed, so none gets it; the
        // lot's other address, read completely, still is leased. Never `network` for what anyone
        // can write.
        $this->world->lot([World::address(), World::address()]);
        $client = $this->world->client();
        $leased = $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress;
        self::assertNotNull($leased);
        $this->world->now += 60;
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $leased);
        for ($i = 0; $i < 101; ++$i) {
            $record = $this->unsigned($this->world->now, $registryAddress);
            foreach ($this->world->relays->relays as $relay) {
                $relay->events[] = $record;
            }
        }
        $invoice = $client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000']);
        self::assertNotNull($invoice->bolt11);
        self::assertNotNull($invoice->onchainAddress);
        self::assertNotSame($leased, $invoice->onchainAddress);
        // On-chain only, the leased address tried again: onchain_unavailable.
        try {
            $client->createInvoice(['ref' => 'ORD-C', 'sats' => '5000000']);
            self::fail('onchain_unavailable expected');
        } catch (OnchainUnavailableException) {
        }
        // Order A's lease still holds the address, marked; order C's was withdrawn.
        $sale = Sale::of($this->world->integration(), 'ORD-A');
        $ofA = array_values(array_filter($this->leases($leased), static fn(Event $event): bool => $event->pubkey === $sale->author));
        self::assertCount(3, $ofA);
        foreach ($ofA as $event) {
            $opened = Lease::open($event->toJson(), $sale, $this->world->registry);
            self::assertNotNull($opened);
            self::assertInstanceOf(Lease::class, $opened[1]);
            self::assertTrue($opened[1]->confirmed);
        }
        self::assertSame(['withdrawn', 'withdrawn', 'withdrawn'], $this->recordsOf(Sale::of($this->world->integration(), 'ORD-C'), $leased));
    }

    public function testRecordsOfALeasesSecondHideNoLease(): void
    {
        // Records at a lease's registry address of its very second, before it in a relay's order
        // (NIP-01: the lowest id first), as many as a page holds (100): no page reaches the lease
        // past them, so no address is known free.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        $lease = null;
        foreach ($this->world->relay(0)->events as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->tag('d') === $registryAddress) {
                $lease = $event;
            }
        }
        self::assertNotNull($lease);
        $this->world->now += 60;
        for ($written = 0; $written < 100;) {
            $id = bin2hex(random_bytes(32));
            if (strcmp($id, $lease->id) < 0) {
                $record = $this->unsigned($lease->createdAt, $registryAddress, $id);
                foreach ($this->world->relays->relays as $relay) {
                    $relay->events[] = $record;
                }
                ++$written;
            }
        }
        foreach ($this->world->relays->relays as $relay) {
            $relay->cap = 100;
        }
        $invoice = $client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000']);
        self::assertNotNull($invoice->bolt11);
        self::assertNull($invoice->onchainAddress);
    }

    public function testRecordsOfNoUseToPagingHideNoLease(): void
    {
        // Records at a lease's registry address dated past 2^53 (a relay need not refuse them), as
        // many as a page holds (100): no events, they say nothing of where the next page starts,
        // so the read does not reach the lease: no address is known free.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        $this->world->now += 60;
        for ($i = 0; $i < 100; ++$i) {
            $record = $this->unsigned(9007199254740992 + $i, $registryAddress);
            foreach ($this->world->relays->relays as $relay) {
                $relay->events[] = $record;
            }
        }
        foreach ($this->world->relays->relays as $relay) {
            $relay->cap = 100;
        }
        self::assertNull($client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000'])->onchainAddress);
    }

    public function testRecordsOfANewerNip44VersionHoldNothingAndCostNoSignatureCheck(): void
    {
        // Records whose content is of a newer NIP-44 version (no MAC to check) at an address of
        // the lot, each by a key of its own, 150 on each relay: anybody can write them at that d
        // tag, so they are ignored there (SPEC.md §31, "Opening a lease"): the address is leased,
        // and none costs a signature check (450 would take seconds).
        $address = World::address();
        $this->world->lot([$address]);
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        for ($i = 0; $i < 150; ++$i) {
            $this->world->publish(Event::sign(random_bytes(31) . "\x01", 30078, $this->world->now - 1 - $i, [['d', $registryAddress]], '#', random_bytes(32)));
        }
        $started = Loop::now();
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-1', 'sats' => '49000']);
        self::assertLessThan(TestTime::seconds(2.0), Loop::now() - $started);
        self::assertNotNull($invoice->bolt11);
        self::assertSame($address, $invoice->onchainAddress, 'a content of a newer NIP-44 version holds nothing');
    }

    public function testARecordOfNoUseHoldsNothing(): void
    {
        // Records anyone writes at the addresses of a quiet lot: one that does not open, one whose
        // content is of a newer NIP-44 version (any key): no full page, nothing out of reach, and
        // neither holds its address. One of a newer format (an outside the outer key seals, a
        // `version` above 1) holds its address alone (SPEC.md §31).
        $addresses = [World::address(), World::address(), World::address()];
        $this->world->lot($addresses);
        $this->junk($addresses[0], 1, 100);
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $addresses[1]);
        $newer = Event::sign(random_bytes(31) . "\x01", 30078, $this->world->now - 5, [['d', $registryAddress]], '#', random_bytes(32));
        foreach ($this->world->relays->relays as $relay) {
            $relay->store($newer);
        }
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $addresses[2]);
        $later = Sale::of($this->world->integration(), 'ORD-LATER');
        $context = TestContext::create(new TestConnector());
        $newerFormat = SealedRecord::seal('registry-onchain', ['version' => 2], $later->secret->reveal(), Derive::outerKey($this->world->registry->key->reveal(), $registryAddress, $later->author), $registryAddress, $this->world->now - 5, $context->random(...));
        foreach ($this->world->relays->relays as $relay) {
            $relay->store($newerFormat);
        }
        $client = $this->world->client();
        $leased = [];
        foreach (['ORD-1', 'ORD-2', 'ORD-3'] as $ref) {
            $leased[] = $client->createInvoice(['ref' => $ref, 'sats' => '49000'])->onchainAddress;
        }
        $given = array_values(array_filter($leased, static fn(?string $address): bool => $address !== null));
        sort($given);
        $expected = [$addresses[0], $addresses[1]];
        sort($expected);
        self::assertSame($expected, $given, 'the address of the newer format never leased');
        self::assertNull($leased[2]);
        // The record of a newer format warned of at each lease (SPEC.md §12), those that do not
        // open never.
        self::assertSame(array_fill(0, 3, "A lease of the integration's addresses has a newer format than this version of sattleme/checkout: update it"), $this->world->warnings);
    }

    public function testRelaysThatSendTheOldestFirstHideNoLease(): void
    {
        // Without a limit, a relay may send every match, the oldest first (nostr-rs-relay): 1000
        // records older than a lease, in its window, would be all a query reads.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        foreach ($this->world->relays->relays as $relay) {
            $relay->oldestFirstWithoutLimit = true;
            for ($i = 0; $i < 1000; ++$i) {
                $relay->events[] = $this->unsigned($this->world->now - 7000 + $i, $registryAddress);
            }
        }
        $this->world->now += 60;
        $invoice = $client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000']);
        self::assertNotNull($invoice->bolt11);
        self::assertNull($invoice->onchainAddress);
    }

    public function testAWithdrawalWhoseSignatureFailsFreesNothing(): void
    {
        // A withdrawal of order A's lease, sealed for its sale (whoever holds the registry key can
        // seal one), newer than the lease, its signature not the sale's: it ends nothing.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $sale = Sale::of($this->world->integration(), 'ORD-A');
        $withdrawal = Lease::withdrawal($sale, $this->world->registry, $address, $this->world->now + 1, TestContext::create(new TestConnector()))->toJson();
        $withdrawal->sig = bin2hex(random_bytes(64));
        foreach ($this->world->relays->relays as $relay) {
            $relay->events[] = $withdrawal;
        }
        $this->world->now += 60;
        self::assertNull($client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000'])->onchainAddress);
    }

    public function testARecordClaimingALeasesIdHidesNothing(): void
    {
        // A withdrawal sealed with the registry key under a key of its own, given order A's lease's
        // id, from one relay that answers first (honest relays refuse such an event): it does not
        // stand in for the lease, which the other relays send.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $registryKey = $this->world->registry->key->reveal();
        $registryAddress = Derive::depositRegistryAddress($registryKey, $address);
        $lease = null;
        foreach ($this->world->relay(0)->events as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->tag('d') === $registryAddress) {
                $lease = $event;
            }
        }
        self::assertNotNull($lease);
        $secret = random_bytes(31) . "\x01";
        $decoy = SealedRecord::seal('registry-onchain', ['version' => 1, 'withdrawn' => true], $secret, Derive::outerKey($registryKey, $registryAddress, Derive::author($secret)), $registryAddress, $lease->createdAt + 10, static fn(int $k): string => random_bytes(max(1, $k)))->toJson();
        $decoy->id = $lease->id;
        $this->world->relay(0)->events[] = $decoy;
        $this->world->relay(1)->queryDelayMs = 80;
        $this->world->relay(2)->queryDelayMs = 80;
        $this->world->now += 60;
        self::assertNull($client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000'])->onchainAddress);
    }

    public function testABusyMerchantsLeasesCostNoSignatureCheck(): void
    {
        // 300 sales' records in the last two hours, leases ended or withdrawn: no address held by
        // them, nothing to check (each check takes 3 to 5 ms: 300, past a relay's 1 s timeout).
        $lot = [World::address(), World::address()];
        $this->world->lot($lot);
        $registryKey = $this->world->registry->key->reveal();
        $records = [];
        for ($i = 0; $i < 300; ++$i) {
            $secret = random_bytes(31) . "\x01";
            $author = Derive::author($secret);
            $registryAddress = Derive::depositRegistryAddress($registryKey, $lot[$i % 2]);
            $createdAt = $this->world->now - 7000 + $i * 20;
            $value = $i % 2 === 0 ? ['version' => 1, 'withdrawn' => true] : ['version' => 1, 'end' => $createdAt + 100, 'sealed' => 'x'];
            $records[] = SealedRecord::seal('registry-onchain', $value, $secret, Derive::outerKey($registryKey, $registryAddress, $author), $registryAddress, $createdAt, static fn(int $k): string => random_bytes(max(1, $k)));
        }
        foreach ($this->world->relays->relays as $relay) {
            foreach ($records as $record) {
                $relay->store($record);
            }
        }
        $started = Loop::now();
        $invoice = $this->world->client(['relayTimeoutMs' => 1000])->createInvoice(['ref' => 'ORD-1', 'sats' => '49000']);
        self::assertNotNull($invoice->onchainAddress);
        self::assertLessThan(TestTime::seconds(1.5), Loop::now() - $started);
    }

    public function testRecordsOfALeasesSecondByAnyRegistryKeyHolderHideNoLease(): void
    {
        // Withdrawals at a lease's registry address, each by a key of its own, sealed by whoever
        // holds the registry key, of the lease's very second and before it in a relay's order, as
        // many as a page holds (100): records that open, but no page reaches the lease past them.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-A', 'sats' => '49000'])->onchainAddress);
        $registryKey = $this->world->registry->key->reveal();
        $registryAddress = Derive::depositRegistryAddress($registryKey, $address);
        $lease = null;
        foreach ($this->world->relay(0)->events as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->tag('d') === $registryAddress) {
                $lease = $event;
            }
        }
        self::assertNotNull($lease);
        for ($written = 0; $written < 100;) {
            $secret = random_bytes(31) . "\x01";
            $record = SealedRecord::seal('registry-onchain', ['version' => 1, 'withdrawn' => true], $secret, Derive::outerKey($registryKey, $registryAddress, Derive::author($secret)), $registryAddress, $lease->createdAt, static fn(int $k): string => random_bytes(max(1, $k)));
            if (strcmp($record->id, $lease->id) < 0) {
                foreach ($this->world->relays->relays as $relay) {
                    $relay->store($record);
                }
                ++$written;
            }
        }
        foreach ($this->world->relays->relays as $relay) {
            $relay->cap = 100;
        }
        $this->world->now += 60;
        self::assertNull($client->createInvoice(['ref' => 'ORD-B', 'sats' => '49000'])->onchainAddress);
    }

    public function testLeasesOfOneSecondLeaveTheNextAddressFree(): void
    {
        // Two orders lease two addresses in one second: a page of the third order's read holds
        // that second's records alone, all seen before, but leases (which only sales write): the
        // read goes on, and the third address is free.
        $this->world->lot([World::address(), World::address(), World::address()]);
        $client = $this->world->client();
        $addresses = [];
        foreach (['ORD-1', 'ORD-2', 'ORD-3'] as $ref) {
            $addresses[] = $client->createInvoice(['ref' => $ref, 'sats' => '49000'])->onchainAddress;
        }
        self::assertNotContains(null, $addresses);
        self::assertCount(3, array_unique($addresses));
    }

    public function testALeaseWhoseReadFailsIsWithdrawn(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $sale = Sale::of($this->world->integration(), 'ORD-X');
        // Once two relays took the lease, they answer no query (events still taken): the read
        // again fails.
        foreach ([1, 2] as $index) {
            $this->world->relay($index)->onEvent = function (Event $event, LocalRelay $relay) use ($sale): void {
                if ($event->pubkey === $sale->author && (Lease::open($event->toJson(), $sale, $this->world->registry)[0] ?? null) === 'ok') {
                    $relay->closeQueries = 'error: test';
                }
            };
        }
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-X', 'sats' => '5000000']);
            self::fail('network expected');
        } catch (NetworkException) {
        }
        self::assertSame(['withdrawn', 'withdrawn', 'withdrawn'], $this->recordsOf($sale, $address));
    }

    public function testAPaidAddressIsSkippedAndItsWitnessPublished(): void
    {
        $paid = World::address();
        $free = World::address();
        $txid = $this->pay($paid, 1000);
        // The paid address alone: skipped, its witness `none` written for the merchant's app.
        $this->world->lot([$paid]);
        $client = $this->world->client();
        self::assertNull($client->createInvoice(['ref' => 'ORD-11', 'sats' => '49000'])->onchainAddress);
        // With a free one, whatever the order they are tried in: the free one.
        $this->world->lot([$paid, $free], $this->world->now - 10);
        self::assertSame($free, $client->createInvoice(['ref' => 'ORD-11B', 'sats' => '49000'])->onchainAddress);
        $witnesses = $this->witnesses();
        $none = array_values(array_filter($witnesses, static fn(Witness $w): bool => $w->txid === $txid));
        self::assertCount(1, $none);
        self::assertSame([$paid, null, '1000', false], [$none[0]->address, $none[0]->sale, $none[0]->sats, $none[0]->replacement]);
    }

    public function testOnchainStatuses(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-12', 'sats' => '49000']);
        self::assertSame(['state' => 'open', 'expiresAt' => $invoice->expiresAt, 'received' => '0', 'rest' => null], $client->getStatus($invoice)->toArray());

        // In the mempool while the lease runs: unconfirmed, and its witness of the sale written.
        $this->world->now += 60;
        $txid = $this->pay($address, 49000);
        self::assertSame(['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 0, 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
        $sale = Sale::of($this->world->integration(), 'ORD-12');
        $witness = array_values(array_filter($this->witnesses(), static fn(Witness $w): bool => $w->txid === $txid));
        self::assertCount(1, $witness);
        self::assertSame([$address, $sale->author, '49000', false], [$witness[0]->address, $witness[0]->sale, $witness[0]->sats, $witness[0]->replacement]);

        // 2 confirmations: unconfirmed; 3 on the explorer read (it alone is asked): paid.
        $this->confirm($address, $txid, $this->world->height - 1);
        self::assertSame(['state' => 'unconfirmed', 'received' => '49000', 'confirmations' => 2, 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
        $this->confirm($address, $txid, $this->world->height - 2);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '49000', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());

        // Found by the order's next invoice: order_paid, without a preimage.
        $this->world->now = $invoice->expiresAt + 500;
        try {
            $client->createInvoice(['ref' => 'ORD-12', 'sats' => '49000']);
            self::fail('Paid on-chain');
        } catch (OrderPaidException $e) {
            self::assertSame('onchain', $e->getStatus()->method);
            self::assertNull($e->getPreimage());
            self::assertSame($address, $e->getInvoice()->onchainAddress);
        }
    }

    public function testAnUnconfirmedPaymentKeepsTheOrderPending(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-13', 'sats' => '49000']);
        $this->world->now += 30;
        $this->pay($address, 49000);
        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($invoice)->state);
        $this->world->now = $invoice->expiresAt + 500;
        try {
            $client->createInvoice(['ref' => 'ORD-13', 'sats' => '49000']);
            self::fail('Being confirmed');
        } catch (InvoicePendingException $e) {
            self::assertSame($address, $e->getInvoice()->onchainAddress);
            self::assertNull($e->getCheckoutUrl());
        }
    }

    public function testOneTransactionPayingTwoOrdersPaysEachItsOwnOutput(): void
    {
        // A wallet paying two orders at once (seen live: 5 000 and 4 000 sats, and its change).
        $this->world->lot([World::address(), World::address()]);
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-40', 'sats' => '5000']);
        $second = $client->createInvoice(['ref' => 'ORD-41', 'sats' => '6000']);
        [$a, $b] = [(string) $first->onchainAddress, (string) $second->onchainAddress];
        self::assertNotSame($a, $b);
        $this->world->now += 60;
        $txid = bin2hex(random_bytes(32));
        $transaction = World::transaction($txid, [bin2hex(random_bytes(32)) . ':0'], [[4000, $b], [5000, $a], [12427, World::address()]]);
        $this->world->transactions[$a] = [$transaction];
        $this->world->transactions[$b] = [$transaction];

        // Each order counts what the transaction pays its own address, never the other's: checked
        // twice each, the other order's witness in between changes nothing.
        foreach ([1, 2] as $round) {
            self::assertSame(['state' => 'unconfirmed', 'received' => '5000', 'confirmations' => 0, 'txids' => [$txid]], $client->getStatus($first)->toArray(), "round {$round}");
            $status = $client->getStatus($second);
            self::assertSame([PaymentStatus::OPEN, '4000', '2000'], [$status->state, $status->received, $status->rest?->sats], "round {$round}");
        }
        $witnesses = array_values(array_filter($this->witnesses(), static fn(Witness $w): bool => $w->txid === $txid));
        $seen = array_map(static fn(Witness $w): array => [$w->address, $w->sale, $w->sats], $witnesses);
        sort($seen);
        $expected = [
            [$a, Sale::of($this->world->integration(), 'ORD-40')->author, '5000'],
            [$b, Sale::of($this->world->integration(), 'ORD-41')->author, '4000'],
        ];
        sort($expected);
        self::assertSame($expected, $seen, 'one witness per address, each of its own order');

        // 3 confirmations: the first paid on-chain; the second still underpaid.
        $this->confirm($a, $txid, $this->world->height - 2);
        $this->confirm($b, $txid, $this->world->height - 2);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '5000', 'txids' => [$txid]], $client->getStatus($first)->toArray());
        self::assertSame([PaymentStatus::OPEN, '4000'], [$client->getStatus($second)->state, $client->getStatus($second)->received]);
    }

    public function testWitnessesARelaySendsAgainstTheFilterAreOfNoInvoiceOfTheRead(): void
    {
        // Two orders paid by one transaction; a witness of a newer format at the second's address.
        $this->world->lot([World::address(), World::address()]);
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-42', 'sats' => '5000']);
        $second = $client->createInvoice(['ref' => 'ORD-43', 'sats' => '6000']);
        [$a, $b] = [(string) $first->onchainAddress, (string) $second->onchainAddress];
        $this->world->now += 60;
        $txid = bin2hex(random_bytes(32));
        $transaction = World::transaction($txid, [bin2hex(random_bytes(32)) . ':0'], [[4000, $b], [5000, $a], [12427, World::address()]]);
        $this->world->transactions[$a] = [$transaction];
        $this->world->transactions[$b] = [$transaction];
        $expected = ['state' => 'unconfirmed', 'received' => '5000', 'confirmations' => 0, 'txids' => [$txid]];
        self::assertSame($expected, $client->getStatus($first)->toArray());
        $client->getStatus($second);
        $keys = WitnessKeys::of($this->world->integration());
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $context = TestContext::create(new TestConnector());
        $this->world->publish(SealedRecord::seal('integration-onchain-seen', ['version' => 2], $keys->seenSecret->reveal(), $keys->encryptionKey->reveal(), Derive::witnessAddress($seenAddressKey, $b, bin2hex(random_bytes(32)), null), $this->world->now - 30, $context->random(...), [['o', Derive::addressTag($seenAddressKey, $b)]]));

        // Relays that send every witness of the integration, whatever the filter: those of the
        // second order's address are of no invoice of the first's read (SPEC.md §31).
        $all = array_map(static fn(Event $event): string => Json::encode($event->toJson()), $this->world->eventsBy($keys->seenAuthor));
        self::assertCount(3, $all);
        foreach ($this->world->relays->relays as $relay) {
            $relay->extraEvents = static fn(): array => $all;
        }
        $this->world->warnings = [];
        self::assertSame($expected, $client->getStatus($first)->toArray());
        self::assertSame([], $this->world->warnings, 'no witness of a newer format of this read');
    }

    public function testAPaymentNoCheckSawIsToldByBlockCypher(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $late = $client->createInvoice(['ref' => 'ORD-14', 'sats' => '49000']);
        $end = $late->expiresAt + 120;
        // Received a millisecond after the lease's end: late, a witness none written.
        $txid = $this->pay($address, 49000);
        $this->world->received[$txid] = World::rfc3339($end * 1000 + 1);
        $this->world->now = $end + 60;
        self::assertSame(['state' => 'expired', 'received' => '0'], $client->getStatus($late)->toArray());
        $witnesses = array_values(array_filter($this->witnesses(), static fn(Witness $w): bool => $w->txid === $txid));
        self::assertCount(1, $witnesses);
        self::assertNull($witnesses[0]->sale);
        self::assertCount(1, $this->world->http->requestsTo('api.blockcypher.com'));

        // Received at the lease's end, to the millisecond: in time.
        $address2 = World::address();
        $this->world->lot([$address2], $this->world->now - 5);
        $this->world->now = $late->expiresAt + 1000;
        $inTime = $client->createInvoice(['ref' => 'ORD-15', 'sats' => '49000']);
        self::assertSame($address2, $inTime->onchainAddress);
        $txid2 = $this->pay($address2, 49000);
        $this->world->received[$txid2] = World::rfc3339(($inTime->expiresAt + 120) * 1000);
        $this->world->now = $inTime->expiresAt + 200;
        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($inTime)->state);
        // Its witness spares every later check the question.
        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($inTime)->state);
        self::assertCount(2, $this->world->http->requestsTo('api.blockcypher.com'), 'BlockCypher asked once for each');
    }

    public function testAWitnessNoneIsNeverPublishedOverOneOfANewerFormat(): void
    {
        // A late transaction, of which a newer version wrote a witness none, of a newer format, at
        // the witness address of A, T and none (SPEC.md §31, step 6): it is never written over
        // (SPEC.md §12), and warned of.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-49', 'sats' => '49000']);
        $end = $invoice->expiresAt + 120;
        $txid = $this->pay($address, 49000);
        $this->world->received[$txid] = World::rfc3339(($end + 30) * 1000);
        $keys = WitnessKeys::of($this->world->integration());
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $context = TestContext::create(new TestConnector());
        $d = Derive::witnessAddress($seenAddressKey, $address, $txid, null);
        $newer = SealedRecord::seal('integration-onchain-seen', ['version' => 2], $keys->seenSecret->reveal(), $keys->encryptionKey->reveal(), $d, $end + 40, $context->random(...), [['o', Derive::orderTag($seenAddressKey, $address, null)], ['o', Derive::addressTag($seenAddressKey, $address)]]);
        $this->world->publish($newer);
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        $this->world->now = $end + 60;
        $this->world->warnings = [];

        self::assertSame(['state' => 'expired', 'received' => '0'], $client->getStatus($invoice)->toArray());
        foreach ($this->world->relays->relays as $url => $relay) {
            $published = array_filter(\array_slice($relay->received, $sent[$url]), static fn(\stdClass $event): bool => Json::get($event, 'pubkey') === $keys->seenAuthor);
            self::assertSame([], $published, "{$url}: a witness published over the newer one");
            $held = array_values(array_filter($relay->events, static fn(\stdClass $event): bool => Event::fromJson($event)?->tag('d') === $d));
            self::assertSame([$newer->id], array_map(static fn(\stdClass $event): mixed => Json::get($event, 'id'), $held));
        }
        self::assertSame(['A witness of this address has a newer format than this version of sattleme/checkout: update it'], $this->world->warned('A witness of this address'));
    }

    public function testATransactionTheExplorerListsPastTheLeasesEndIsNotInTime(): void
    {
        // The check starts 5 s before the lease's end; the explorer answers 10 s after it, with a
        // transaction BlockCypher received 8 s after it. What it lists was broadcast by the time
        // it answered (SPEC.md §31): judged by the clock read then, it is late, a witness none,
        // never a witness of the sale, which would say in time for good.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-46', 'sats' => '49000']);
        $end = $invoice->expiresAt + 120;
        $this->world->now = $end - 5;
        $txid = bin2hex(random_bytes(32));
        $this->world->onRequest = function (string $host, HttpRequest $request) use ($address, $end, $txid): void {
            if ($host === 'mempool.space' && $request->path() === "/api/address/{$address}/txs" && !isset($this->world->transactions[$address])) {
                $this->world->now = $end + 10;
                $this->world->transactions[$address][] = World::transaction($txid, [bin2hex(random_bytes(32)) . ':0'], [[49000, $address]]);
                $this->world->received[$txid] = World::rfc3339(($end + 8) * 1000);
            }
        };
        // Its Lightning part, asked by the clock the check started with, is not final yet.
        self::assertSame(['state' => 'open', 'expiresAt' => $invoice->expiresAt, 'received' => '0', 'rest' => null], $client->getStatus($invoice)->toArray());
        $witnesses = array_map(static fn(Witness $w): array => [$w->txid, $w->sale], $this->witnesses());
        self::assertSame([[$txid, null]], $witnesses);
    }

    public function testARestInvoiceIsAskedByTheClockOfTheDecision(): void
    {
        $client = $this->world->client();
        // The check starts with 70 s left; the explorer answers 50 s later. 20 s are left: no
        // invoice of the rest is asked (60 s at least, SPEC.md §31), though 70 s were at first.
        [$invoice, $address] = $this->underpaid($client, 'ORD-47', 20000);
        $this->world->now = $invoice->expiresAt - 70;
        $this->world->onRequest = function (string $host, HttpRequest $request) use ($address, $invoice): void {
            if ($host === 'mempool.space' && $request->path() === "/api/address/{$address}/txs") {
                $this->world->now = $invoice->expiresAt - 20;
            }
        };
        $status = $client->getStatus($invoice);
        self::assertSame('29000', $status->rest?->sats);
        self::assertNull($status->rest->bolt11);
        self::assertSame([], $this->restInvoices(29000));

        // Its turn to ask came with 70 s left, the pay request answered 50 s later: the decision
        // reads the clock then (a check that waited has less time left), and asks nothing.
        [$invoice] = $this->underpaid($client, 'ORD-48', 20000);
        $this->world->now = $invoice->expiresAt - 70;
        $this->world->onRequest = function (string $host, HttpRequest $request) use ($invoice): void {
            if ($host === 'breez.tips' && $request->path() === '/.well-known/lnurlp/shop' && $this->world->now === $invoice->expiresAt - 70) {
                $this->world->now = $invoice->expiresAt - 20;
            }
        };
        self::assertNull($client->getStatus($invoice)->rest?->bolt11);
        self::assertSame([], $this->restInvoices(29000));
        self::assertNotSame([], $this->world->http->requestsTo('breez.tips'), 'its pay request asked');

        // With 61 s left when it decides: asked, for those 61 s.
        $this->world->onRequest = null;
        $this->world->now = $invoice->expiresAt - 61;
        self::assertNotNull($client->getStatus($invoice)->rest?->bolt11);
        self::assertSame([61], array_column($this->restInvoices(29000), 'expiry'));
    }

    public function testTheRestOfAnUnderpayment(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-16', 'sats' => '49000', 'note' => 'Bread']);
        $this->world->now += 100;
        $this->pay($address, 20000);

        // Underpaid: an invoice of the rest asked, declared and published at once.
        $status = $client->getStatus($invoice);
        self::assertSame(PaymentStatus::OPEN, $status->state);
        self::assertSame('20000', $status->received);
        self::assertNotNull($status->rest);
        self::assertSame('29000', $status->rest->sats);
        $bolt11 = $status->rest->bolt11;
        self::assertNotNull($bolt11);
        self::assertSame("bitcoin:{$address}?amount=0.00029&lightning={$bolt11}", $status->rest->paymentUri);
        $asked = array_values(array_filter($this->world->invoices, static fn(array $i): bool => $i['pr'] === $bolt11));
        self::assertCount(1, $asked);
        self::assertSame(29000, $asked[0]['sats']);
        self::assertSame($invoice->expiresAt - $this->world->now, $asked[0]['expiry'], 'until the invoice\'s end');
        self::assertSame('Bread', $asked[0]['comment'], 'the lease\'s note');
        $rests = array_values(array_filter($this->declarations('ORD-16'), static fn(Declaration $d): bool => $d->rest !== null));
        self::assertNotSame([], $rests);
        self::assertSame(['address' => $address, 'expiresAt' => $invoice->expiresAt], $rests[0]->rest);
        self::assertSame($bolt11, $rests[0]->invoice);
        $keys = RestKeys::of($this->world->integration(), 'ORD-16', $address, $invoice->expiresAt);
        $records = $this->world->eventsBy($keys->author);
        self::assertCount(1, $records, 'its rest record');
        $holding = 0;
        foreach ($this->world->relays->relays as $relay) {
            $holding += \count(array_filter($relay->events, static fn(\stdClass $e): bool => $e->id === $records[0]->id));
        }
        self::assertGreaterThanOrEqual(2, $holding, 'on a majority');

        // Not asked again.
        $count = \count($this->world->invoices);
        self::assertSame($bolt11, $client->getStatus($invoice)->rest?->bolt11);
        self::assertCount($count, $this->world->invoices);

        // Paid with it: mixed, once on-chain is 3 deep.
        $hash = array_search($asked[0], $this->world->invoices, true);
        \assert(\is_string($hash));
        $this->world->settle($hash);
        $txid = $this->world->txid($address);
        $this->confirm($address, $txid, $this->world->height - 5);
        self::assertSame(['state' => 'paid', 'method' => 'mixed', 'received' => '49000', 'txids' => [$txid], 'preimages' => [$this->world->invoices[$hash]['preimage']]], $client->getStatus($invoice)->toArray());
        self::assertFalse(\in_array(true, array_map(static fn(string $w): bool => str_contains($w, 'rest'), $this->world->warnings), true));
    }

    public function testAnUnderpaidPendingInvoiceIsHandedOutWithItsRestInvoice(): void
    {
        // An order's one invoice that may still be paid, underpaid, not checked since: the check
        // of the next createInvoice asks for its rest invoice before it hands it out, so that its
        // checkout links' pages show it (SPEC.md §21, step 1; §31, "Asking for a rest invoice").
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-44', 20000);
        $this->world->now += 100;
        self::assertSame([], $this->restInvoices(29000));
        try {
            $client->createInvoice(['ref' => 'ORD-44', 'sats' => '49000', 'checkout' => true]);
            self::fail('The invoice may still be paid');
        } catch (InvoicePendingException $e) {
            self::assertSame($invoice->bolt11, $e->getInvoice()->bolt11);
            self::assertNotNull($e->getCheckoutUrl());
        }
        $rest = $this->restInvoices(29000);
        self::assertCount(1, $rest, 'its rest invoice asked once');
        $declared = array_values(array_filter($this->declarations('ORD-44'), static fn(Declaration $d): bool => $d->rest !== null));
        self::assertSame([$rest[0]['pr']], array_values(array_unique(array_map(static fn(Declaration $d): string => $d->invoice, $declared))), 'declared');
        $keys = RestKeys::of($this->world->integration(), 'ORD-44', $address, $invoice->expiresAt);
        self::assertCount(1, $this->world->eventsBy($keys->author), 'its rest record, which the pages read');

        // The status then gives that rest invoice; none is asked again.
        $count = \count($this->world->invoices);
        self::assertSame($rest[0]['pr'], $client->getStatus($invoice)->rest?->bolt11);
        self::assertCount($count, $this->world->invoices);
    }

    public function testNoRestRecordIsPublishedAgainAfterAReadFewerThanAMajorityAnswered(): void
    {
        // Its rest records read on fewer than a majority of the relays: such a read decides
        // nothing (SPEC.md §13), none is published again, so that what the other relays hold is
        // never written over unseen, and none counts as known (SPEC.md §31, "Rest records"): the
        // rest invoice is not the status's bolt11, and no other is asked for the same rest. Once
        // a majority answers, the next check publishes its record again, and it is shown.
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-45', 20000);
        $this->world->now += 100;
        $status = $client->getStatus($invoice);
        $bolt11 = $status->rest?->bolt11;
        self::assertNotNull($bolt11);
        $keys = RestKeys::of($this->world->integration(), 'ORD-45', $address, $invoice->expiresAt);
        $record = $this->world->eventsBy($keys->author)[0]->id;
        // Its first publication reached one relay only; two relays answer no read of the rest records.
        foreach ([1, 2] as $index) {
            $relay = $this->world->relay($index);
            $relay->events = array_values(array_filter($relay->events, static fn(\stdClass $e): bool => $e->id !== $record));
            $relay->onQuery = static function (\stdClass $filter, LocalRelay $relay) use ($keys): void {
                $relay->closeQueries = \in_array($keys->author, (array) ($filter->authors ?? []), true) && !isset($filter->{'#d'}) ? 'error: test' : null;
            };
        }
        $count = \count($this->world->invoices);
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        $this->world->warnings = [];

        $status = $client->getStatus($invoice);
        self::assertSame(PaymentStatus::OPEN, $status->state);
        self::assertSame('29000', $status->rest?->sats);
        self::assertNull($status->rest->bolt11, 'not known on a majority');
        self::assertCount($count, $this->world->invoices, 'no other invoice of the rest');
        self::assertSame([], $this->sentBy($keys->author, $sent), 'nothing published');
        self::assertSame(['The rest records of an invoice could not be read on a majority of the relays: none published again'], $this->world->warned('The rest records'));

        // A majority answers again: published again on a majority, the status's again.
        foreach ([1, 2] as $index) {
            $this->world->relay($index)->onQuery = null;
            $this->world->relay($index)->closeQueries = null;
        }
        self::assertSame($bolt11, $client->getStatus($invoice)->rest?->bolt11);
        self::assertCount($count, $this->world->invoices, 'no other invoice of the rest');
        $hash = array_search($bolt11, array_map(static fn(array $i): string => $i['pr'], $this->world->invoices), true);
        self::assertIsString($hash);
        $holding = 0;
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->events as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->pubkey === $keys->author && $event->tag('d') === $keys->recordAddress($hash)) {
                    ++$holding;
                    break;
                }
            }
        }
        self::assertGreaterThanOrEqual(2, $holding, 'its rest record on a majority again');
    }

    public function testARestRecordIsNeverPublishedOverOneOfANewerFormat(): void
    {
        // A newer version wrote the rest record of a rest invoice in a format this one cannot read
        // (SPEC.md §31, "Rest records"): never written over (SPEC.md §12), and the invoice counts
        // as recorded, the status's bolt11; warned of.
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-50', 20000);
        $this->world->now += 100;
        $keys = RestKeys::of($this->world->integration(), 'ORD-50', $address, $invoice->expiresAt);
        // Its rest record taken by the first relay alone: not known on a majority.
        foreach ([1, 2] as $index) {
            $this->world->relay($index)->refuseEvent = static fn(Event $event): ?string => $event->pubkey === $keys->author ? 'blocked: not now' : null;
        }
        self::assertNull($client->getStatus($invoice)->rest?->bolt11, 'not known on a majority');
        [$hash, $pr] = $this->restInvoice(29000);
        // A newer version's record of it, on the second relay; the third answers no read of the
        // rest records, so that the read hears the second. Both still refuse the rest author's
        // events: its own record could not be published again.
        $d = $keys->recordAddress($hash);
        $newer = Event::sign($keys->secret->reveal(), 30078, $this->world->now - 1, [['d', $d], ['expiration', (string) ($invoice->expiresAt + 604800)]], '#', random_bytes(32));
        $this->world->relay(1)->take($newer);
        $this->world->relay(2)->onQuery = static function (\stdClass $filter, LocalRelay $relay) use ($keys): void {
            $relay->closeQueries = \in_array($keys->author, (array) ($filter->authors ?? []), true) && !isset($filter->{'#d'}) ? 'error: test' : null;
        };
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        $asked = \count($this->world->invoices);
        $this->world->warnings = [];

        self::assertSame($pr, $client->getStatus($invoice)->rest?->bolt11);
        self::assertCount($asked, $this->world->invoices, 'no other invoice of the rest');
        self::assertSame([], $this->sentBy($keys->author, $sent), 'nothing published over it');
        self::assertContains(Json::encode($newer->toJson()), array_map(Json::encode(...), $this->world->relay(1)->events));
        self::assertSame(['A record of an invoice of the rest has a newer format than this version of sattleme/checkout: update it'], $this->world->warned('A record of an invoice of the rest'));
    }

    public function testARestRecordIsNeverPublishedOverOneOfANewerFormatWhenItsReadFails(): void
    {
        // Fewer than a majority answer the read of the rest records: none is published again
        // (SPEC.md §31, "Rest records"), nor over the record of a newer format that a relay which
        // answered sent: here the first; the second refuses the read, the third never answers it.
        // Such a read decides nothing: the invoice is not known, never the status's bolt11, and
        // no other is asked for the same rest; the record of a newer format is warned of.
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-51', 20000);
        $this->world->now += 100;
        $shown = $client->getStatus($invoice)->rest?->bolt11;
        [$hash, $pr] = $this->restInvoice(29000);
        self::assertSame($pr, $shown);
        $keys = RestKeys::of($this->world->integration(), 'ORD-51', $address, $invoice->expiresAt);
        $d = $keys->recordAddress($hash);
        // A newer version's record of it, newer than its own, on the first relay.
        $this->world->now += 10;
        $newer = SealedRecord::seal('checkout-rest', ['version' => 2], $keys->secret->reveal(), $keys->encryptionKey->reveal(), $d, $this->world->now - 1, TestContext::create(new TestConnector())->random(...), [['expiration', (string) ($invoice->expiresAt + 604800)]]);
        $this->world->relay(0)->take($newer);
        self::assertContains(Json::encode($newer->toJson()), array_map(Json::encode(...), $this->world->relay(0)->events));
        $this->world->relay(1)->onQuery = static function (\stdClass $filter, LocalRelay $relay) use ($keys): void {
            $relay->closeQueries = \in_array($keys->author, (array) ($filter->authors ?? []), true) && !isset($filter->{'#d'}) ? 'error: test' : null;
        };
        $this->world->relay(2)->onQuery = static function (\stdClass $filter, LocalRelay $relay) use ($keys): void {
            $relay->queryDelayMs = \in_array($keys->author, (array) ($filter->authors ?? []), true) && !isset($filter->{'#d'}) ? 60000 : 0;
        };
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        $asked = \count($this->world->invoices);
        $this->world->warnings = [];

        $status = $client->getStatus($invoice);
        self::assertSame('29000', $status->rest?->sats);
        self::assertNull($status->rest->bolt11, 'not known on a majority');
        self::assertCount($asked, $this->world->invoices, 'no other invoice of the rest');
        self::assertSame([], $this->sentBy($keys->author, $sent), 'nothing published over it');
        self::assertNotSame([], $this->world->warned('The rest records of an invoice could not be read'));
        self::assertNotSame([], $this->world->warned('A record of an invoice of the rest has a newer format'));
    }

    public function testAPayRequestWhoseMetadataHoldsALoneSurrogateAsksNoRestInvoice(): void
    {
        // The Lightning address answers a pay request whose metadata holds a lone surrogate (SPEC.md
        // §16: invalid_invoice) when a check asks for an invoice of an underpayment's rest: logged,
        // never the check's error, and the status still given (SPEC.md §23, §31).
        $client = $this->world->client();
        [$invoice] = $this->underpaid($client, 'ORD-62', 20000);
        $this->world->payRequestText = '{"tag":"payRequest","callback":"https://breez.tips/lnurlp/shop/invoice","minSendable":1000,"maxSendable":4000000000,"metadata":"[[\\"text/plain\\",\\"\\udc00\\"]]"}';
        $this->world->warnings = [];
        $asked = \count($this->world->invoices);

        $status = $client->getStatus($invoice);
        self::assertSame(PaymentStatus::OPEN, $status->state);
        self::assertSame('20000', $status->received);
        self::assertSame('29000', $status->rest?->sats);
        self::assertNull($status->rest->bolt11);
        self::assertCount($asked, $this->world->invoices, 'its callback never asked');
        self::assertSame(['No invoice of the rest could be made: breez.tips gave no valid LNURL-pay request'], $this->world->warned('No invoice of the rest'));

        // A pay request as it should be: the next check asks for it.
        $this->world->payRequestText = null;
        self::assertNotNull($client->getStatus($invoice)->rest?->bolt11);
    }

    public function testNoRestInvoiceWithoutTimeLeftOrOutsideTheSendableSatsOrWhenTheAddressFails(): void
    {
        $client = $this->world->client();
        // 59 s left: not asked.
        [$invoice, $address] = $this->underpaid($client, 'ORD-17', 20000);
        $this->world->now = $invoice->expiresAt - 59;
        $status = $client->getStatus($invoice);
        self::assertSame('29000', $status->rest?->sats);
        self::assertNull($status->rest->bolt11);

        // Outside the sendable sats: not asked.
        [$invoice] = $this->underpaid($client, 'ORD-18', 20000);
        $this->world->payRequest['maxSendable'] = 28999000;
        self::assertNull($client->getStatus($invoice)->rest?->bolt11);
        $this->world->payRequest['maxSendable'] = 4000000000;

        // The Lightning address failing: logged, asked again at the next check.
        [$invoice] = $this->underpaid($client, 'ORD-19', 20000);
        $this->world->invoiceAnswer = static fn(): HttpResponseSpec => new HttpResponseSpec(503, 'Unavailable');
        $this->world->warnings = [];
        $status = $client->getStatus($invoice);
        self::assertNull($status->rest?->bolt11);
        self::assertNotSame([], $this->world->warnings);
        $this->world->invoiceAnswer = null;
        self::assertNotNull($client->getStatus($invoice)->rest?->bolt11);
    }

    public function testTheMerchantsTolerance(): void
    {
        $this->world->channel(['onchainTolerance' => 500], null, $this->world->now - 100);
        $client = $this->world->client();

        // 48 000 of 49 000 in time: accepted; its acceptance record published once.
        [$invoice, $address] = $this->underpaid($client, 'ORD-20', 48000);
        $status = $client->getStatus($invoice);
        self::assertSame(['unconfirmed', '48000', 0], [$status->state, $status->received, $status->confirmations]);
        $keys = RestKeys::of($this->world->integration(), 'ORD-20', $address, $invoice->expiresAt);
        self::assertSame(['48000'], $this->acceptances($keys, '49000'));
        $published = function () use ($keys): int {
            $count = 0;
            foreach ($this->world->relays->relays as $relay) {
                $count += \count(array_filter($relay->received, static fn(\stdClass $e): bool => $e->pubkey === $keys->author));
            }

            return $count;
        };
        self::assertGreaterThanOrEqual(2, $published(), 'on a majority');
        // Held by every relay now (a relay closed after the majority gets it later): not published again.
        $records = $this->world->eventsBy($keys->author);
        self::assertCount(1, $records);
        $this->world->publish($records[0]);
        $client->getStatus($invoice);
        self::assertSame([$records[0]->id], array_map(static fn(Event $e): string => $e->id, $this->world->eventsBy($keys->author)), 'published once');

        // A replacement paying less, still accepted: a newer record of less.
        $old = $this->world->txid($address);
        $coin = $this->world->coin($address);
        $this->world->transactions[$address] = [World::transaction(bin2hex(random_bytes(32)), [$coin], [[46600, $address], [1000, World::address()]])];
        $this->world->now += 30;
        $status = $client->getStatus($invoice);
        self::assertSame(['unconfirmed', '46600'], [$status->state, $status->received]);
        $this->world->now += 1;
        self::assertSame('46600', $this->newestAcceptance($keys, '49000'), 'the newest, as the page reads it');
        self::assertNotSame($old, $status->txids[0] ?? null);

        // A sat short of the least accepted (46 550): its whole rest asked.
        $this->world->channel(['onchainTolerance' => 0], null, $this->world->now - 5);
        [$short] = $this->underpaid($client, 'ORD-21', 46549);
        $status = $client->getStatus($short);
        self::assertSame('2451', $status->rest?->sats);
        self::assertNotNull($status->rest->bolt11);

        // The tolerance changed after the order: the lease's holds.
        $status = $client->getStatus($invoice);
        self::assertSame(PaymentStatus::UNCONFIRMED, $status->state, 'judged with the lease\'s tolerance');

        // Within it, 3 deep: paid, for less than the amount.
        $txid = $this->world->txid($address);
        $this->confirm($address, $txid, $this->world->height - 2);
        self::assertSame(['state' => 'paid', 'method' => 'onchain', 'received' => '46600', 'txids' => [$txid]], $client->getStatus($invoice)->toArray());
    }

    public function testAnAcceptanceRecordIsNeverPublishedOverOneOfANewerFormat(): void
    {
        // A newer version wrote the order's acceptance record in a format this one cannot read
        // (SPEC.md §31, "Acceptance records"): it holds it, never written over (SPEC.md §12),
        // and it is warned of.
        $this->world->channel(['onchainTolerance' => 500], null, $this->world->now - 100);
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-52', 48000);
        $keys = RestKeys::of($this->world->integration(), 'ORD-52', $address, $invoice->expiresAt);
        $newer = Event::sign($keys->secret->reveal(), 30078, $this->world->now - 5, [['d', $keys->acceptanceAddress()], ['expiration', (string) ($invoice->expiresAt + 604800)]], '#', random_bytes(32));
        $this->world->publish($newer);
        $this->world->now += 30;
        $this->world->warnings = [];

        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($invoice)->state, 'accepted with the tolerance');
        self::assertSame([$newer->id], array_map(static fn(Event $event): string => $event->id, $this->world->eventsBy($keys->author)), 'not written over');
        self::assertSame(['An acceptance record of this order has a newer format than this version of sattleme/checkout: update it'], $this->world->warned('An acceptance record'));
    }

    public function testNoAcceptanceRecordIsPublishedAfterAReadFewerThanAMajorityAnswered(): void
    {
        // Fewer than a majority of the relays answer the read of the acceptance records: such a
        // read decides nothing (SPEC.md §13), and what the others hold, of a newer format perhaps,
        // is never written over unseen (SPEC.md §31, "Acceptance records"). Nothing is published,
        // a warning is logged, never the check's error; the next check reads and publishes again.
        $this->world->channel(['onchainTolerance' => 500], null, $this->world->now - 100);
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-57', 48000);
        $keys = RestKeys::of($this->world->integration(), 'ORD-57', $address, $invoice->expiresAt);
        foreach ([1, 2] as $index) {
            // Their read of the acceptance records alone fails there.
            $this->world->relay($index)->onQuery = static function (\stdClass $filter, LocalRelay $relay) use ($keys): void {
                $relay->closeQueries = \in_array($keys->acceptanceAddress(), (array) ($filter->{'#d'} ?? []), true) ? 'error: test' : null;
            };
        }
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        $this->world->warnings = [];

        $status = $client->getStatus($invoice);
        self::assertSame(['unconfirmed', '48000'], [$status->state, $status->received], 'accepted, the check\'s status given');
        self::assertSame([], $this->sentBy($keys->author, $sent), 'nothing published');
        self::assertSame([], $this->acceptances($keys, '49000'));
        self::assertSame(['The acceptance records of an invoice could not be read on a majority of the relays'], $this->world->warned('The acceptance record'));

        // The relays answer again: the next check reads and publishes it.
        foreach ([1, 2] as $index) {
            $this->world->relay($index)->onQuery = null;
            $this->world->relay($index)->closeQueries = null;
        }
        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($invoice)->state);
        self::assertSame(['48000'], $this->acceptances($keys, '49000'));
    }

    public function testAnAcceptanceRecordThatCannotBePublishedIsOnlyLogged(): void
    {
        $this->world->channel(['onchainTolerance' => 500], null, $this->world->now - 100);
        $client = $this->world->client();
        [$invoice, $address] = $this->underpaid($client, 'ORD-22', 47000);
        $keys = RestKeys::of($this->world->integration(), 'ORD-22', $address, $invoice->expiresAt);
        foreach ($this->world->relays->relays as $relay) {
            $relay->refuseEvent = static fn(Event $event): ?string => $event->pubkey === $keys->author ? 'blocked: no' : null;
        }
        $this->world->warnings = [];
        self::assertSame(PaymentStatus::UNCONFIRMED, $client->getStatus($invoice)->state);
        self::assertNotSame([], $this->world->warnings);
        self::assertSame([], $this->acceptances($keys, '49000'));
    }

    public function testWaitForPaymentIsWokenByTheWebSocket(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-23', 'sats' => '49000']);
        $start = Loop::now();
        $states = [];
        $txid = null;
        $confirmed = false;
        $status = $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => function (PaymentStatus $status) use (&$states, &$txid, &$confirmed, $address): void {
            $states[] = $status->state;
            if ($txid === null) {
                $txid = $this->pay($address, 49000);
                $this->world->push(['address-transactions' => [['txid' => $txid]]]);
            } elseif ($status->state === PaymentStatus::UNCONFIRMED && !$confirmed) {
                $this->confirm($address, $txid, $this->world->height - 3);
                $confirmed = true;
                $this->world->push(['block' => ['height' => $this->world->height]]);
            }
        }], new TimeoutCancellation(30));
        self::assertSame(PaymentStatus::PAID, $status->state);
        self::assertContains(PaymentStatus::UNCONFIRMED, $states);
        // Neither this bound nor the call's cancellation is scaled (TestTime): both stay under the
        // SDK's own 60 s, which no scale changes.
        self::assertLessThan(20, Loop::now() - $start, 'woken by the WebSocket, not the 60 s check');
        self::assertNotSame([], $this->world->sockets, 'connected to mempool.space');
    }

    public function testARemovedIntegrationStopsTheCallsThatReadTheChannel(): void
    {
        // SPEC.md §14: a removed integration stops every call that reads the channel (making an
        // invoice, finding an order's invoices, reading the merchant, the on-chain part of a
        // check: the check of an invoice with an address, and the wait for it, are revoked unless
        // its Lightning invoice is paid). The check of an invoice without an address reads no
        // relay and goes on.
        $client = $this->world->client();
        $lightningOnly = $client->createInvoice(['ref' => 'ORD-63', 'sats' => '500']);
        [$first, $second] = [World::address(), World::address()];
        ++$this->world->now; // A newer lot than the last one.
        $this->world->lot([$first, $second], $this->world->now - 1);
        $paidByLightning = $client->createInvoice(['ref' => 'ORD-64', 'sats' => '49000']);
        $unpaid = $client->createInvoice(['ref' => 'ORD-65', 'sats' => '49000']);
        self::assertNotNull($paidByLightning->onchainAddress);
        self::assertNotNull($unpaid->onchainAddress);
        $this->world->channel(['status' => 'removed'], null, $this->world->now - 1);
        $this->world->settle((string) $paidByLightning->paymentHash);

        foreach ([
            'createInvoice' => static fn(): mixed => $client->createInvoice(['ref' => 'ORD-66', 'sats' => '500']),
            'findInvoices' => static fn(): mixed => $client->findInvoices('ORD-65'),
            'merchant' => static fn(): mixed => $client->merchant(),
            'getStatus with an address' => static fn(): mixed => $client->getStatus($unpaid),
            'waitForPayment with an address' => static fn(): mixed => $client->waitForPayment($unpaid, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(20))),
        ] as $call => $make) {
            try {
                $make();
                self::fail("{$call}: not revoked");
            } catch (RevokedException) {
            }
        }

        // Paid by its Lightning invoice: found paid, by a check and by a wait.
        self::assertSame(['state' => 'paid', 'method' => 'lightning'], array_intersect_key($client->getStatus($paidByLightning)->toArray(), ['state' => 1, 'method' => 1]));
        self::assertSame(PaymentStatus::PAID, $client->waitForPayment($paidByLightning, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(20)))->state);

        // Without an address: no relay read; open, then paid.
        $queries = array_sum(array_map(static fn(LocalRelay $relay): int => \count($relay->queries), $this->world->relays->relays));
        self::assertSame(PaymentStatus::OPEN, $client->getStatus($lightningOnly)->state);
        $this->world->settle((string) $lightningOnly->paymentHash);
        self::assertSame(PaymentStatus::PAID, $client->waitForPayment($lightningOnly, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(20)))->state);
        self::assertSame($queries, array_sum(array_map(static fn(LocalRelay $relay): int => \count($relay->queries), $this->world->relays->relays)), 'no relay read');
    }

    public function testAnInvoicePaidByLightningIsPaidWhateverItsOnchainPartMeets(): void
    {
        // SPEC.md §14, §31: a paid Lightning invoice wins over every failure of the on-chain part,
        // not only a removed integration (testARemovedIntegrationStopsTheCallsThatReadTheChannel):
        // the relays answering without the channel (channel_not_found), the relays not answering
        // (network), the explorer not answering (network). An invoice with an address paid by its
        // Lightning invoice is found paid by a check and by a wait; one unpaid fails so.
        $client = $this->world->client();
        [$first, $second] = [World::address(), World::address()];
        ++$this->world->now; // A newer lot than the last one.
        $this->world->lot([$first, $second], $this->world->now - 1);
        $paid = $client->createInvoice(['ref' => 'ORD-67', 'sats' => '49000']);
        $unpaid = $client->createInvoice(['ref' => 'ORD-68', 'sats' => '49000']);
        self::assertNotNull($paid->onchainAddress);
        self::assertNotNull($unpaid->onchainAddress);
        $this->world->settle((string) $paid->paymentHash);
        $channel = ['d', Derive::channelAddress($this->world->integration()->key->reveal())];
        $failures = [
            'the relays answering without the channel' => [ChannelNotFoundException::class, function (bool $on) use ($channel): void {
                foreach ($this->world->relays->relays as $relay) {
                    $relay->hide = $on ? static fn(\stdClass $event): bool => \in_array($channel, (array) $event->tags, true) : null;
                }
            }],
            'the relays not answering' => [NetworkException::class, function (bool $on): void {
                foreach ($this->world->relays->relays as $relay) {
                    $relay->mute = $on;
                }
            }],
            'the explorer not answering' => [NetworkException::class, function (bool $on): void {
                $this->world->explorerDown = $on;
            }],
        ];
        foreach ($failures as $failure => [$error, $fails]) {
            $fails(true);
            try {
                $status = $client->getStatus($paid);
                self::assertSame([PaymentStatus::PAID, 'lightning'], [$status->state, $status->method], $failure);
                self::assertSame(PaymentStatus::PAID, $client->waitForPayment($paid, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(20)))->state, $failure);
                try {
                    $client->getStatus($unpaid);
                    self::fail("{$failure}: an unpaid invoice checked");
                } catch (SattlemeException $e) {
                    self::assertInstanceOf($error, $e, $failure);
                }
            } finally {
                $fails(false);
            }
        }
    }

    public function testWaitForPaymentLeavesNothingRunningOnceItReturns(): void
    {
        // When waitForPayment returns (a status, a cancellation), nothing it started runs on
        // (SPEC.md §23): every task of its call (a check, the explorer's socket, the relays past a
        // majority) is stopped and has ended, its connections closed. A check held on the
        // explorer's answer, which then lists a part of the amount (its witness to publish, an
        // invoice of the rest to ask and declare), does nothing of it once the wait returned: it
        // opens no connection.
        $fibers = new \ReflectionProperty(Loop::class, 'fibers');
        $running = static function () use ($fibers): int {
            $all = $fibers->getValue(Loop::get());
            \assert(\is_array($all));

            // The tasks of a call; the local relays and servers run on their own.
            return \count(array_filter($all, static fn(mixed $fiber): bool => \is_array($fiber) && $fiber[1] !== null));
        };
        $connector = new class ($this->world->connector) implements Connector {
            public int $connections = 0;

            public function __construct(private readonly Connector $connector) {}

            public function connect(string $host, int $port, int $timeoutMs): mixed
            {
                ++$this->connections;

                return $this->connector->connect($host, $port, $timeoutMs);
            }
        };
        $client = $this->world->client(['connector' => $connector]);
        $cancellation = new class implements Cancellation {
            public bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
        foreach (['a cancellation' => 'ORD-60', 'the Lightning invoice paid' => 'ORD-61'] as $what => $ref) {
            $address = World::address();
            ++$this->world->now; // A newer lot than the last one.
            $this->world->lot([$address], $this->world->now - 1);
            $invoice = $client->createInvoice(['ref' => $ref, 'sats' => '49000']);
            self::assertSame($address, $invoice->onchainAddress);
            // The explorer's next answer about the address held, then a part of the amount listed.
            $wait = new class {
                public bool $hold = false;

                public int $held = 0;

                public int $statuses = 0;
            };
            $this->world->onRequest = function (string $host, HttpRequest $request) use ($wait, $address, $cancellation, $what): void {
                if ($wait->hold && $host === 'mempool.space' && $request->path() === "/api/address/{$address}/txs") {
                    $wait->hold = false;
                    ++$wait->held;
                    if ($what === 'a cancellation') {
                        $cancellation->cancelled = true;
                    }
                    Loop::get()->sleepUntil(TestTime::deadline(0.75));
                    $this->pay($address, 20000);
                }
            };
            try {
                $status = $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => function () use ($wait, $invoice, $what): void {
                    if (++$wait->statuses === 1) {
                        // A block: a check starts, and waits for the explorer's answer.
                        $wait->hold = true;
                        if ($what === 'the Lightning invoice paid') {
                            $this->world->settle((string) $invoice->paymentHash);
                        }
                        $this->world->push(['block' => ['height' => ++$this->world->height]]);
                    }
                }], $cancellation);
                self::assertSame('the Lightning invoice paid', $what);
                self::assertSame([PaymentStatus::PAID, 'lightning'], [$status->state, $status->method]);
            } catch (CancelledException) {
                self::assertSame('a cancellation', $what);
            }
            self::assertSame(1, $wait->held, "a check held on the explorer's answer, {$what}");
            self::assertSame(0, $running(), "nothing of the call runs, {$what}");
            $connections = $connector->connections;
            $asked = \count($this->world->invoices);
            // The held answer comes meanwhile, a part of the amount listed.
            Loop::get()->run(static fn() => Loop::get()->sleepUntil(TestTime::deadline(1.5)));
            self::assertNotSame([], $this->world->transactions[$address] ?? [], "the part paid, {$what}");
            self::assertSame($connections, $connector->connections, "no connection opened, {$what}: nothing published nor asked");
            self::assertCount($asked, $this->world->invoices, "no invoice of the rest, {$what}");
            $cancellation->cancelled = false;
        }
    }

    public function testWaitForPaymentFreesWhatEachCheckLeftForItsEnd(): void
    {
        // Each on-chain check of a wait runs on relay connections of its own, closed after it:
        // what it left to close at the end of the call is freed with them, so that a wait of
        // hours piles nothing up, check after check.
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-56', 'sats' => '49000']);
        $checks = fn(): int => \count(array_filter($this->world->http->requestsTo('mempool.space'), static fn(HttpRequest $r): bool => $r->path() === "/api/address/{$address}/txs"));
        $before = $checks();
        $scopes = new \ReflectionProperty(Loop::class, 'scopes');
        $cancellation = new class implements Cancellation {
            public bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
        $kept = [];
        try {
            $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => function (PaymentStatus $status) use (&$kept, $scopes, $cancellation, $checks, $before): void {
                $running = $scopes->getValue(Loop::get());
                \assert(\is_array($running) && end($running) instanceof Scope);
                $kept[$checks() - $before] = \count(end($running)->cleanups);
                if ($checks() - $before >= 4) {
                    $cancellation->cancelled = true;
                }
                // A new block: the explorer's WebSocket wakes the next on-chain check.
                $this->world->push(['block' => ['height' => ++$this->world->height]]);
            }], $cancellation);
            self::fail('Never paid');
        } catch (CancelledException) {
        }
        self::assertGreaterThanOrEqual(4, $checks() - $before, 'checked on-chain again and again');
        self::assertSame(array_fill_keys(array_keys($kept), 0), $kept, 'nothing of the checks done left for its end');
    }

    public function testAnExplorerThatDoesNotAnswerIsLoggedOnceNotAtEachCheck(): void
    {
        $address = World::address();
        $this->world->lot([$address]);
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-24', 'sats' => '49000']);
        // The explorer down: each on-chain check fails, and the Lightning part, checked every
        // 250 ms, is combined with that failure until the next on-chain check (60 s): each check
        // meets it again (SPEC.md §23).
        $this->world->explorerDown = true;
        $this->world->warnings = [];
        $before = \count($this->world->http->requests);
        try {
            $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(3)));
            self::fail('Never paid');
        } catch (CancelledException) {
        }
        $requests = \array_slice($this->world->http->requests, $before);
        $onchain = \count(array_filter($requests, static fn($r): bool => $r->host() === 'mempool.space' && $r->path() === "/api/address/{$address}/txs"));
        $lightning = \count(array_filter($requests, static fn($r): bool => $r->host() === 'breez.tips'));
        self::assertGreaterThan(0, $onchain);
        self::assertGreaterThan(2, $lightning, 'the ticks ran');
        self::assertCount(1, $this->world->warned('A payment check failed'), 'logged once, not at each check');
    }

    /**
     * An invoice of 49 000 sats with an address, paid $sats in time.
     *
     * @return array{0: Invoice, 1: string}
     */
    private function underpaid(\Sattleme\Checkout\Client $client, string $ref, int $sats): array
    {
        $address = World::address();
        ++$this->world->now; // A newer lot than the last one.
        $this->world->lot([$address], $this->world->now - 1);
        $invoice = $client->createInvoice(['ref' => $ref, 'sats' => '49000']);
        self::assertSame($address, $invoice->onchainAddress);
        $this->pay($address, $sats);

        return [$invoice, $address];
    }

    /** A transaction paying $address $sats (and change elsewhere), in the mempool: its txid. */
    private function pay(string $address, int $sats): string
    {
        $txid = bin2hex(random_bytes(32));
        $this->world->transactions[$address][] = World::transaction($txid, [bin2hex(random_bytes(32)) . ':0'], [[$sats, $address], [5000, World::address()]]);

        return $txid;
    }

    private function confirm(string $address, string $txid, int $height): void
    {
        foreach ($this->world->transactions[$address] as $index => $transaction) {
            if ($transaction['txid'] === $txid) {
                $this->world->transactions[$address][$index]['status'] = ['confirmed' => true, 'block_height' => $height];
            }
        }
    }

    /** @return list<Event> the lease records of $address the relays hold */
    private function leases(string $address): array
    {
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        $events = [];
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->events as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->tag('d') === $registryAddress) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * $count records at $address's registry address on every relay, each by a key of its own,
     * newer than what is there, of $bytes of content no one can open (a NIP-44 payload of
     * random bytes).
     *
     * @param positive-int $bytes
     */
    private function junk(string $address, int $count, int $bytes): void
    {
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        for ($i = 0; $i < $count; ++$i) {
            $event = Event::sign(random_bytes(31) . "\x01", 30078, $this->world->now - $i, [['d', $registryAddress]], base64_encode("\x02" . random_bytes($bytes)), random_bytes(32));
            foreach ($this->world->relays->relays as $relay) {
                $relay->store($event);
            }
        }
    }

    /** A record at $registryAddress of the right shape, neither its id nor its signature true (no read checks them). */
    private function unsigned(int $createdAt, string $registryAddress, ?string $id = null): \stdClass
    {
        return (object) ['id' => $id ?? bin2hex(random_bytes(32)), 'pubkey' => bin2hex(random_bytes(32)), 'created_at' => $createdAt, 'kind' => 30078, 'tags' => [['d', $registryAddress]], 'content' => 'x', 'sig' => bin2hex(random_bytes(64))];
    }

    /**
     * What each relay holds of $sale's lease of $address, by relay: 'ok', 'withdrawn' or none.
     *
     * @return list<string|null>
     */
    private function recordsOf(Sale $sale, string $address): array
    {
        $registryAddress = Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
        $records = [];
        foreach ($this->world->relays->relays as $relay) {
            $kept = null;
            foreach ($relay->events as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->pubkey === $sale->author && $event->tag('d') === $registryAddress) {
                    $kept = Lease::open($value, $sale, $this->world->registry)[0] ?? null;
                }
            }
            $records[] = $kept;
        }

        return $records;
    }

    /** @return list<Witness> the integration's witnesses (once each) */
    private function witnesses(): array
    {
        $keys = WitnessKeys::of($this->world->integration());
        $witnesses = [];
        foreach ($this->world->eventsBy($keys->seenAuthor) as $event) {
            $opened = Witness::open($event->toJson(), $keys);
            if ($opened !== null && $opened[0] === 'ok') {
                $witnesses[] = $opened[1];
            }
        }

        return $witnesses;
    }

    /**
     * The received of the acceptance records the relays hold (once each).
     *
     * @param numeric-string $sats
     *
     * @return list<string>
     */
    private function acceptances(RestKeys $keys, string $sats): array
    {
        $received = [];
        foreach ($this->world->eventsBy($keys->author) as $event) {
            $read = Acceptance::read($event->toJson(), $keys, $sats);
            if ($read !== null && $read[0] === 'ok') {
                $received[] = $read[1];
            }
        }

        return $received;
    }

    /**
     * The received of the newest acceptance record the relays hold (created_at, then the lowest id).
     *
     * @param numeric-string $sats
     */
    private function newestAcceptance(RestKeys $keys, string $sats): ?string
    {
        $newest = null;
        foreach ($this->world->eventsBy($keys->author) as $event) {
            $read = Acceptance::read($event->toJson(), $keys, $sats);
            if ($read !== null && $read[0] === 'ok' && ($newest === null || $read[2]->replaces($newest[2]))) {
                $newest = $read;
            }
        }

        return $newest === null ? null : $newest[1];
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
     * The payment hash and the invoice of the one invoice of $sats sats the Lightning address made.
     *
     * @return array{0: string, 1: string}
     */
    private function restInvoice(int $sats): array
    {
        $made = array_filter($this->world->invoices, static fn(array $invoice): bool => $invoice['sats'] === $sats);
        self::assertCount(1, $made);
        $hash = array_key_first($made);

        return [$hash, $made[$hash]['pr']];
    }

    /**
     * The events by $author the relays received since they had received $sent (by relay).
     *
     * @param array<string, int> $sent
     *
     * @return list<\stdClass>
     */
    private function sentBy(string $author, array $sent): array
    {
        $events = [];
        foreach ($this->world->relays->relays as $url => $relay) {
            foreach (\array_slice($relay->received, $sent[$url] ?? 0) as $event) {
                if (Json::get($event, 'pubkey') === $author) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /** @return list<Declaration> the declarations of $ref's sale (once per relay) */
    private function declarations(string $ref): array
    {
        $sale = Sale::of($this->world->integration(), $ref);
        $declarations = [];
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->events as $value) {
                $opened = Declaration::open($value, $sale, $this->world->registry);
                if ($opened !== null && $opened[0] === 'ok') {
                    $declarations[] = $opened[1];
                }
            }
        }

        return $declarations;
    }
}
