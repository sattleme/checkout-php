<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;

/**
 * What the on-chain part of a payment check reads and writes (SPEC.md §31, "The on-chain
 * payment"): the explorer, the order's witnesses, the merchant's acceptance, BlockCypher. The
 * SDK's is LiveSource; the conformance vectors drive the same steps with theirs.
 *
 * @internal
 */
interface OnchainSource
{
    /**
     * The address's transactions on the explorer, and the chain's height there: one read, of one
     * explorer (SPEC.md §31, `fallback`).
     *
     * @return array{0: list<Transaction>, 1: int}
     *
     * @throws NetworkException
     */
    public function explorer(string $address): array;

    /**
     * The witnesses of the invoice of the sale $sale whose end is $expiresAt: of this invoice, of
     * other invoices, of none, and those of a newer format. $again: read again after a witness of
     * this invoice was published, on every relay (SPEC.md §13).
     *
     * @throws NetworkException when fewer than a majority of the relays answered
     */
    public function witnesses(string $address, string $sale, int $expiresAt, bool $again = false): WitnessSet;

    /**
     * The merchant's acceptance of the invoice of the sale $sale at $address, of $sats, whose end
     * is $expiresAt (SPEC.md §31, "The merchant's acceptance"): read on every relay (SPEC.md §13),
     * at the same time as the witnesses; what a majority of the relays sent counts.
     *
     * @param numeric-string $sats
     *
     * @throws NetworkException when fewer than a majority of the relays answered
     */
    public function merchantAcceptance(string $address, string $sale, string $sats, int $expiresAt): MerchantAcceptance;

    /**
     * Publishes a witness seen now (`sale` null: none; `expiresAt` the invoice's, null for none):
     * whether a majority of the relays took it.
     *
     * @param numeric-string $sats
     * @param list<string>   $inputs
     */
    public function publish(string $address, string $txid, ?string $sale, string $sats, array $inputs, bool $replacement, ?int $expiresAt): bool;

    /**
     * When BlockCypher received $txid, in Unix milliseconds; null when it does not know it yet.
     *
     * @throws NetworkException
     */
    public function received(string $txid): ?int;

}
