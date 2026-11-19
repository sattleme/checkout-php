<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

/**
 * The on-chain part of an invoice (SPEC.md §31, "The on-chain payment", step 4): nothing due (its
 * rest invoices pay the order), underpaid (final or not), unconfirmed, or paid; with what counts
 * (`received`) and the order's transactions.
 *
 * @internal
 */
final class OnchainResult
{
    public const NOTHING_DUE = 'nothing_due';
    public const UNDERPAID = 'underpaid';
    public const UNCONFIRMED = 'unconfirmed';
    public const PAID = 'paid';

    /**
     * @param self::NOTHING_DUE|self::UNDERPAID|self::UNCONFIRMED|self::PAID $state
     * @param numeric-string $received
     * @param list<string>   $txids
     */
    public function __construct(
        public readonly string $state,
        public readonly string $received,
        public readonly bool $final = false,
        public readonly int $confirmations = 0,
        public readonly array $txids = [],
    ) {}
}
