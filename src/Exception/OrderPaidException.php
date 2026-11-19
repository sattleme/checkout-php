<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\PaymentStatus;

/**
 * order_paid: an earlier invoice of the order was paid (SPEC.md §21). It carries the invoice, its
 * paid status, and its preimage (null when it was paid otherwise than by its own Lightning
 * invoice: on-chain, or mixed).
 */
final class OrderPaidException extends SattlemeException
{
    public function __construct(
        private readonly DeclaredInvoice $invoice,
        private readonly PaymentStatus $status,
    ) {
        parent::__construct('An earlier invoice of the order was paid', 'order_paid', false);
    }

    public function getInvoice(): DeclaredInvoice
    {
        return $this->invoice;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getPreimage(): ?string
    {
        return $this->status->method === 'lightning' ? $this->status->preimage : null;
    }
}
