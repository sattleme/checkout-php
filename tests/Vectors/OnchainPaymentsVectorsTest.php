<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Onchain\Acceptance;
use Sattleme\Checkout\Internal\Onchain\Combined;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptance;
use Sattleme\Checkout\Internal\Onchain\OnchainPart;
use Sattleme\Checkout\Internal\Onchain\OnchainResult;
use Sattleme\Checkout\Internal\Onchain\RestInvoice;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\RestPart;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Tolerance;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\Relays;
use Sattleme\Checkout\Tests\Support\Onchain\VectorSource;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * Statuses of an invoice with an address (SPEC.md §31): the on-chain part's steps against each
 * case's explorers, witnesses, merchant's acceptances and BlockCypher, the rest part, how they
 * combine, the witnesses published, the transactions BlockCypher is asked about, and the acceptance
 * record published on local relays; the cases with merchant's acceptances again end to end, read
 * on 3 local relays.
 */
final class OnchainPaymentsVectorsTest extends TestCase
{
    /** The invoice's address and its end. */
    private const ADDRESS = 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4';

    private const EXPIRES_AT = 1791379180;

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function cases(): iterable
    {
        yield from V::indexed('vectors/onchain-payments.json', ['cases']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function merchantAcceptanceCases(): iterable
    {
        foreach (self::cases() as $name => [$case, $index]) {
            if (\array_key_exists('merchantAccepted', $case)) {
                yield $name => [$case, $index];
            }
        }
    }

    public function testTheWitnessesOfAnotherOrderAreAtADepositAddressOfItsOwn(): void
    {
        $invoice = V::a(V::object('vectors/onchain-payments.json')['invoice']);
        self::assertTrue(DepositAddress::isValid(VectorSource::OTHER_ADDRESS));
        self::assertNotSame($invoice['onchainAddress'], VectorSource::OTHER_ADDRESS);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testAStatus(array $case, int $index): void
    {
        self::check($case, $index, null);
    }

    public function testTheMerchantsAcceptancesItMakesCheckOut(): void
    {
        // Sealed and signed by merchant-acceptance.json's team, with the app's integration key.
        $case = ['now' => 1791378340, 'merchantAccepted' => [['received' => '30000', 'createdAt' => 1791378300]]];
        $source = new VectorSource($case, 0, self::ADDRESS, self::sale(), IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']), self::EXPIRES_AT);
        $event = $source->merchantAcceptances[VectorSource::MERCHANT_RELAYS[0]][0] ?? null;
        self::assertNotNull($event);
        $opened = MerchantAcceptance::open($event->toJson(), $source->merchantKeys, '49000', self::EXPIRES_AT);
        self::assertSame([MerchantAcceptance::OK, '30000'], [$opened[0] ?? null, $opened[2] ?? null]);
    }

    /**
     * The merchant's acceptances end to end (PORTING.md, step 4): the merchant's 3 relays, local,
     * each storing the events it holds as they are, several at one address included; the check
     * reads them on every relay (MerchantAcceptance::read()). The first, which holds every record,
     * answers 150 ms after the others: a read that went on with the first majority to answer
     * would miss what it holds. "unreadable": the 2 others never answer the read.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('merchantAcceptanceCases')]
    public function testAStatusEndToEnd(array $case, int $index): void
    {
        $relays = new Relays(new TestConnector());
        try {
            $unreadable = $case['merchantAccepted'] === 'unreadable';
            $source = self::check($case, $index, $relays, static function (VectorSource $source) use ($relays, $unreadable): void {
                foreach (VectorSource::MERCHANT_RELAYS as $at => $url) {
                    $relay = $relays->add($url);
                    foreach ($source->merchantAcceptances[$url] as $event) {
                        $relay->store($event);
                    }
                    $relay->queryDelayMs = $at === 0 ? 150 : 0;
                    $relay->mute = $unreadable && $at > 0;
                }
            });
            $filter = Json::encode(['kinds' => [Protocol::RECORD_KIND], 'authors' => [$source->merchantKeys->team], '#d' => [$source->merchantKeys->address]]);
            foreach ($relays->relays as $at => $relay) {
                // Nothing published there: a check never publishes a merchant's acceptance, nor again.
                self::assertSame([], $relay->received, "{$at}: nothing published");
                // Read on every relay, as the check reads it: each relay asked once, by its address alone.
                $asked = $source->merchantAcceptanceRead && !$relay->mute ? [$filter] : [];
                self::assertSame($asked, array_map(Json::encode(...), $relay->queries), "{$at}: asked");
            }
        } finally {
            $relays->stop();
        }
    }

    /** The order's sale: that of vectors/app/app.json's declaration (PORTING.md, step 4). */
    private static function sale(): string
    {
        return V::s(V::o(V::object('vectors/app/app.json')['declaration'])['saleAuthor']);
    }

    /**
     * One case through the package's payment check (OnchainPart, Combined), the merchant's
     * acceptances counted from what the 3 relays hold, or, with $merchantRelays, read on them end
     * to end ($ready makes them ready, from the case's source); then the acceptance record it
     * publishes (Acceptance::publish()).
     *
     * @param array<string, mixed>                $case
     * @param (\Closure(VectorSource): void)|null $ready
     */
    private static function check(array $case, int $index, ?Relays $merchantRelays, ?\Closure $ready = null): VectorSource
    {
        $invoice = V::a(V::object('vectors/onchain-payments.json')['invoice']);
        $address = V::s($invoice['onchainAddress']);
        $sats = V::s($invoice['sats']);
        \assert(is_numeric($sats));
        $expiresAt = V::i($invoice['expiresAt']);
        $now = V::i($case['now']);
        self::assertSame([self::ADDRESS, self::EXPIRES_AT], [$address, $expiresAt]);
        $key = IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']);
        $sale = self::sale();
        $source = new VectorSource($case, $index, $address, $sale, $key, $expiresAt, $merchantRelays);
        if ($ready !== null) {
            $ready($source);
        }
        $context = TestContext::create(new TestConnector(), $now);

        $lightning = self::lightning($case['lightning']);
        $rests = new RestPart(array_map(self::restInvoice(...), V::l($case['rests'] ?? [])));
        $tolerance = \array_key_exists('tolerance', $case) ? Tolerance::read($case['tolerance']) : 0;
        // As a payment check runs it: in a call of the loop (the explorer's two reads are asked at once).
        $onchain = Loop::get()->run(static function () use ($source, $context, $address, $expiresAt, $sale, $lightning, $sats, $tolerance, $rests): OnchainResult|SattlemeException|null {
            try {
                $observed = OnchainPart::observe($source, $context, $address, $expiresAt, $sale, $sats);
            } catch (NetworkException $e) {
                $observed = $e;
            }

            return Combined::judge($observed, $lightning, $sats, $tolerance, $rests->paid(), $expiresAt);
        });
        // A merchant's acceptance of a newer format is logged when the check read it, whatever its
        // date and however many relays sent it.
        $newer = \is_array($case['merchantAccepted'] ?? null) && \in_array(true, array_map(static fn(mixed $record): bool => isset(V::a($record)['newer']), $case['merchantAccepted']), true);
        $warned = array_filter(array_column(TestContext::$warnings, 0), static fn(string $warning): bool => str_starts_with($warning, 'A merchant\'s acceptance of this order has a newer format'));
        self::assertCount($newer && $source->merchantAcceptanceRead ? 1 : 0, $warned, 'warned of a newer format');
        $expected = V::a($case['expected']);
        try {
            $status = Combined::status($sats, $expiresAt, $address, $now, $lightning, $onchain, $rests);
        } catch (SattlemeException $e) {
            self::assertSame($expected, ['error' => $e->getErrorCode()], $e->getMessage());
            $status = null;
        }
        if ($status !== null) {
            self::assertSame($expected, $status->toArray());
        }
        // What it published before failing is not checked.
        if (!isset($expected['error'])) {
            self::assertSame($case['published'], $source->published, 'published');
        }
        self::assertSame($case['asked'], $source->asked(), 'asked');

        if ($status !== null) {
            $accepted = \array_key_exists('accepted', $case) ? V::s($case['accepted']) : null;
            \assert($accepted === null || is_numeric($accepted));
            self::assertSame($case['acceptance'] ?? null, self::acceptancePublished($status, $key, $address, $sats, $expiresAt, $now, $accepted), 'acceptance');
        }

        return $source;
    }

    /**
     * What the check publishes of an acceptance record when its status is $status (SPEC.md §31,
     * "Acceptance records"), as the client does (Acceptance::publish()), on three local relays
     * each of which holds one of $accepted (null: none holds one), so that every majority that
     * answers reads it: the `received` of the record the relays took, null when they took none.
     *
     * @param numeric-string      $sats
     * @param numeric-string|null $accepted
     */
    private static function acceptancePublished(PaymentStatus $status, IntegrationKey $key, string $address, string $sats, int $expiresAt, int $now, ?string $accepted): ?string
    {
        $relays = new Relays(new TestConnector());
        try {
            $urls = ['wss://one.relay.example', 'wss://two.relay.example', 'wss://three.relay.example'];
            $keys = RestKeys::of($key, 'ORD-1042', $address, $expiresAt);
            $context = TestContext::create($relays->connector, $now);
            $held = $accepted === null ? null : Acceptance::event($keys, (int) $accepted, $expiresAt, $now - 60, $context);
            foreach ($urls as $url) {
                $relay = $relays->add($url);
                if ($held !== null) {
                    $relay->store($held);
                }
            }
            $published = Loop::get()->run(static fn(): ?string => Acceptance::publish(new RelayPool($context), $context, $urls, static fn(): RestKeys => $keys, $status, $sats, $expiresAt, $now));
            // What the relays took: one record, on a majority of them, or none at all.
            $took = [];
            foreach ($relays->relays as $relay) {
                foreach ($relay->received as $value) {
                    $read = Acceptance::read($value, $keys, $sats);
                    self::assertNotNull($read);
                    self::assertSame('ok', $read[0]);
                    $took[$read[1]] = ($took[$read[1]] ?? 0) + 1;
                }
            }
            if ($published === null) {
                self::assertSame([], $took, 'no record taken');
            } else {
                self::assertSame([$published], array_map('strval', array_keys($took)));
                self::assertGreaterThanOrEqual(RelayPool::majority(\count($urls)), $took[$published]);
            }

            return $published;
        } finally {
            $relays->stop();
        }
    }

    /**
     * @return array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null
     */
    private static function lightning(mixed $value): array|SattlemeException|null
    {
        if ($value === null) {
            return null;
        }
        $value = V::a($value);
        if (isset($value['error'])) {
            return $value['error'] === 'network' ? new NetworkException('the Lightning part fails') : new InvalidInvoiceException('the Lightning part fails');
        }

        return match ($value['state']) {
            'open' => ['open'],
            'expired' => ['expired'],
            'paid' => ['paid', V::s($value['preimage'])],
            default => throw new \RuntimeException('A state expected'),
        };
    }

    private static function restInvoice(mixed $value): RestInvoice
    {
        $value = V::a($value);
        $sats = V::s($value['sats']);
        \assert(is_numeric($sats));
        $invoice = new RestInvoice(V::s($value['invoice']), V::s($value['paymentHash']), 'https://breez.tips/lnurlp/verify/x', $sats, V::i($value['createdAt']), V::i($value['expiresAt']));
        $state = self::lightning($value['lightning']);
        if ($state instanceof SattlemeException) {
            $invoice->error = $state;
        } else {
            $invoice->state = $state;
        }

        return $invoice;
    }
}
