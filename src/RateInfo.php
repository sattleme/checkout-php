<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/** The rate an order's price was converted at (SPEC.md §3). */
final class RateInfo implements \JsonSerializable
{
    /**
     * @param string   $source    coingecko, mempool, opennode, coinbase, kraken or custom
     * @param string   $price     the price of one bitcoin, plain decimal, shortest
     * @param int      $fetchedAt when the SDK read it (Unix seconds)
     * @param int|null $time      when the source says the price was set
     */
    public function __construct(
        public readonly string $source,
        public readonly string $currency,
        public readonly string $price,
        public readonly int $fetchedAt,
        public readonly ?int $time,
    ) {}

    /** @return array{source: string, currency: string, price: string, fetchedAt: int, time: int|null} */
    public function toArray(): array
    {
        return ['source' => $this->source, 'currency' => $this->currency, 'price' => $this->price, 'fetchedAt' => $this->fetchedAt, 'time' => $this->time];
    }

    /** @return array{source: string, currency: string, price: string, fetchedAt: int, time: int|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
