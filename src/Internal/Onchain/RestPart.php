<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\BigInt;

/**
 * The rest part of an invoice with an address (SPEC.md §31): its rest invoices, each checked as a
 * Lightning part. Paid by rests: the sum of those paid; final when each is expired or paid; its
 * error the first one's (one that fails counts as not paid).
 *
 * @internal
 */
final class RestPart
{
    /** @var list<RestInvoice> sorted */
    public readonly array $invoices;

    /**
     * @param list<RestInvoice> $invoices
     * @param SattlemeException|null $failure the part failed as a whole: the sale's records
     *                                               could not be read, or a declaration not published again
     */
    public function __construct(array $invoices, private readonly ?SattlemeException $failure = null)
    {
        $this->invoices = RestInvoice::sorted($invoices);
    }

    /** @return numeric-string */
    public function paid(): string
    {
        $sum = '0';
        foreach ($this->invoices as $invoice) {
            if ($invoice->isPaid()) {
                $sum = BigInt::add($sum, $invoice->sats);
            }
        }

        return $sum;
    }

    /**
     * The preimages of those paid, in their order.
     *
     * @return list<string>
     */
    public function preimages(): array
    {
        $preimages = [];
        foreach ($this->invoices as $invoice) {
            if ($invoice->state !== null && $invoice->state[0] === 'paid') {
                $preimages[] = $invoice->state[1];
            }
        }

        return $preimages;
    }

    public function isFinal(): bool
    {
        if ($this->failure !== null) {
            return false;
        }
        foreach ($this->invoices as $invoice) {
            if (!$invoice->isFinal()) {
                return false;
            }
        }

        return true;
    }

    public function error(): ?SattlemeException
    {
        if ($this->failure !== null) {
            return $this->failure;
        }
        foreach ($this->invoices as $invoice) {
            if ($invoice->error !== null) {
                return $invoice->error;
            }
        }

        return null;
    }

    /**
     * The status's `bolt11` for a rest of $sats (SPEC.md §31, "The status's rest"): the latest
     * rest invoice of exactly that amount, not paid, with now < its end, whose rest record is on a
     * majority of the relays; null when there is none. Without $recorded, whatever its record.
     *
     * @param numeric-string $sats
     */
    public function invoiceOf(string $sats, int $now, bool $recorded = true): ?RestInvoice
    {
        $found = null;
        foreach ($this->invoices as $invoice) {
            if ($invoice->sats === $sats && !$invoice->isPaid() && $invoice->error === null && $now < $invoice->expiresAt && ($invoice->recordOnMajority || !$recorded)) {
                $found = $invoice;
            }
        }

        return $found;
    }
}
