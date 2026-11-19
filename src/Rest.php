<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * What is left to pay of an on-chain underpayment, and how (SPEC.md §3, §31): `sats` all that is
 * left; `bolt11` a Lightning invoice of exactly that, declared for the order (null while there is
 * none); `paymentUri` what to show as one QR code.
 */
final class Rest implements \JsonSerializable
{
    /** @param numeric-string $sats */
    public function __construct(
        public readonly string $sats,
        public readonly ?string $bolt11,
        public readonly ?string $paymentUri,
    ) {}

    /** @return array{sats: string, bolt11: string|null, paymentUri: string|null} */
    public function toArray(): array
    {
        return ['sats' => $this->sats, 'bolt11' => $this->bolt11, 'paymentUri' => $this->paymentUri];
    }

    /** @return array{sats: string, bolt11: string|null, paymentUri: string|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
