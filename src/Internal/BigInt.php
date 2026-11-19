<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * Arbitrary-precision integers on bcmath, as decimal strings of non-negative integers unless said
 * otherwise. Every call passes its scale (0): a host or another plugin may change bcmath's default
 * scale with bcscale().
 *
 * @internal
 */
final class BigInt
{
    /** 2^56: seven bytes, which a PHP integer holds. */
    private const CHUNK = '72057594037927936';

    private function __construct() {}

    /**
     * The integer of big-endian $bytes.
     *
     * @return numeric-string
     */
    public static function fromBytes(#[\SensitiveParameter] string $bytes): string
    {
        $value = '0';
        $hex = bin2hex($bytes);
        $first = \strlen($hex) % 14;
        $chunks = [];
        if ($first > 0) {
            $chunks[] = substr($hex, 0, $first);
        }
        if (\strlen($hex) > $first) {
            foreach (str_split(substr($hex, $first), 14) as $chunk) {
                $chunks[] = $chunk;
            }
        }
        foreach ($chunks as $chunk) {
            $value = bcadd(bcmul($value, bcpow('16', (string) \strlen($chunk), 0), 0), (string) hexdec($chunk), 0);
        }

        return $value;
    }

    /**
     * $value as $length big-endian bytes; it must fit.
     *
     * @param numeric-string $value
     */
    public static function toBytes(#[\SensitiveParameter] string $value, int $length): string
    {
        $hex = '';
        while (bccomp($value, '0', 0) > 0) {
            $quotient = bcdiv($value, self::CHUNK, 0);
            $remainder = bcsub($value, bcmul($quotient, self::CHUNK, 0), 0);
            $hex = str_pad(dechex((int) $remainder), 14, '0', \STR_PAD_LEFT) . $hex;
            $value = $quotient;
        }
        $hex = ltrim($hex, '0');
        if (\strlen($hex) > 2 * $length) {
            throw new \LogicException('Integer too large for its length');
        }

        return (string) hex2bin(str_pad($hex, 2 * $length, '0', \STR_PAD_LEFT));
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function add(#[\SensitiveParameter] string $a, #[\SensitiveParameter] string $b): string
    {
        return bcadd($a, $b, 0);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function sub(#[\SensitiveParameter] string $a, #[\SensitiveParameter] string $b): string
    {
        return bcsub($a, $b, 0);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function mul(#[\SensitiveParameter] string $a, #[\SensitiveParameter] string $b): string
    {
        return bcmul($a, $b, 0);
    }

    /**
     * $a modulo $m, in [0, m): bcmod keeps the sign of $a.
     *
     * @param numeric-string $a
     * @param numeric-string $m
     *
     * @return numeric-string
     */
    public static function mod(#[\SensitiveParameter] string $a, string $m): string
    {
        $r = bcmod($a, $m, 0);
        if ($r !== '0' && $r[0] === '-') {
            $r = bcadd($r, $m, 0);
        }

        return $r;
    }

    /**
     * The quotient of $a by $b, rounded down ($a, $b > 0).
     *
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function div(string $a, string $b): string
    {
        return bcdiv($a, $b, 0);
    }

    /**
     * The quotient of $a by $b, rounded up ($a ≥ 0, $b > 0).
     *
     * @param numeric-string $a
     * @param numeric-string $b
     *
     * @return numeric-string
     */
    public static function ceilDiv(string $a, string $b): string
    {
        $q = bcdiv($a, $b, 0);

        return bccomp(bcmul($q, $b, 0), $a, 0) === 0 ? $q : bcadd($q, '1', 0);
    }

    /** @return numeric-string */
    public static function pow10(int $exponent): string
    {
        return bcpow('10', (string) $exponent, 0);
    }

    /**
     * @param numeric-string $a
     * @param numeric-string $b
     */
    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }

    /**
     * Whether $value (non-negative) is odd.
     *
     * @param numeric-string $value
     */
    public static function isOdd(string $value): bool
    {
        return ((int) $value[\strlen($value) - 1]) % 2 === 1;
    }
}
