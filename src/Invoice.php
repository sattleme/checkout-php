<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * What createInvoice returns (SPEC.md §3), for the integrator to store with the order: toArray()
 * (or json_encode()) gives the JSON every implementation reads; getStatus() takes it back.
 *
 * The shop's page needs paymentUri (or bolt11 and onchainAddress) and expiresAt only; with a
 * checkout link, the shop sends its customer to checkoutUrl instead.
 */
final class Invoice implements \JsonSerializable
{
    /**
     * @param numeric-string $sats
     */
    public function __construct(
        public readonly string $ref,
        public readonly ?string $bolt11,
        public readonly ?string $paymentHash,
        public readonly ?string $verifyUrl,
        public readonly ?string $onchainAddress,
        public readonly string $paymentUri,
        public readonly string $sats,
        public readonly ?Fiat $fiat,
        public readonly ?RateInfo $rate,
        public readonly string $note,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly ?string $checkoutUrl,
        public readonly ?string $redirectUrl,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'bolt11' => $this->bolt11,
            'paymentHash' => $this->paymentHash,
            'verifyUrl' => $this->verifyUrl,
            'onchainAddress' => $this->onchainAddress,
            'paymentUri' => $this->paymentUri,
            'sats' => $this->sats,
            'fiat' => $this->fiat?->toArray(),
            'rate' => $this->rate?->toArray(),
            'note' => $this->note,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'checkoutUrl' => $this->checkoutUrl,
            'redirectUrl' => $this->redirectUrl,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
