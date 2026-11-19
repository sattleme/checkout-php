<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * An invoice as the merchant's registry holds it (SPEC.md §3): findInvoices(), and the invoice of
 * InvoicePendingException and OrderPaidException. Every field of an Invoice but rate, checkoutUrl
 * and redirectUrl, plus declaredAt.
 */
final class DeclaredInvoice implements \JsonSerializable
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
        public readonly string $note,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly int $declaredAt,
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
            'note' => $this->note,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'declaredAt' => $this->declaredAt,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
