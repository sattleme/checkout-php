<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/** An order's price in a currency (SPEC.md §3): `amount` has exactly `digits` decimals. */
final class Fiat implements \JsonSerializable
{
    /**
     * @param string         $amount   "24.50"
     * @param string         $currency ISO 4217, upper case
     * @param numeric-string $minor    the amount in minor units, "2450"
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $minor,
        public readonly int $digits,
    ) {}

    /** @return array{amount: string, currency: string, minor: string, digits: int} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'minor' => $this->minor, 'digits' => $this->digits];
    }

    /** @return array{amount: string, currency: string, minor: string, digits: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
