<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Crypto;

use Sattleme\Checkout\Internal\BigInt;

/**
 * BIP-340 Schnorr signatures (https://github.com/bitcoin/bips/blob/master/bip-0340.mediawiki), on
 * the scalar multiplications of OpenSSL (Secp256k1).
 *
 * Signing multiplies the secret and the nonce by G in OpenSSL (its ladder for secret scalars); the
 * rest is arithmetic modulo n on bcmath, whose time varies with its operands: signing is not
 * constant-time (README, "Security").
 * Verifying computes s·G and e·P in OpenSSL, then checks that lift_x(r) + e·P = s·G, which is
 * BIP-340's R = s·G − e·P with an even y and x(R) = r. OpenSSL gives only the x coordinate of
 * e·P (ECDH): its sign is told by x((e + 1)·P), from a second ECDH.
 *
 * Checked against every official BIP-340 vector, and against libsecp256k1 on random keys and
 * messages (tests/).
 *
 * @internal
 */
final class Schnorr
{
    private function __construct() {}

    /** BIP-340's tagged hash of $data. */
    public static function taggedHash(string $tag, string $data): string
    {
        $tagHash = hash('sha256', $tag, true);

        return hash('sha256', $tagHash . $tagHash . $data, true);
    }

    /**
     * The signature (64 bytes) of $message by $secret, with 32 bytes of auxiliary random data.
     *
     * @throws \InvalidArgumentException for a secret out of range or auxiliary data of another size
     */
    public static function sign(#[\SensitiveParameter] string $secret, string $message, #[\SensitiveParameter] string $auxiliary): string
    {
        if (\strlen($auxiliary) !== 32) {
            throw new \InvalidArgumentException('Auxiliary data of 32 bytes expected');
        }
        $point = Secp256k1::publicPoint($secret);
        $publicKey = $point->xBytes();
        $d = BigInt::fromBytes($secret);
        if (!$point->hasEvenY()) {
            $d = BigInt::sub(Secp256k1::N, $d);
        }
        $t = BigInt::toBytes($d, 32) ^ self::taggedHash('BIP0340/aux', $auxiliary);
        $k = BigInt::mod(BigInt::fromBytes(self::taggedHash('BIP0340/nonce', $t . $publicKey . $message)), Secp256k1::N);
        if ($k === '0') {
            throw new \LogicException('Nonce of zero'); // Negligible probability.
        }
        $nonce = Secp256k1::publicPoint(BigInt::toBytes($k, 32));
        if (!$nonce->hasEvenY()) {
            $k = BigInt::sub(Secp256k1::N, $k);
        }
        $r = $nonce->xBytes();
        $e = self::challenge($r, $publicKey, $message);
        $signature = $r . BigInt::toBytes(BigInt::mod(BigInt::add($k, BigInt::mul($e, $d)), Secp256k1::N), 32);
        // BIP-340 recommends it: a fault while signing never yields a bad signature.
        if (!self::verify($publicKey, $message, $signature)) {
            throw new \LogicException('A signature failed its own verification');
        }

        return $signature;
    }

    /** Whether $signature (64 bytes) is a valid signature of $message by the x-only $publicKey. */
    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        if (\strlen($publicKey) !== 32 || \strlen($signature) !== 64) {
            return false;
        }
        $p = Secp256k1::liftX($publicKey);
        if ($p === null) {
            return false;
        }
        $rBytes = substr($signature, 0, 32);
        $s = BigInt::fromBytes(substr($signature, 32));
        if (BigInt::cmp(BigInt::fromBytes($rBytes), Secp256k1::P) >= 0 || BigInt::cmp($s, Secp256k1::N) >= 0) {
            return false;
        }
        $e = self::challenge($rBytes, $publicKey, $message);
        $eP = self::multiple($e, $p, $publicKey);
        $sG = $s === '0' ? null : Secp256k1::publicPoint(BigInt::toBytes($s, 32));
        // R = s·G − e·P must have an even y and x(R) = r: it can only be lift_x(r).
        $r = Secp256k1::liftX($rBytes);

        return $r !== null && Secp256k1::isSum($r, $eP, $sG);
    }

    /** @return numeric-string */
    private static function challenge(string $r, string $publicKey, string $message): string
    {
        return BigInt::mod(BigInt::fromBytes(self::taggedHash('BIP0340/challenge', $r . $publicKey . $message)), Secp256k1::N);
    }

    /**
     * $e·$p (null: the point at infinity), $p being lift_x($x), with 0 ≤ $e < n.
     *
     * @param numeric-string $e
     */
    private static function multiple(string $e, Point $p, string $x): ?Point
    {
        if ($e === '0') {
            return null;
        }
        if ($e === '1') {
            return $p;
        }
        $last = BigInt::sub(Secp256k1::N, '1');
        if ($e === $last) {
            return Secp256k1::negate($p);
        }
        // Here 2 ≤ e ≤ n − 2: e·P ≠ ±P, (e + 1)·P is no point at infinity.
        $candidate = Secp256k1::liftX(Secp256k1::sharedX(BigInt::toBytes($e, 32), $x));
        if ($candidate === null) {
            throw new \LogicException('An ECDH gave no x coordinate of a point');
        }
        $next = BigInt::fromBytes(Secp256k1::sharedX(BigInt::toBytes(BigInt::add($e, '1'), 32), $x));
        if (Secp256k1::sumHasX($candidate, $p, $next)) {
            return $candidate;
        }
        $negated = Secp256k1::negate($candidate);
        \assert($negated !== null);
        if (Secp256k1::sumHasX($negated, $p, $next)) {
            return $negated;
        }
        throw new \LogicException('The sign of e·P could not be told');
    }
}
