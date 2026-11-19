<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit\Onchain;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\Witness;
use Sattleme\Checkout\Internal\Onchain\WitnessKeys;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * SPEC.md §2: a member is null only when it is there with that value. Where SPEC.md §31 says that
 * a record's member may be null (a witness's `sale`, a lease's `invoice` and `verifyUrl`), a record
 * without it is not one: a reader that took absent for null would read a witness none, or a lease
 * of an order paid on-chain only, that nobody wrote.
 */
final class AbsentMembersTest extends TestCase
{
    private const ADDRESS = 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4';
    private const TXID = '30b6893398f0a7832d1052a9e120cdf7e87d9ec180a897ae6c15edd18574e3e8';

    public function testAWitnessWithoutSaleIsNone(): void
    {
        $keys = WitnessKeys::of(IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']));
        $value = Witness::value(self::ADDRESS, self::TXID, null, 49000, [str_repeat('fc', 32) . ':0'], false, null);
        self::assertNotNull(self::openWitness($keys, $value), 'a witness none, `sale` null');
        unset($value['sale']);
        self::assertNull(self::openWitness($keys, $value), 'no witness without `sale`');
    }

    public function testALeaseWithoutInvoiceOrVerifyUrlIsNone(): void
    {
        $app = V::object('vectors/app/app.json');
        $leases = V::object('vectors/onchain-leases.json');
        $registry = new Registry(new Secret(V::bytes($leases['registryKey'])), V::s($leases['registryReader']));
        $sale = Sale::of(IntegrationKey::parse($app['secretKey']), V::s(V::a($app['declaration'])['ref']));
        $inside = Lease::inside(self::ADDRESS, 49000, null, null, 1791378280, 1791379180, $sale->keyHex(), ['minor' => 49000, 'digits' => 0, 'currency' => 'SAT'], 'ORD-1', $sale->takerTag, 0);
        $opened = self::openLease($sale, $registry, $inside);
        self::assertSame('ok', $opened, 'a lease of an order paid on-chain only: both null');
        foreach ([['invoice'], ['verifyUrl'], ['invoice', 'verifyUrl']] as $absent) {
            $without = $inside;
            foreach ($absent as $member) {
                unset($without[$member]);
            }
            self::assertNull(self::openLease($sale, $registry, $without), 'no lease without ' . implode(' nor ', $absent));
        }
    }

    /**
     * What a witness of $value, sealed and signed as the integration seals one, opens to.
     *
     * @param array<string, mixed> $value
     */
    private static function openWitness(WitnessKeys $keys, array $value): ?string
    {
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $event = SealedRecord::seal(
            Protocol::WITNESS_TYPE,
            $value,
            $keys->seenSecret->reveal(),
            $keys->encryptionKey->reveal(),
            Derive::witnessAddress($seenAddressKey, self::ADDRESS, self::TXID, null),
            1791378380,
            static fn(int $bytes): string => random_bytes(max(1, $bytes)),
            [['o', Derive::orderTag($seenAddressKey, self::ADDRESS, null)], ['o', Derive::addressTag($seenAddressKey, self::ADDRESS)]],
        );

        return Witness::open(Json::decode(Json::encode($event->toJson())), $keys)[0] ?? null;
    }

    /**
     * What the sale's lease of $inside, sealed and signed as the sale seals one, opens to.
     *
     * @param array<string, mixed> $inside
     */
    private static function openLease(Sale $sale, Registry $registry, array $inside): ?string
    {
        $event = Lease::event($sale, $registry, self::ADDRESS, $inside, 1791378280, TestContext::create(new TestConnector()));

        return Lease::open(Json::decode(Json::encode($event->toJson())), $sale, $registry)[0] ?? null;
    }
}
