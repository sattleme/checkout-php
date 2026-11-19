<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * A custom rate source (SPEC.md §26): any source the SDK does not name (CoinGecko Pro, a
 * self-hosted mempool, several sources checked against each other…). A Closure of the same
 * signature is one too.
 *
 * It gives the price of one bitcoin in $currency (ISO 4217, upper case): an exact decimal string
 * ("69012.5"), or ['price' => '69012.5', 'time' => <Unix seconds, or null>]; never a number, which
 * may already have lost digits. It throws UnsupportedCurrencyException when it has no price in
 * that currency. An exception of the SDK it throws goes through as it is; any other becomes a
 * RateException (its previous exception).
 */
interface RateSource
{
    /**
     * @return string|array{price: string, time?: int|null}
     */
    public function price(string $currency, Cancellation $cancellation): string|array;
}
