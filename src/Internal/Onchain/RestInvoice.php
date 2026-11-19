<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Nostr\Event;

/**
 * An invoice of the rest of an on-chain underpayment (SPEC.md §31, "The rest of an
 * underpayment"), with its Lightning part once checked: ['open'], ['expired'], ['paid', preimage],
 * or the error of that check.
 *
 * @internal
 */
final class RestInvoice
{
    /** @var array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|null */
    public ?array $state = null;

    public ?SattlemeException $error = null;

    /** Whether its rest record is known on a majority of the relays: else never shown. */
    public bool $recordOnMajority = true;

    /** Its declaration, as the relays sent it. */
    public ?Event $declaration = null;

    /** Whether a majority of the relays sent its declaration: else it is published again first. */
    public bool $declaredOnMajority = true;

    /**
     * @param numeric-string $sats
     */
    public function __construct(
        public readonly string $bolt11,
        public readonly string $paymentHash,
        public readonly string $verifyUrl,
        public readonly string $sats,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {}

    public function isPaid(): bool
    {
        return $this->state !== null && $this->state[0] === 'paid';
    }

    /** Paid, or expired: it will not change. */
    public function isFinal(): bool
    {
        return $this->state !== null && ($this->state[0] === 'paid' || $this->state[0] === 'expired');
    }

    /**
     * Sorted by their invoice's timestamp, then payment hash.
     *
     * @param list<self> $invoices
     *
     * @return list<self>
     */
    public static function sorted(array $invoices): array
    {
        usort($invoices, static fn(self $a, self $b): int => $a->createdAt !== $b->createdAt ? $a->createdAt <=> $b->createdAt : strcmp($a->paymentHash, $b->paymentHash));

        return $invoices;
    }
}
