<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\Stamp;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\LeaseRecord;
use Sattleme\Checkout\Internal\Onchain\Lot;
use Sattleme\Checkout\Internal\Onchain\Witness;
use Sattleme\Checkout\Internal\Onchain\WitnessKeys;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The on-chain records (SPEC.md §31): the app's lot, leases, witnesses.
 */
final class OnchainRecordsVectorsTest extends TestCase
{
    public function testTheAppsLotOpens(): void
    {
        $vector = V::object('vectors/app/onchain-lot.json');
        $key = IntegrationKey::parse($vector['secretKey']);
        $ik = $key->key->reveal();
        self::assertSame($vector['team'], $key->team);
        self::assertSame($vector['lotAddress'], Derive::lotAddress($ik));
        self::assertSame($vector['channelEncryptionKey'], bin2hex(Derive::channelEncryptionKey($ik)));

        $event = V::json('vectors/app/onchain-lot.json', ['lot', 'event']);
        $context = TestContext::create(new TestConnector());
        $lot = Lot::fromAnswers(['wss://relay.example' => [$event]], $context, $key->team, V::s($vector['lotAddress']), Derive::channelEncryptionKey($ik));
        self::assertSame(V::a(V::a(V::a($vector['lot'])['value'])['v'])['addresses'], $lot);
        self::assertSame([], TestContext::$warnings);
    }

