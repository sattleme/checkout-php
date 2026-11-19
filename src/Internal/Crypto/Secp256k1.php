<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Crypto;

use Sattleme\Checkout\Internal\BigInt;

/**
 * The curve secp256k1 through OpenSSL: every scalar multiplication (a public key, a nonce point,
 * an ECDH) is OpenSSL's own, its Montgomery ladder for secret scalars, written against timing
 * attacks on arithmetic OpenSSL does not claim constant-time (README, "Security"); point
 * decompression (BIP-340 lift_x) too. Nothing here multiplies a point: this class only checks
 * relations between points OpenSSL computed (Schnorr), on public values, with bcmath.
 *
 * Keys go to OpenSSL as DER structures (SEC1 private key, SubjectPublicKeyInfo) rather than through
 * openssl_pkey_new(): on a key OpenSSL refuses, openssl_pkey_new() generates a random key instead
 * of failing. Every input is checked before (a secret in [1, n − 1], x below p) and every output
 * after (the secret read back, the point on the curve).
 *
 * @internal
 */
final class Secp256k1
{
    /** The field's prime p. */
    public const P = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
    /** The group's order n. */
    public const N = '115792089237316195423570985008687907852837564279074904382605163141518161494337';

    /** SEC1 ECPrivateKey (RFC 5915) without its public key, parameters secp256k1: d follows. */
    private const PRIVATE_PREFIX = "\x30\x2e\x02\x01\x01\x04\x20";
    private const PRIVATE_SUFFIX = "\xa0\x07\x06\x05\x2b\x81\x04\x00\x0a";
    /** SubjectPublicKeyInfo of id-ecPublicKey on secp256k1, compressed point with even y: x follows. */
    private const COMPRESSED_PREFIX = "\x30\x36\x30\x10\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x05\x2b\x81\x04\x00\x0a\x03\x22\x00\x02";

    private function __construct() {}

