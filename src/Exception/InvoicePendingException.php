<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

use Sattleme\Checkout\DeclaredInvoice;

/**
 * invoice_pending: an earlier invoice of the order may still be paid (SPEC.md §21). It carries
 * that invoice, and the URL of a new checkout link to it when the order asked for one.
 */
final class InvoicePendingException extends SattlemeException
{
    public function __construct(
        private readonly DeclaredInvoice $invoice,
        private readonly ?string $checkoutUrl,
    ) {
        parent::__construct('An earlier invoice of the order may still be paid', 'invoice_pending', false);
    }

    public function getInvoice(): DeclaredInvoice
    {
        return $this->invoice;
    }

    /** A new checkout link to the pending invoice, when the order asked for one; else null. */
    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }
}
