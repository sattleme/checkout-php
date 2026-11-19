<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Crypto\Hkdf;
use Sattleme\Checkout\Internal\Crypto\Point;
use Sattleme\Checkout\Internal\Crypto\Schnorr;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;

/** The edges of the curve's arithmetic, which random keys and the vectors never reach. */
final class Secp256k1Test extends TestCase
{
    public function testSecretKeysAreFromOneToTheOrderMinusOne(): void
    {
        $n = BigInt::toBytes(Secp256k1::N, 32);
        $nMinusOne = BigInt::toBytes(BigInt::sub(Secp256k1::N, '1'), 32);
        self::assertFalse(Secp256k1::isSecretKey(str_repeat("\0", 32)));
        self::assertTrue(Secp256k1::isSecretKey(str_repeat("\0", 31) . "\1"));
        self::assertTrue(Secp256k1::isSecretKey($nMinusOne));
        self::assertFalse(Secp256k1::isSecretKey($n));
        self::assertFalse(Secp256k1::isSecretKey(str_repeat("\xff", 32)));
        self::assertFalse(Secp256k1::isSecretKey(str_repeat("\1", 31)));
        // OpenSSL itself takes 0 and n (PHP's openssl_pkey_new() even swaps them for a random key).
        foreach ([str_repeat("\0", 32), $n] as $secret) {
            try {
                Secp256k1::publicPoint($secret);
                self::fail('A secret out of range was taken');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // (n − 1)·G = −G.
        self::assertEquals(Secp256k1::negate(self::g(1)), Secp256k1::publicPoint($nMinusOne));
    }

    public function testLiftXRefusesWhatIsNotAnXCoordinateBelowP(): void
    {
        self::assertNull(Secp256k1::liftX(BigInt::toBytes(Secp256k1::P, 32)));
        self::assertNull(Secp256k1::liftX(str_repeat("\xff", 32)));
        // x = 0: 7 has no square root modulo p. x = 1: 8 has one.
        self::assertNull(Secp256k1::liftX(str_repeat("\0", 32)));
        $one = Secp256k1::liftX(str_repeat("\0", 31) . "\1");
        self::assertNotNull($one);
        self::assertSame('1', $one->x);
        self::assertTrue($one->hasEvenY());
        self::assertTrue(Secp256k1::isOnCurve($one));
        // p + 1 would be x = 1 again if OpenSSL reduced it: it must not.
        self::assertNull(Secp256k1::liftX(BigInt::toBytes(BigInt::add(Secp256k1::P, '1'), 32)));
        self::assertNull(Secp256k1::liftX(str_repeat("\1", 31)));
    }

    public function testSumsWithoutInversion(): void
    {
        $g = self::g(1);
        $g2 = self::g(2);
        $g3 = self::g(3);
        self::assertTrue(Secp256k1::isSum($g, $g, $g2), 'doubling');
        self::assertTrue(Secp256k1::isSum($g, $g2, $g3));
        self::assertTrue(Secp256k1::isSum($g2, $g, $g3));
        self::assertTrue(Secp256k1::isSum($g3, Secp256k1::negate($g), $g2));
        self::assertTrue(Secp256k1::isSum($g, Secp256k1::negate($g), null), 'P + (−P) = O');
        self::assertTrue(Secp256k1::isSum(null, $g, $g));
        self::assertTrue(Secp256k1::isSum($g, null, $g));
        self::assertTrue(Secp256k1::isSum(null, null, null));
        self::assertFalse(Secp256k1::isSum($g, $g, null));
        self::assertFalse(Secp256k1::isSum($g, $g2, null));
        self::assertFalse(Secp256k1::isSum($g, $g2, Secp256k1::negate($g3)), 'the sum, not its negation');
        self::assertFalse(Secp256k1::isSum($g, $g, Secp256k1::negate($g2)));
        self::assertFalse(Secp256k1::isSum($g, $g, $g3));
        self::assertFalse(Secp256k1::isSum(null, $g, $g2));
        self::assertFalse(Secp256k1::isSum($g, $g2, $g));
        self::assertTrue(Secp256k1::sumHasX($g, $g2, $g3->x));
        self::assertTrue(Secp256k1::sumHasX(Secp256k1::negate($g) ?? $g, $g3, $g2->x));
        self::assertFalse(Secp256k1::sumHasX($g, $g2, $g2->x));
    }

    /** e·P as Schnorr computes it (two ECDH and the sign of the first), against OpenSSL's (e·d)·G. */
    public function testMultiplesOfAPointAndTheirSign(): void
    {
        $multiple = new \ReflectionMethod(Schnorr::class, 'multiple');
        $n = Secp256k1::N;
        for ($i = 0; $i < 12; ++$i) {
            $secret = random_bytes(32);
            if (!Secp256k1::isSecretKey($secret)) {
                continue;
            }
            $d = BigInt::fromBytes($secret);
            $point = Secp256k1::publicPoint($secret);
            $x = $point->xBytes();
            $p = Secp256k1::liftX($x);
            self::assertNotNull($p);
            // lift_x gives the point of even y: d·G or −d·G.
            $dEven = $point->hasEvenY() ? $d : BigInt::sub($n, $d);
            $scalars = ['0', '1', '2', BigInt::sub($n, '1'), BigInt::sub($n, '2'), BigInt::mod(BigInt::fromBytes(random_bytes(32)), $n)];
            foreach ($scalars as $e) {
                $expected = BigInt::mod(BigInt::mul($e, $dEven), $n);
                $expectedPoint = $expected === '0' ? null : Secp256k1::publicPoint(BigInt::toBytes($expected, 32));
                self::assertEquals($expectedPoint, $multiple->invoke(null, $e, $p, $x), "e = {$e}");
            }
        }
    }

    public function testBigIntBytesRoundTrip(): void
    {
        foreach (['', "\0", "\1", "\xff", str_repeat("\xff", 7), str_repeat("\xff", 8), random_bytes(32), str_repeat("\0", 5) . random_bytes(27)] as $bytes) {
            $value = BigInt::fromBytes($bytes);
            self::assertSame(ltrim(bin2hex($bytes), '0') === '' ? '0' : ltrim(bin2hex($bytes), '0'), ltrim(self::dechexBig($value), '0') === '' ? '0' : ltrim(self::dechexBig($value), '0'));
            self::assertSame(str_pad(bin2hex($bytes), 64, '0', \STR_PAD_LEFT), bin2hex(BigInt::toBytes($value, 32)));
        }
        self::assertSame(Secp256k1::P, BigInt::fromBytes((string) hex2bin('fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f')));
        self::assertSame(Secp256k1::N, BigInt::fromBytes((string) hex2bin('fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141')));
    }

    public function testBigIntIgnoresTheDefaultScale(): void
    {
        $previous = bcscale(10);
        try {
            self::assertSame('2', BigInt::div('7', '3'));
            self::assertSame('3', BigInt::ceilDiv('7', '3'));
            self::assertSame('1', BigInt::mod('7', '3'));
            self::assertSame('2', BigInt::mod('-1', '3'));
            self::assertSame('21', BigInt::mul('7', '3'));
        } finally {
            bcscale($previous);
        }
    }

    public function testHkdfIsRfc5869(): void
    {
        // RFC 5869, test case 1.
        $prk = Hkdf::extract((string) hex2bin('000102030405060708090a0b0c'), (string) hex2bin(str_repeat('0b', 22)));
        self::assertSame('077709362c2e32df0ddc3f0dc47bba6390b6c73bb50f9c3122ec844ad7c2b3e5', bin2hex($prk));
        self::assertSame(
            '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
            bin2hex(Hkdf::expand($prk, (string) hex2bin('f0f1f2f3f4f5f6f7f8f9'), 42)),
        );
        for ($i = 0; $i < 5; ++$i) {
            $ikm = random_bytes(32);
            self::assertSame(hash_hkdf('sha256', $ikm, 32, 'info', 'salt'), Hkdf::derive($ikm, 'salt', 'info'));
        }
    }

    private static function g(int $k): Point
    {
        return Secp256k1::publicPoint(str_repeat("\0", 31) . \chr($k));
    }

    /** @param numeric-string $value */
    private static function dechexBig(string $value): string
    {
        $hex = '';
        while (BigInt::cmp($value, '0') > 0) {
            $hex = dechex((int) BigInt::mod($value, '16')) . $hex;
            $value = BigInt::div($value, '16');
        }

        return $hex === '' ? '0' : $hex;
    }
}