    /** Whether $secret is 32 bytes whose integer is a secret key: from 1 to n − 1. */
    public static function isSecretKey(#[\SensitiveParameter] string $secret): bool
    {
        if (\strlen($secret) !== 32) {
            return false;
        }
        $d = BigInt::fromBytes($secret);

        return BigInt::cmp($d, '0') > 0 && BigInt::cmp($d, self::N) < 0;
    }

    /**
     * BIP-340 lift_x: the point of x-coordinate $x (32 bytes) with an even y, or null when $x is
     * not below p or no point has it.
     */
    public static function liftX(string $x): ?Point
    {
        if (\strlen($x) !== 32 || BigInt::cmp(BigInt::fromBytes($x), self::P) >= 0) {
            return null;
        }
        $key = self::openssl(static fn() => openssl_pkey_get_public(self::pem('PUBLIC KEY', self::COMPRESSED_PREFIX . $x)));
        if ($key === null) {
            return null;
        }
        [$px, $py] = self::coordinates($key);
        if ($px !== $x || (\ord($py[31]) & 1) === 1) {
            throw new \LogicException('OpenSSL decompressed another point');
        }
        $point = new Point(BigInt::fromBytes($px), BigInt::fromBytes($py));
        self::assertOnCurve($point);

        return $point;
    }

    /** Whether $x (32 bytes) is the x coordinate of a point: an x-only public key (BIP-340). */
    public static function isXOnlyPublicKey(string $x): bool
    {
        return self::liftX($x) !== null;
    }

    /** The point $secret × G. */
    public static function publicPoint(#[\SensitiveParameter] string $secret): Point
    {
        $key = self::privateKey($secret);
        [$x, $y] = self::coordinates($key);
        $point = new Point(BigInt::fromBytes($x), BigInt::fromBytes($y));
        self::assertOnCurve($point);

        return $point;
    }

    /** The x-only public key (32 bytes) of $secret (BIP-340). */
    public static function xOnlyPublicKey(#[\SensitiveParameter] string $secret): string
    {
        return self::publicPoint($secret)->xBytes();
    }

    /**
     * The x coordinate (32 bytes) of $secret × lift_x($x): the ECDH shared secret, unhashed, as
     * NIP-44 takes it.
     *
     * @throws \InvalidArgumentException when $x is no x-only public key
     */
    public static function sharedX(#[\SensitiveParameter] string $secret, string $x): string
    {
        if (self::liftX($x) === null) {
            throw new \InvalidArgumentException('Not a public key');
        }
        $private = self::privateKey($secret);
        $public = self::openssl(static fn() => openssl_pkey_get_public(self::pem('PUBLIC KEY', self::COMPRESSED_PREFIX . $x)));
        if ($public === null) {
            throw new \LogicException('OpenSSL refused a public key');
        }
        $shared = self::openssl(static fn() => openssl_pkey_derive($public, $private));
        if (!\is_string($shared) || \strlen($shared) !== 32) {
            throw new \LogicException('OpenSSL derived no shared secret');
        }

        return $shared;
    }

    /** Whether $point satisfies y² = x³ + 7 (mod p). */
    public static function isOnCurve(Point $point): bool
    {
        $left = BigInt::mod(BigInt::mul($point->y, $point->y), self::P);
        $right = BigInt::mod(BigInt::add(BigInt::mul(BigInt::mod(BigInt::mul($point->x, $point->x), self::P), $point->x), '7'), self::P);

        return BigInt::cmp($point->x, self::P) < 0
            && BigInt::cmp($point->y, self::P) < 0
            && $left === $right;
    }

    /** −$point. */
    public static function negate(?Point $point): ?Point
    {
        if ($point === null) {
            return null;
        }

        return new Point($point->x, BigInt::mod(BigInt::sub(self::P, $point->y), self::P));
    }

    /**
     * Whether $a + $b = $sum, null being the point at infinity, without computing the sum: the
     * chord (or tangent) through $a and $b is checked to meet −$sum. No division, so no inversion.
     */
    public static function isSum(?Point $a, ?Point $b, ?Point $sum): bool
    {
        if ($a === null) {
            return self::same($b, $sum);
        }
        if ($b === null) {
            return self::same($a, $sum);
        }
        if ($a->x === $b->x) {
            if ($a->y !== $b->y) {
                return $sum === null; // $b = −$a
            }
            // Doubling (y is never 0: secp256k1 has no point of order 2): slope 3x² / 2y.
            $num = self::fmul('3', self::fmul($a->x, $a->x));
            $den = self::fadd($a->y, $a->y);
        } else {
            $num = self::fsub($b->y, $a->y);
            $den = self::fsub($b->x, $a->x);
        }
        if ($sum === null) {
            return false;
        }
        // x(sum) = λ² − xa − xb and y(sum) = λ (xa − x(sum)) − ya, with λ = num / den.
        $xOk = self::fmul($num, $num) === self::fmul(self::fadd($sum->x, self::fadd($a->x, $b->x)), self::fmul($den, $den));
        $yOk = self::fmul(self::fadd($sum->y, $a->y), $den) === self::fmul($num, self::fsub($a->x, $sum->x));

        return $xOk && $yOk;
    }

    /**
     * Whether x($a + $b) is $x, for $a ≠ ±$b: as isSum, on the x coordinate alone.
     *
     * @param numeric-string $x
     */
    public static function sumHasX(Point $a, Point $b, string $x): bool
    {
        if ($a->x === $b->x) {
            throw new \LogicException('Points with one x coordinate');
        }
        $num = self::fsub($b->y, $a->y);
        $den = self::fsub($b->x, $a->x);

        return self::fmul($num, $num) === self::fmul(self::fadd($x, self::fadd($a->x, $b->x)), self::fmul($den, $den));
    }

    private static function same(?Point $a, ?Point $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->x === $b->x && $a->y === $b->y;
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    private static function fmul(string $a, string $b): string
    {
        return BigInt::mod(BigInt::mul($a, $b), self::P);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    private static function fadd(string $a, string $b): string
    {
        return BigInt::mod(BigInt::add($a, $b), self::P);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    private static function fsub(string $a, string $b): string
    {
        return BigInt::mod(BigInt::sub($a, $b), self::P);
    }

    private static function assertOnCurve(Point $point): void
    {
        if (!self::isOnCurve($point)) {
            throw new \LogicException('OpenSSL gave a point off the curve');
        }
    }

    private static function privateKey(#[\SensitiveParameter] string $secret): \OpenSSLAsymmetricKey
    {
        if (!self::isSecretKey($secret)) {
            throw new \InvalidArgumentException('Not a secret key');
        }
        $key = self::openssl(static fn() => openssl_pkey_get_private(self::pem('EC PRIVATE KEY', self::PRIVATE_PREFIX . $secret . self::PRIVATE_SUFFIX)));
        if ($key === null) {
            throw new \LogicException('OpenSSL refused a secret key');
        }
        $details = openssl_pkey_get_details($key);
        $d = \is_array($details) && \is_array($details['ec'] ?? null) && \is_string($details['ec']['d'] ?? null) ? $details['ec']['d'] : '';
        if (!hash_equals($secret, str_pad($d, 32, "\0", \STR_PAD_LEFT))) {
            throw new \LogicException('OpenSSL read another secret key');
        }

        return $key;
    }

    /**
     * The coordinates (32 bytes each) of an EC key's public point.
     *
     * @return array{0: string, 1: string}
     */
    private static function coordinates(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (!\is_array($ec) || ($ec['curve_name'] ?? null) !== 'secp256k1' || !\is_string($ec['x'] ?? null) || !\is_string($ec['y'] ?? null)
            || \strlen($ec['x']) > 32 || \strlen($ec['y']) > 32) {
            throw new \LogicException('OpenSSL gave no secp256k1 point');
        }

        return [str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT), str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT)];
    }

    private static function pem(string $label, #[\SensitiveParameter] string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }

    /**
     * Runs an OpenSSL call with its error queue emptied before and after, so that no error of ours
     * reaches another caller of OpenSSL in this process, nor one of theirs us.
     *
     * @template T
     *
     * @param \Closure(): (T|false) $call
     *
     * @return T|null null when the call returned false
     */
    private static function openssl(\Closure $call): mixed
    {
        self::clearErrors();
        try {
            $result = $call();
        } finally {
            self::clearErrors();
        }

        return $result === false ? null : $result;
    }

    private static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