    public function testALotValueIsChecked(): void
    {
        $event = Event::fromJson(V::json('vectors/app/onchain-lot.json', ['lot', 'event']));
        \assert($event instanceof Event);
        $a = 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4';
        $value = static fn(array $fields): mixed => Json::decode(Json::encode($fields + ['version' => 1, 'updatedAt' => 1791378600, 'addresses' => [$a]]));
        self::assertSame(['ok', [$a]], Lot::validate($value([]), $event));
        self::assertSame(['ok', []], Lot::validate($value(['addresses' => []]), $event));
        self::assertSame(['newer'], Lot::validate($value(['version' => 2]), $event));
        self::assertNull(Lot::validate($value(['version' => '1']), $event));
        self::assertNull(Lot::validate($value(['updatedAt' => 1791378601]), $event));
        self::assertNull(Lot::validate($value(['addresses' => [$a, $a]]), $event), 'an address twice');
        self::assertNull(Lot::validate($value(['addresses' => [strtoupper($a)]]), $event));
        self::assertNull(Lot::validate($value(['addresses' => array_fill(0, 101, $a)]), $event));
        self::assertNull(Lot::validate($value(['addresses' => 'x']), $event));
        self::assertNull(Lot::validate($value(['addresses' => (object) []]), $event));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function leaseEvents(): iterable
    {
        yield from V::indexed('vectors/onchain-leases.json', ['events']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('leaseEvents')]
    public function testALeaseOpens(array $case, int $index): void
    {
        [$sale, $registry] = self::sale();
        $vector = V::object('vectors/onchain-leases.json');
        $value = V::json('vectors/onchain-leases.json', ['events', $index, 'event']);
        $registryAddress = Derive::depositRegistryAddress($registry->key->reveal(), V::s($case['address']));

        // Opening it at a lease's place, whatever its author: its id first, no signature checked.
        $outside = Lease::outside($value, $registry, $registryAddress);
        if ($case['outside'] === null) {
            self::assertNull($outside);
        } elseif ($case['outside'] === 'newer') {
            self::assertNotNull($outside);
            self::assertSame(LeaseRecord::NEWER, $outside->kind);
        } else {
            $expected = V::a($case['outside']);
            self::assertNotNull($outside);
            if (($expected['withdrawn'] ?? false) === true) {
                self::assertSame(LeaseRecord::WITHDRAWN, $outside->kind);
            } else {
                self::assertSame(LeaseRecord::LEASE, $outside->kind);
                self::assertSame($expected['end'], $outside->end);
            }
        }
        // Its signature, checked where it decides: as the vector says (absent: it checks out).
        $event = Event::fromJson($value);
        self::assertNotNull($event);
        self::assertSame($case['signature'] ?? true, $event->verify());
        if ($outside !== null) {
            self::assertSame($case['signature'] ?? true, $outside->signed());
        }

        $opened = Lease::open($value, $sale, $registry);
        if ($case['inside'] === null) {
            self::assertTrue($opened === null || $opened[0] !== 'ok', 'no lease of the sale');
            if ($case['outside'] !== null && $opened !== null) {
                self::assertSame(\is_array($case['outside']) && isset($case['outside']['withdrawn']) ? 'withdrawn' : 'newer', $opened[0]);
            }

            return;
        }
        $inside = V::a($case['inside']);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        $lease = $opened[1];
        self::assertSame($inside['address'], $lease->address);
        self::assertSame((string) V::i($inside['sats']), $lease->sats);
        self::assertSame($inside['invoice'], $lease->invoice);
        self::assertSame($inside['verifyUrl'], $lease->verifyUrl);
        self::assertSame($inside['createdAt'], $lease->createdAt);
        self::assertSame($inside['expiresAt'], $lease->expiresAt);
        self::assertSame(V::a($inside['sale'])['fiat'], $lease->fiat);
        self::assertSame(V::a($inside['sale'])['note'], $lease->note);
        self::assertSame($inside['taker'], $lease->taker);
        self::assertSame($case['tolerance'] ?? 0, $lease->tolerance());
        // Confirmed: `confirmed` true (absent from the case: true).
        self::assertSame($case['confirmed'] ?? true, $lease->isConfirmed());

        // The vector's keys open it the same way.
        self::assertSame($vector['innerConversationKey'], bin2hex($sale->innerKey($registry)));
    }

    public function testTheVectorsSaleIsTheAppsDeclarations(): void
    {
        [$sale, $registry] = self::sale();
        $vector = V::object('vectors/onchain-leases.json');
        self::assertSame($vector['saleAuthor'], $sale->author);
        self::assertSame($vector['saleSecret'], bin2hex($sale->secret->reveal()));
        self::assertSame($vector['registryReader'], $registry->reader);
        foreach (V::a($vector['derived']) as $derived) {
            $derived = V::a($derived);
            $registryAddress = Derive::depositRegistryAddress($registry->key->reveal(), V::s($derived['address']));
            self::assertSame($derived['registryAddress'], $registryAddress);
            self::assertSame($derived['outerKey'], bin2hex(Derive::outerKey($registry->key->reveal(), $registryAddress, $sale->author)));
        }
    }

    public function testOurLeaseOpensWithTheVectorsKeys(): void
    {
        [$sale, $registry] = self::sale();
        $vector = V::object('vectors/onchain-leases.json');
        $context = TestContext::create(new TestConnector());
        foreach (V::a($vector['events']) as $case) {
            $case = V::o($case);
            if (!\is_array($case['inside'])) {
                continue;
            }
            $inside = V::a($case['inside']);
            $sats = V::i($inside['sats']);
            /** @var array{minor: int, digits: int, currency: string} $fiat */
            $fiat = V::a(V::a($inside['sale'])['fiat']);
            $json = Lease::inside(
                V::s($inside['address']),
                $sats,
                V::ns($inside['invoice']),
                V::ns($inside['verifyUrl']),
                V::i($inside['createdAt']),
                V::i($inside['expiresAt']),
                $sale->keyHex(),
                $fiat,
                V::s(V::a($inside['sale'])['note']),
                $sale->takerTag,
                \is_int($inside['tolerance'] ?? null) ? $inside['tolerance'] : 0,
                \is_bool($inside['confirmed'] ?? null) ? $inside['confirmed'] : null,
            );
            $event = Lease::event($sale, $registry, V::s($inside['address']), $json, V::i(V::a($case['event'])['created_at']), $context);
            self::assertTrue($event->verify());
            self::assertSame($sale->author, $event->pubkey);
            self::assertSame(V::a($case['event'])['tags'], $event->tags);
            $opened = Lease::open($event->toJson(), $sale, $registry);
            self::assertNotNull($opened);
            self::assertSame('ok', $opened[0]);
            // The inside is exactly the JSON the vector's lease holds, when its tolerance is one.
            if (($case['tolerance'] ?? 0) === ($inside['tolerance'] ?? 0)) {
                $theirs = self::insideText($case, V::bytes(V::object('vectors/onchain-leases.json')['innerConversationKey']), $registry, $sale);
                $outer = Json::decode(Nip44::decrypt($event->content, Derive::outerKey($registry->key->reveal(), V::s(V::a(V::a($case['event'])['tags'][0])[1]), $sale->author)));
                $ours = Nip44::decrypt(V::s(Json::get(Json::get($outer, 'v'), 'sealed')), V::bytes($vector['innerConversationKey']));
                self::assertSame($theirs, $ours);
            }
        }
        $withdrawal = Lease::withdrawal($sale, $registry, 'bc1p4gmh64rd4m5y5nn8hwfnvtz2mm6lngpmwys5a257klyg03ju0pyqu65xe8', 1791381281, $context);
        $opened = Lease::open($withdrawal->toJson(), $sale, $registry);
        self::assertNotNull($opened);
        self::assertSame('withdrawn', $opened[0]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function leased(): iterable
    {
        yield from V::named(V::l(V::object('vectors/onchain-leases.json')['leased']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('leased')]
    public function testAnAddressIsLeased(array $case): void
    {
        $checked = [];
        $records = self::records(V::a($case['records']), $checked);
        self::assertSame($case['expected'], Lease::held(array_values($records), V::i($case['t'])));
        // The signatures checked: those of the records that would end an older one's hold.
        sort($checked);
        self::assertSame($case['checked'], $checked);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function confirmed(): iterable
    {
        yield from V::named(V::l(V::object('vectors/onchain-leases.json')['confirmed']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('confirmed')]
    public function testALeaseIsConfirmed(array $case): void
    {
        $checked = [];
        $records = self::records(V::a($case['records']), $checked);
        // This lease, or one that is not among the records read.
        $own = $case['lease'] === null
            ? Stamp::of(self::fakeEvent(V::s($case['sale']), V::i($case['t']), str_repeat('f', 64)))
            : $records[V::i($case['lease'])]->stamp;
        self::assertSame($case['expected'], Lease::isConfirmedBy(array_values($records), $own, V::i($case['t']), $case['complete'] === true));
    }

    public function testTheWitnessKeysAndAddresses(): void
    {
        $vector = V::object('vectors/onchain-witnesses.json');
        $keys = self::witnessKeys();
        $derived = V::a($vector['derived']);
        self::assertSame($derived['seenSecret'], bin2hex($keys->seenSecret->reveal()));
        self::assertSame($derived['seenAuthor'], $keys->seenAuthor);
        self::assertSame($derived['seenAddressKey'], bin2hex($keys->seenAddressKey->reveal()));
        self::assertSame($vector['channelEncryptionKey'], bin2hex($keys->encryptionKey->reveal()));
        foreach (V::a($vector['addresses']) as $case) {
            $case = V::a($case);
            self::assertSame($case['witnessAddress'], Derive::witnessAddress($keys->seenAddressKey->reveal(), V::s($case['address']), V::s($case['txid']), V::ns($case['sale'])));
        }
        foreach (V::a($vector['orderTags']) as $case) {
            $case = V::a($case);
            self::assertSame($case['orderTag'], Derive::orderTag($keys->seenAddressKey->reveal(), V::s($case['address']), V::ns($case['sale'])));
        }
        foreach (V::a($vector['addressTags']) as $case) {
            $case = V::a($case);
            self::assertSame($case['addressTag'], Derive::addressTag($keys->seenAddressKey->reveal(), V::s($case['address'])));
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function witnessEvents(): iterable
    {
        yield from V::indexed('vectors/onchain-witnesses.json', ['events']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('witnessEvents')]
    public function testAWitnessOpens(array $case, int $index): void
    {
        $keys = self::witnessKeys();
        $value = V::json('vectors/onchain-witnesses.json', ['events', $index, 'event']);
        $opened = Witness::open($value, $keys);
        if ($case['value'] === null) {
            self::assertNull($opened);

            return;
        }
        if ($case['value'] === 'newer') {
            self::assertNotNull($opened);
            self::assertSame('newer', $opened[0]);

            return;
        }
        $expected = V::a($case['value']);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        $witness = $opened[1];
        self::assertSame($expected['address'], $witness->address);
        self::assertSame($expected['txid'], $witness->txid);
        self::assertSame($expected['sale'], $witness->sale);
        self::assertSame((string) V::i($expected['sats']), $witness->sats);
        self::assertSame($expected['inputs'], $witness->inputs);
        self::assertSame($expected['replacement'], $witness->replacement);
        // Its invoice's `expiresAt`, null for none.
        self::assertArrayHasKey('expiresAt', $expected);
        self::assertSame($expected['expiresAt'], $witness->expiresAt);

        // Ours, of the same value, opens to the same, at the same address, with the order tag and
        // the address tag, which the vector's holds (with tags to ignore, for some).
        $context = TestContext::create(new TestConnector());
        /** @var list<string> $inputs */
        $inputs = V::a($expected['inputs']);
        $event = Witness::event($keys, $witness->address, $witness->txid, $witness->sale, V::i($expected['sats']), $inputs, $witness->replacement, $witness->expiresAt, $witness->event->createdAt, $context);
        $seenAddressKey = $keys->seenAddressKey->reveal();
        self::assertSame([
            ['d', Derive::witnessAddress($seenAddressKey, $witness->address, $witness->txid, $witness->sale)],
            ['o', Derive::orderTag($seenAddressKey, $witness->address, $witness->sale)],
            ['o', Derive::addressTag($seenAddressKey, $witness->address)],
        ], $event->tags);
        foreach ($event->tags as $tag) {
            self::assertContains($tag, $witness->event->tags);
        }
        self::assertSame($keys->seenAuthor, $event->pubkey);
        $ours = Witness::open($event->toJson(), $keys);
        self::assertNotNull($ours);
        self::assertSame('ok', $ours[0]);
        self::assertEquals($witness->inputs, $ours[1]->inputs);
        self::assertSame($witness->expiresAt, $ours[1]->expiresAt);
    }

    /**
     * The addresses an event names when leasing (SPEC.md §31, "Leasing an address", step 3), as
     * Leasing reads them: the addresses of `addressTags`, by their address tags as derived.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('witnessEvents')]
    public function testTheAddressesAnEventNames(array $case, int $index): void
    {
        $keys = self::witnessKeys();
        $byTag = [];
        foreach (V::a(V::object('vectors/onchain-witnesses.json')['addressTags']) as $tagged) {
            $address = V::s(V::a($tagged)['address']);
            $byTag[Derive::addressTag($keys->seenAddressKey->reveal(), $address)] = $address;
        }
        $named = Witness::addressesNamed(V::json('vectors/onchain-witnesses.json', ['events', $index, 'event']), $keys->seenAuthor, $byTag);
        $expected = V::l($case['names']);
        sort($named);
        sort($expected);
        self::assertSame($expected, $named);
    }

    public function testAWitnessKeepsTheFirst100Coins(): void
    {
        $coins = [];
        for ($i = 0; $i < 150; ++$i) {
            $coins[] = str_repeat('ab', 32) . ':' . $i;
        }
        $value = Witness::value('bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4', str_repeat('cd', 32), null, 1000, $coins, false, null);
        self::assertSame(\array_slice($coins, 0, 100), $value['inputs']);
        self::assertTrue(Witness::isCoin(str_repeat('ab', 32) . ':4294967295'));
        self::assertFalse(Witness::isCoin(str_repeat('ab', 32) . ':4294967296'));
        self::assertFalse(Witness::isCoin(str_repeat('ab', 32) . ':01'));
        self::assertFalse(Witness::isCoin(str_repeat('AB', 32) . ':1'));
        self::assertFalse(Witness::isCoin(str_repeat('ab', 32) . ':'));
        self::assertFalse(Witness::isCoin(str_repeat('ab', 32) . ':-1'));
    }

    /**
     * @return array{0: Sale, 1: Registry}
     */
    private static function sale(): array
    {
        $app = V::object('vectors/app/app.json');
        $key = IntegrationKey::parse($app['secretKey']);
        $leases = V::object('vectors/onchain-leases.json');
        $registry = new Registry(new Secret(V::bytes($leases['registryKey'])), V::s($leases['registryReader']));

        return [Sale::of($key, V::s(V::a($app['declaration'])['ref'])), $registry];
    }

    private static function witnessKeys(): WitnessKeys
    {
        $app = V::object('vectors/app/app.json');
        $key = IntegrationKey::parse($app['secretKey']);
        self::assertSame(V::object('vectors/onchain-witnesses.json')['integrationKey'], bin2hex($key->key->reveal()));

        return WitnessKeys::of($key);
    }

    /**
     * The JSON inside a vector's lease, as the sale opens it.
     *
     * @param array<string, mixed> $case
     */
    private static function insideText(array $case, string $innerKey, Registry $registry, Sale $sale): string
    {
        $event = V::a($case['event']);
        $d = V::s(V::a(V::a($event['tags'])[0])[1]);
        $outer = Json::decode(Nip44::decrypt(V::s($event['content']), Derive::outerKey($registry->key->reveal(), $d, $sale->author)));

        return Nip44::decrypt(V::s(Json::get(Json::get($outer, 'v'), 'sealed')), $innerKey);
    }

    /**
     * The records of a vector of `leased` or `confirmed`, as opened: one whose id is not its hash
     * never is. Their signatures as the vector says, the index of each record checked into $checked.
     *
     * @param array<array-key, mixed> $vector
     * @param list<int>               $checked
     *
     * @return array<int, LeaseRecord> by index in the vector
     */
    private static function records(array $vector, array &$checked): array
    {
        $records = [];
        foreach ($vector as $index => $record) {
            $record = V::a($record);
            \assert(\is_int($index));
            if (($record['id'] ?? true) === false) {
                continue;
            }
            $event = self::fakeEvent(V::s($record['author']), V::i($record['createdAt']), \is_string($record['eventId'] ?? null) ? $record['eventId'] : str_pad((string) $index, 64, '0', \STR_PAD_LEFT));
            $signature = ($record['signature'] ?? true) !== false;
            $verify = static function () use (&$checked, $index, $signature): bool {
                $checked[] = $index;

                return $signature;
            };
            $outside = $record['outside'];
            if ($outside === 'newer') {
                $records[$index] = new LeaseRecord(LeaseRecord::NEWER, null, Stamp::of($event), $verify);
            } elseif ((V::a($outside)['withdrawn'] ?? false) === true) {
                $records[$index] = new LeaseRecord(LeaseRecord::WITHDRAWN, null, Stamp::of($event), $verify);
            } else {
                $records[$index] = new LeaseRecord(LeaseRecord::LEASE, V::i(V::a($outside)['end']), Stamp::of($event), $verify);
            }
        }

        return $records;
    }

    /** An event of $author at $createdAt, for the rules on records alone (its id orders ties). */
    private static function fakeEvent(string $author, int $createdAt, string $id): Event
    {
        $event = Event::fromJson((object) [
            'id' => $id,
            'pubkey' => hash('sha256', $author),
            'created_at' => $createdAt,
            'kind' => 30078,
            'tags' => [],
            'content' => '',
            'sig' => str_repeat('00', 64),
        ]);
        \assert($event instanceof Event);

        return $event;
    }
}
