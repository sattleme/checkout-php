<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * Bech32 (BIP-173) and bech32m (BIP-350), after their reference implementations, without BIP-173's
 * 90-character limit (BOLT11 invoices are longer; SPEC.md §19): either case, never mixed.
 *
 * @internal
 */
final class Bech32
{
    /** The checksum's constant: BIP-173's bech32, BIP-350's bech32m. */
    public const BECH32 = 1;
    public const BECH32M = 0x2bc830a3;

    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    private function __construct() {}

    /**
     * $text's human-readable part (lowercase) and 5-bit words (checksum removed), or null when it
     * is not valid with $constant's checksum.
     *
     * @return array{0: string, 1: list<int>}|null
     */
    public static function decode(string $text, int $constant): ?array
    {
        $lower = Text::asciiLower($text);
        if ($text !== $lower && $text !== strtr($text, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')) {
            return null;
        }
        $separator = strrpos($lower, '1');
        if ($separator === false || $separator < 1 || $separator + 7 > \strlen($lower)) {
            return null;
        }
        $hrp = substr($lower, 0, $separator);
        if (preg_match('/^[\x21-\x7E]+$/D', $hrp) !== 1) {
            return null;
        }
        $values = [];
        $data = substr($lower, $separator + 1);
        for ($i = 0, $n = \strlen($data); $i < $n; ++$i) {
            $value = strpos(self::CHARSET, $data[$i]);
            if ($value === false) {
                return null;
            }
            $values[] = $value;
        }
        if (self::polymod([...self::expandHrp($hrp), ...$values]) !== $constant) {
            return null;
        }

        return [$hrp, \array_slice($values, 0, -6)];
    }

    /**
     * Regroups 5-bit words into bytes. Lenient (BOLT11's fields), the trailing padding bits are
     * dropped; strict (BIP-173 addresses), they must be fewer than 5 and zero, else null.
     *
     * @param list<int> $words
     */
    public static function toBytes(array $words, bool $strict): ?string
    {
        $bytes = '';
        $accumulator = 0;
        $bits = 0;
        foreach ($words as $word) {
            $accumulator = (($accumulator << 5) | $word) & 0xFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= \chr(($accumulator >> $bits) & 0xFF);
            }
        }
        if ($strict && ($bits >= 5 || (($accumulator << (8 - $bits)) & 0xFF) !== 0)) {
            return null;
        }

        return $bytes;
    }

    /**
     * The big-endian integer of 5-bit words (35 bits at most here).
     *
     * @param list<int> $words
     */
    public static function toInteger(array $words): int
    {
        $value = 0;
        foreach ($words as $word) {
            $value = $value * 32 + $word;
        }

        return $value;
    }

    /** @param list<int> $values */
    private static function polymod(array $values): int
    {
        $chk = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $value;
            for ($i = 0; $i < 5; ++$i) {
                if ((($top >> $i) & 1) === 1) {
                    $chk ^= self::GENERATOR[$i];
                }
            }
        }

        return $chk;
    }

    /** @return list<int> */
    private static function expandHrp(string $hrp): array
    {
        $high = [];
        $low = [];
        for ($i = 0, $n = \strlen($hrp); $i < $n; ++$i) {
            $code = \ord($hrp[$i]);
            $high[] = $code >> 5;
            $low[] = $code & 31;
        }

        return [...$high, 0, ...$low];
    }
}
