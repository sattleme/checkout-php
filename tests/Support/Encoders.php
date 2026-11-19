<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support;

/**
 * What the tests' fakes write: bech32/bech32m (BIP-173, BIP-350), BOLT11 invoices as a Lightning
 * service writes them (signed with zeros: the SDK reads no signature, SPEC.md §19), taproot
 * deposit addresses.
 */
final class Encoders
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32 = 1;
    private const BECH32M = 0x2BC830A3;

    /**
     * A BOLT11 invoice, of mainnet (bc) unless $network says otherwise; $extra, fields of tags the
     * SDK does not read ([tag, words], up to 1023 words each), after the others: an invoice as long
     * as a test needs.
     *
     * @param list<array{0: int, 1: list<int>}> $extra
     */
    public static function bolt11(int $sats, int $timestamp, int $expiry, string $paymentHashHex, string $descriptionHashHex, string $network = 'bc', array $extra = []): string
    {
        $words = self::integerWords($timestamp, 7);
        $words = [...$words, ...self::field(1, self::bytesToWords((string) hex2bin($paymentHashHex)))];
        $words = [...$words, ...self::field(23, self::bytesToWords((string) hex2bin($descriptionHashHex)))];
        $words = [...$words, ...self::field(6, self::integerWords($expiry))];
        foreach ($extra as [$tag, $data]) {
            \assert(\count($data) <= 1023);
            $words = [...$words, ...self::field($tag, $data)];
        }
        $words = [...$words, ...array_fill(0, 104, 0)];

        return self::encode('ln' . $network . self::amount($sats), $words, self::BECH32);
    }

    /**
     * A BOLT11 invoice of $length characters, as bolt11() writes it with fields of tag 31 (which
     * the SDK does not read) of zeros.
     */
    public static function sizedBolt11(int $length, int $sats, int $timestamp, int $expiry, string $paymentHashHex, string $descriptionHashHex): string
    {
        // Each field adds its 3 words of tag and length, and its data.
        $fields = [];
        $left = $length - \strlen(self::bolt11($sats, $timestamp, $expiry, $paymentHashHex, $descriptionHashHex));
        for (; $left > 1026; $left -= 1026) {
            $fields[] = [31, array_fill(0, 1023, 0)];
        }
        \assert($left >= 3);
        $invoice = self::bolt11($sats, $timestamp, $expiry, $paymentHashHex, $descriptionHashHex, 'bc', [...$fields, [31, array_fill(0, $left - 3, 0)]]);
        \assert(\strlen($invoice) === $length);

        return $invoice;
    }

    /** A taproot address of mainnet (bc1p…) of a 32-byte program. */
    public static function taproot(string $program): string
    {
        \assert(\strlen($program) === 32);

        return self::encode('bc', [1, ...self::bytesToWords($program)], self::BECH32M);
    }

    /**
     * @param list<int> $words
     */
    public static function encode(string $hrp, array $words, int $constant): string
    {
        $values = [...self::expandHrp($hrp), ...$words, 0, 0, 0, 0, 0, 0];
        $polymod = self::polymod($values) ^ $constant;
        $text = $hrp . '1';
        foreach ($words as $word) {
            $text .= self::CHARSET[$word];
        }
        for ($i = 0; $i < 6; ++$i) {
            $text .= self::CHARSET[($polymod >> (5 * (5 - $i))) & 31];
        }

        return $text;
    }

    /** The amount part of an invoice's prefix, in the smallest multiplier that writes it whole. */
    private static function amount(int $sats): string
    {
        $msat = $sats * 1000;
        foreach (['m' => 100000000, 'u' => 100000, 'n' => 100] as $unit => $msatPerUnit) {
            if ($msat % $msatPerUnit === 0) {
                return intdiv($msat, $msatPerUnit) . $unit;
            }
        }

        return ($msat * 10) . 'p';
    }

    /**
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function field(int $tag, array $data): array
    {
        return [$tag, intdiv(\count($data), 32), \count($data) % 32, ...$data];
    }

    /** @return list<int> */
    private static function integerWords(int $value, ?int $length = null): array
    {
        $words = [];
        do {
            array_unshift($words, $value & 31);
            $value >>= 5;
        } while ($value > 0);
        while ($length !== null && \count($words) < $length) {
            array_unshift($words, 0);
        }

        return $words;
    }

    /** @return list<int> */
    private static function bytesToWords(string $bytes): array
    {
        $words = [];
        $accumulator = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            $accumulator = ($accumulator << 8) | \ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $words[] = ($accumulator >> $bits) & 31;
            }
        }
        if ($bits > 0) {
            $words[] = ($accumulator << (5 - $bits)) & 31;
        }

        return $words;
    }

    /** @return list<int> */
    private static function expandHrp(string $hrp): array
    {
        $high = [];
        $low = [];
        foreach (str_split($hrp) as $char) {
            $high[] = \ord($char) >> 5;
            $low[] = \ord($char) & 31;
        }

        return [...$high, 0, ...$low];
    }

    /** @param list<int> $values */
    private static function polymod(array $values): int
    {
        $generator = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];
        $checksum = 1;
        foreach ($values as $value) {
            $top = $checksum >> 25;
            $checksum = (($checksum & 0x1FFFFFF) << 5) ^ $value;
            for ($i = 0; $i < 5; ++$i) {
                if ((($top >> $i) & 1) === 1) {
                    $checksum ^= $generator[$i];
                }
            }
        }

        return $checksum;
    }
}
