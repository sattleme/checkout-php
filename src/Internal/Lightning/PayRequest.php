<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Lightning;

use Sattleme\Checkout\Internal\BigInt;

/**
 * The LNURL-pay parameters of the merchant's Lightning address (SPEC.md §16).
 *
 * @internal
 */
final class PayRequest
{
    /**
     * @param array{host: string, port: int, target: string} $callbackParts
     */
    public function __construct(
        public readonly string $callback,
        public readonly array $callbackParts,
        public readonly int $minSendable,
        public readonly int $maxSendable,
        public readonly string $metadata,
        public readonly int $commentAllowed,
    ) {}

    /** The description hash of the address's invoices: SHA-256 of the metadata. */
    public function descriptionHash(): string
    {
        return hash('sha256', $this->metadata);
    }

    /**
     * Sendable sats: ceil(minSendable / 1000) to floor(maxSendable / 1000).
     *
     * @return array{0: numeric-string, 1: numeric-string}
     */
    public function sendable(): array
    {
        return [BigInt::ceilDiv((string) $this->minSendable, '1000'), BigInt::div((string) $this->maxSendable, '1000')];
    }

    /** @param numeric-string $sats */
    public function canSend(string $sats): bool
    {
        [$min, $max] = $this->sendable();

        return BigInt::cmp($sats, $min) >= 0 && BigInt::cmp($sats, $max) <= 0;
    }
}
