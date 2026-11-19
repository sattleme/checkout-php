<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Onchain\Lease;

/**
 * An invoice the merchant's registry holds for a sale (SPEC.md §21): the DeclaredInvoice, with
 * its declaration (null for an order paid on-chain only) and its lease (null without an address),
 * published again while it may still be paid.
 *
 * @internal
 */
final class SaleInvoice
{
    public function __construct(
        public readonly DeclaredInvoice $invoice,
        public readonly ?Event $declaration,
        #[\SensitiveParameter]
        public readonly ?Lease $lease,
        public readonly ?Declaration $declared,
    ) {}

    /**
     * Its last record: its declaration, or for an invoice paid on-chain only its lease's event (its
     * mark). The one shown has it on a majority of the relays; one whose createInvoice failed
     * before that, on a minority (SPEC.md §21).
     */
    public function lastRecord(): Event
    {
        $record = $this->declaration ?? $this->lease?->event;
        \assert($record !== null);

        return $record;
    }
}
