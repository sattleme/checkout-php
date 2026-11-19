<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;
use Sattleme\Checkout\Tests\Support\World;

/**
 * What a declaration or a lease can hold (SPEC.md §17, step 6): their sizes, told before anything
 * is published and without drawing random bytes, are those of the events they would be.
 */
final class RecordSizeTest extends TestCase
{
    private const FIAT = ['minor' => 49000, 'digits' => 0, 'currency' => 'SAT'];

    public function testThePayloadLengthIsTheEncryptedOnes(): void
    {
        $key = random_bytes(32);
        foreach ([1, 2, 31, 32, 33, 64, 255, 256, 257, 511, 1000, 4096, 40959, 40960, 40961, 49152, 65534, 65535] as $length) {
            self::assertSame(\strlen(Nip44::encrypt(str_repeat('a', $length), $key, random_bytes(32))), Nip44::payloadLength($length), (string) $length);
        }
    }

    public function testADeclarationFitsExactlyWhenItsEventCanBeMade(): void
    {
        [$sale, $registry] = self::sale();
        $inside = static fn(int $length): array => Declaration::inside(str_repeat('q', $length), 'https://breez.tips/lnurlp/verify/x', $sale->keyHex(), self::FIAT, 'ORD-1', $sale->takerTag, null);
        $largest = self::largest(static fn(int $length): bool => Declaration::fits($inside($length)));
        $event = static fn(int $length) => Declaration::event($sale, $registry, str_repeat('ab', 32), $inside($length), 1791378000, TestContext::create(new TestConnector()));
        self::assertGreaterThan(40550, $largest, 'an invoice of 40 550 characters fits a declaration');
        self::assertSame(30078, $event($largest)->kind);
        $this->expectException(\LengthException::class);
        $event($largest + 1);
    }

    public function testALeaseFitsExactlyWhenItsEventCanBeMade(): void
    {
        [$sale, $registry] = self::sale();
        $address = World::address();
        self::assertSame(\strlen(DepositAddress::SIZED), \strlen($address), 'every deposit address of one length');
        $inside = static fn(int $length, string $address, bool $confirmed): array => Lease::inside($address, 49000, str_repeat('q', $length), 'https://breez.tips/lnurlp/verify/x', 1791378000, 1791378900, $sale->keyHex(), self::FIAT, 'ORD-1', $sale->takerTag, 437, $confirmed);
        $largest = self::largest(static fn(int $length): bool => Lease::fits($inside($length, DepositAddress::SIZED, false)));
        self::assertLessThan(40550, $largest, 'an invoice of 40 550 characters does not fit a lease');
        // Measured as first published (SPEC.md §17, step 6): one byte more fits its mark,
        // `"confirmed": true`, one byte shorter, not the lease.
        self::assertTrue(Lease::fits($inside($largest + 1, DepositAddress::SIZED, true)));
        $event = static fn(int $length, bool $confirmed) => Lease::event($sale, $registry, $address, $inside($length, $address, $confirmed), 1791378000, TestContext::create(new TestConnector()));
        self::assertSame(30078, $event($largest, false)->kind);
        self::assertSame(30078, $event($largest, true)->kind, 'its mark, as large or smaller');
        $this->expectException(\LengthException::class);
        $event($largest + 1, false);
    }

    /** @return array{0: Sale, 1: Registry} */
    private static function sale(): array
    {
        $key = IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']);

        return [Sale::of($key, 'ORD-1'), new Registry(new Secret(random_bytes(32)), Derive::author(random_bytes(31) . "\x02"))];
    }

    /**
     * The largest length that $fits, which holds up to a length and from there on never: by
     * bisection.
     *
     * @param \Closure(int): bool $fits
     */
    private static function largest(\Closure $fits): int
    {
        [$low, $high] = [1, 70000];
        self::assertTrue($fits($low));
        self::assertFalse($fits($high));
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            if ($fits($middle)) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
