<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * The status of an invoice's payment (SPEC.md §3):
 *
 * - open: not paid yet; it may still be, until expiresAt;
 * - unconfirmed: enough came on-chain in time, not 3 confirmations deep yet: not paid yet;
 * - paid, by method lightning (the preimage is the proof), onchain (the txids) or mixed;
 * - expired: never paid, for good.
 *
 * `received` is what the order received in time, in sats ("0" without an address). Below the
 * amount in unconfirmed and paid (onchain or mixed) when the merchant's tolerance of on-chain
 * underpayments, or the merchant's acceptance of what came (in its app), accepted it: the order is
 * paid all the same.
 */
final class PaymentStatus implements \JsonSerializable
{
    public const OPEN = 'open';
    public const UNCONFIRMED = 'unconfirmed';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';

    /**
     * @param numeric-string|null $received
     * @param list<string>|null   $txids
     * @param list<string>|null   $preimages
     */
    private function __construct(
        public readonly string $state,
        public readonly ?string $method = null,
        public readonly ?int $expiresAt = null,
        public readonly ?string $received = null,
        public readonly ?Rest $rest = null,
        public readonly ?int $confirmations = null,
        public readonly ?array $txids = null,
        public readonly ?string $preimage = null,
        public readonly ?array $preimages = null,
    ) {}

    /** @param numeric-string $received */
    public static function open(int $expiresAt, string $received, ?Rest $rest): self
    {
        return new self(self::OPEN, expiresAt: $expiresAt, received: $received, rest: $rest);
    }

    /**
     * @param numeric-string $received
     * @param list<string>   $txids
     */
    public static function unconfirmed(string $received, int $confirmations, array $txids): self
    {
        return new self(self::UNCONFIRMED, received: $received, confirmations: $confirmations, txids: $txids);
    }

    public static function paidByLightning(string $preimage): self
    {
        return new self(self::PAID, method: 'lightning', preimage: $preimage);
    }

    /**
     * @param numeric-string $received
     * @param list<string>   $txids
     */
    public static function paidOnchain(string $received, array $txids): self
    {
        return new self(self::PAID, method: 'onchain', received: $received, txids: $txids);
    }

    /**
     * @param numeric-string $received
     * @param list<string>   $txids
     * @param list<string>   $preimages
     */
    public static function paidMixed(string $received, array $txids, array $preimages): self
    {
        return new self(self::PAID, method: 'mixed', received: $received, txids: $txids, preimages: $preimages);
    }

    /** @param numeric-string $received */
    public static function expired(string $received): self
    {
        return new self(self::EXPIRED, received: $received);
    }

    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }

    /** Whether it is paid or expired: it will never change. */
    public function isFinal(): bool
    {
        return $this->state === self::PAID || $this->state === self::EXPIRED;
    }

    /**
     * The status's JSON (SPEC.md §3), with its fields in this order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match (true) {
            $this->state === self::OPEN => ['state' => $this->state, 'expiresAt' => $this->expiresAt, 'received' => $this->received, 'rest' => $this->rest?->toArray()],
            $this->state === self::UNCONFIRMED => ['state' => $this->state, 'received' => $this->received, 'confirmations' => $this->confirmations, 'txids' => $this->txids],
            $this->state === self::EXPIRED => ['state' => $this->state, 'received' => $this->received],
            $this->method === 'lightning' => ['state' => $this->state, 'method' => $this->method, 'preimage' => $this->preimage],
            $this->method === 'onchain' => ['state' => $this->state, 'method' => $this->method, 'received' => $this->received, 'txids' => $this->txids],
            default => ['state' => $this->state, 'method' => $this->method, 'received' => $this->received, 'txids' => $this->txids, 'preimages' => $this->preimages],
        };
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
