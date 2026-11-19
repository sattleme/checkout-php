<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Invoice;

/**
 * An invoice to check (SPEC.md §3 "InvoiceToCheck", §22): an Invoice, a DeclaredInvoice, or the
 * JSON an integrator stored (decoded, as arrays or objects), checked before anything is asked.
 * Every member the check reads is there, but `onchainAddress` (SPEC.md §2: one left out is not
 * null): an invoice without `bolt11`, say, is no invoice createInvoice made. The check proves that
 * an invoice is well formed, never who made it (SPEC.md §27: the shop's own, never the customer's).
 *
 * @internal
 */
final class StoredInvoice
{
    private const NOT_STORED = 'Not an invoice made by createInvoice';

    /**
     * @param numeric-string $sats
     * @param string|null    $ref  with an address, its order's reference
     */
    private function __construct(
        public readonly ?string $ref,
        public readonly ?string $bolt11,
        public readonly ?string $paymentHash,
        public readonly ?string $verifyUrl,
        public readonly ?string $onchainAddress,
        public readonly string $sats,
        public readonly int $expiresAt,
    ) {}

    /** @throws InvalidOrderException (invoice) */
    public static function check(mixed $invoice): self
    {
        if ($invoice instanceof Invoice || $invoice instanceof DeclaredInvoice) {
            $invoice = $invoice->toArray();
        } elseif ($invoice instanceof \stdClass) {
            $invoice = get_object_vars($invoice);
        }
        if (!\is_array($invoice)) {
            throw new InvalidOrderException('invoice', self::NOT_STORED);
        }
        foreach (['sats', 'expiresAt', 'bolt11', 'paymentHash', 'verifyUrl'] as $member) {
            if (!\array_key_exists($member, $invoice)) {
                throw new InvalidOrderException('invoice', self::NOT_STORED . ": without {$member}");
            }
        }
        $sats = $invoice['sats'];
        $expiresAt = Json::safeInteger($invoice['expiresAt']);
        if (!\is_string($sats) || preg_match('/^[0-9]{1,16}$/D', $sats) !== 1 || $expiresAt === null) {
            throw new InvalidOrderException('invoice', self::NOT_STORED);
        }
        $sats = ltrim($sats, '0');
        if (!is_numeric($sats) || BigInt::cmp($sats, (string) Protocol::MAX_SATS) > 0) {
            throw new InvalidOrderException('invoice', self::NOT_STORED);
        }
        $bolt11 = $invoice['bolt11'];
        $paymentHash = $invoice['paymentHash'];
        $verifyUrl = $invoice['verifyUrl'];
        if ($bolt11 === null) {
            if ($paymentHash !== null || $verifyUrl !== null) {
                throw new InvalidOrderException('invoice', self::NOT_STORED);
            }
        } else {
            $decoded = Bolt11::decode($bolt11);
            if ($decoded === null || $decoded->network !== 'bc' || $decoded->amountMsat !== $sats . '000'
                || !Text::isHex32($paymentHash) || $decoded->paymentHash !== $paymentHash
                || $decoded->expiresAt() !== $expiresAt || !Url::isHttps($verifyUrl)) {
                throw new InvalidOrderException('invoice', self::NOT_STORED);
            }
            \assert(\is_string($bolt11) && \is_string($verifyUrl));
        }
        $address = $invoice['onchainAddress'] ?? null;
        if ($address !== null && !DepositAddress::isValid($address)) {
            throw new InvalidOrderException('invoice', self::NOT_STORED);
        }
        \assert($address === null || \is_string($address));
        if ($bolt11 === null && $address === null) {
            throw new InvalidOrderException('invoice', self::NOT_STORED);
        }
        $ref = null;
        if ($address !== null) {
            $ref = $invoice['ref'] ?? null;
            if (!Order::isRef($ref)) {
                throw new InvalidOrderException('invoice', self::NOT_STORED . ': with an address, its ref');
            }
            \assert(\is_string($ref));
        }

        return new self($ref, $bolt11, $paymentHash, $verifyUrl, $address, $sats, $expiresAt);
    }
}
