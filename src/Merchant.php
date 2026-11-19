<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * The merchant, as merchant() reads it (SPEC.md §3): never its Lightning address, which the app
 * never shows to the integrator.
 */
final class Merchant implements \JsonSerializable
{
    /**
     * @param numeric-string $minSats          the Lightning address's least sendable sats
     * @param numeric-string $maxSats          its most
     * @param int            $onchainAddresses the addresses of the integration's lot, leased or not
     */
    public function __construct(
        public readonly string $businessName,
        public readonly string $minSats,
        public readonly string $maxSats,
        public readonly int $onchainAddresses,
    ) {}

    /** @return array{businessName: string, minSats: string, maxSats: string, onchainAddresses: int} */
    public function toArray(): array
    {
        return ['businessName' => $this->businessName, 'minSats' => $this->minSats, 'maxSats' => $this->maxSats, 'onchainAddresses' => $this->onchainAddresses];
    }

    /** @return array{businessName: string, minSats: string, maxSats: string, onchainAddresses: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
