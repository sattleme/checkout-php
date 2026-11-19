<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * An exact positive decimal, units × 10^-scale: the price of one bitcoin (SPEC.md §25), never a
 * float in arithmetic.
 *
 * @internal
 */
final class Decimal
{
    /**
     * @param numeric-string $units without trailing zeros when scale > 0
     */
    private function __construct(
        public readonly string $units,
        public readonly int $scale,
    ) {}

    /**
     * A price written as a string (SPEC.md §25): digits, an optional fraction and exponent, 64
     * characters and an exponent of 64 either way at most, strictly positive; else null.
     */
    public static function fromText(mixed $text): ?self
    {
        if (!\is_string($text) || \strlen($text) > Protocol::MAX_PRICE_TEXT
            || preg_match('/^([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $text, $match) !== 1) {
            return null;
        }
        $fraction = $match[2] ?? '';
        $exponentText = ltrim($match[3] ?? '0', '+');
        $negative = str_starts_with($exponentText, '-');
        $exponentDigits = ltrim($negative ? substr($exponentText, 1) : $exponentText, '0');
        if (\strlen($exponentDigits) > 3) {
            return null;
        }
        $exponent = (int) $exponentDigits * ($negative ? -1 : 1);
        if (abs($exponent) > Protocol::MAX_PRICE_EXPONENT) {
            return null;
        }
        $digits = ltrim($match[1] . $fraction, '0');
        if ($digits === '') {
            return null; // Zero.
        }
        \assert(is_numeric($digits));
        $scale = \strlen($fraction) - $exponent;
        if ($scale < 0) {
            $digits = $digits . str_repeat('0', -$scale);
            $scale = 0;
        }
        while ($scale > 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            --$scale;
        }
        \assert(is_numeric($digits));

        return new self($digits, $scale);
    }

    /**
     * A price written as a JSON number (SPEC.md §25): read as an IEEE 754 double, then as the
     * shortest decimal that reads back as that double (ECMAScript's Number::toString), then as a
     * string; else null.
     */
    public static function fromNumber(int|float $number): ?self
    {
        $double = (float) $number;
        if (!is_finite($double) || $double <= 0) {
            return null;
        }
        [$digits, $exponent] = self::shortest($double);
        // As Number::toString writes it: plain from 10^-7 to 10^21, else with an exponent, which
        // the string's rule bounds to 64 either way.
        if (abs($exponent) > Protocol::MAX_PRICE_EXPONENT) {
            return null;
        }

        // As d.ddd e±x: the exponent of the first digit, as Number::toString writes it.
        return self::fromText($digits[0] . (\strlen($digits) > 1 ? '.' . substr($digits, 1) : '') . 'e' . $exponent);
    }

    /**
     * The shortest decimal digits that read back as the positive $double, and the exponent of the
     * first one (d.ddd × 10^exponent), as ECMAScript's Number::toString gives them: the fewest
     * digits, then the closest to $double. For each precision, the correctly rounded writing; and,
     * where it does not read back, its neighbour on the grid of that precision, which may: beside
     * a power of two, the doubles' spacing halves below it, and the interval that reads back as
     * $double is lopsided (2^-24 is 5.960464477539063e-8, not 5.9604644775390625e-8).
     *
     * @return array{0: string, 1: int}
     */
    public static function shortest(float $double): array
    {
        for ($precision = 1; $precision <= 17; ++$precision) {
            [$mantissa, $exponent] = explode('e', sprintf('%.' . ($precision - 1) . 'e', $double));
            $digits = str_replace('.', '', $mantissa);
            \assert(is_numeric($digits));
            $scale = (int) $exponent - $precision + 1; // $double ≈ digits × 10^scale
            foreach ([$digits, BigInt::add($digits, '1'), BigInt::sub($digits, '1')] as $candidate) {
                if ($candidate !== '0' && (float) ($candidate . 'e' . $scale) === $double) {
                    $significant = ltrim($candidate, '0');

                    return [rtrim($significant, '0'), $scale + \strlen($significant) - 1];
                }
            }
        }
        throw new \LogicException('No decimal of 17 digits reads back as a double'); // 17 always do.
    }

    /** Written plainly (SPEC.md §25): no exponent, no trailing zero, no fraction when whole. */
    public function plain(): string
    {
        if ($this->scale === 0) {
            return $this->units;
        }
        $digits = str_pad($this->units, $this->scale + 1, '0', \STR_PAD_LEFT);

        return substr($digits, 0, -$this->scale) . '.' . substr($digits, -$this->scale);
    }

    /**
     * Sats of $minor units of a currency with $digits decimals at this price of one bitcoin,
     * rounded up (SPEC.md §25): ceil(minor × 10^8 × 10^scale / (units × 10^digits)).
     *
     * @param numeric-string $minor
     *
     * @return numeric-string
     */
    public function sats(string $minor, int $digits): string
    {
        return BigInt::ceilDiv(
            BigInt::mul(BigInt::mul($minor, '100000000'), BigInt::pow10($this->scale)),
            BigInt::mul($this->units, BigInt::pow10($digits)),
        );
    }
}
