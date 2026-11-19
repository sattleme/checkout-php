<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Fiat;

/**
 * An order's price in a currency (SPEC.md §25, "The price of an order").
 *
 * @internal
 */
final class Pricing
{
    private function __construct() {}

    /**
     * ['amount' => '24.50', 'currency' => 'CHF'] as a Fiat: the amount 1 to 15 digits, an optional
     * fraction of 1 to 15 digits, at most the currency's decimals once its trailing zeros are
     * dropped; the minor units from 1 to 2^53 − 1. Else InvalidOrderException (fiat).
     */
    public static function fiat(mixed $fiat): Fiat
    {
        if (!\is_array($fiat)) {
            throw new InvalidOrderException('fiat', "fiat: ['amount' => '24.50', 'currency' => 'CHF'] expected");
        }
        $currency = $fiat['currency'] ?? null;
        $digits = \is_string($currency) ? Currencies::digits($currency) : null;
        if ($digits === null) {
            throw new InvalidOrderException('fiat', 'fiat: the currency is an ISO 4217 code of the table, in upper case, as CHF');
        }
        $amount = $fiat['amount'] ?? null;
        if (!\is_string($amount) || preg_match('/^([0-9]{1,15})(?:\.([0-9]{1,15}))?$/D', $amount, $match) !== 1) {
            throw new InvalidOrderException('fiat', 'fiat: the amount is a string of digits, as "24.50" (never a float)');
        }
        $fraction = rtrim($match[2] ?? '', '0');
        if (\strlen($fraction) > $digits) {
            throw new InvalidOrderException('fiat', "fiat: {$currency} has {$digits} decimals at most");
        }
        $minor = ltrim($match[1] . str_pad($fraction, $digits, '0'), '0');
        $minor = $minor === '' ? '0' : $minor;
        \assert(is_numeric($minor));
        if (BigInt::cmp($minor, '1') < 0 || BigInt::cmp($minor, (string) Protocol::MAX_SAFE_INTEGER) > 0) {
            throw new InvalidOrderException('fiat', 'fiat: the amount is above 0, and not that large');
        }
        return new Fiat(self::amount($minor, $digits), $currency, $minor, $digits);
    }

    /**
     * A declared price (SPEC.md §21) as a Fiat: null for a sale in sats (currency SAT).
     *
     * @param array{minor: int, digits: int, currency: string} $declared
     */
    public static function declared(array $declared): ?Fiat
    {
        if ($declared['currency'] === Protocol::SATS_CURRENCY) {
            return null;
        }
        $minor = (string) $declared['minor'];

        return new Fiat(self::amount($minor, $declared['digits']), $declared['currency'], $minor, $declared['digits']);
    }

    /** $minor units with exactly $digits decimals: 2450 with 2 is "24.50". */
    private static function amount(string $minor, int $digits): string
    {
        if ($digits === 0) {
            return $minor;
        }
        $padded = str_pad($minor, $digits + 1, '0', \STR_PAD_LEFT);

        return substr($padded, 0, -$digits) . '.' . substr($padded, -$digits);
    }
}
