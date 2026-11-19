<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Rest;

/**
 * When a payment check asks for an invoice of the rest (SPEC.md §31, "Asking for a rest
 * invoice"): its status is open with a rest and no invoice of it, 60 s are left at least, the
 * sale's lease of the address has the invoice's terms (it gives the price and the note), and the
 * rest is within the Lightning address's sendable sats.
 *
 * @internal
 */
final class RestAsking
{
    private function __construct() {}

    /**
     * The invoice to ask for: [its sats, its expiry in seconds], or null when none is asked.
     *
     * @param numeric-string                                    $sats       the invoice's
     * @param array{sats: string, expiresAt: int}|null          $leaseTerms the sale's lease of the address
     * @param numeric-string                                    $min        sendable
     * @param numeric-string                                    $max        sendable
     *
     * @return array{0: numeric-string, 1: int}|null
     */
    public static function ask(?Rest $rest, string $sats, int $expiresAt, int $now, ?array $leaseTerms, string $min, string $max): ?array
    {
        if ($rest === null || $rest->bolt11 !== null || $now + Protocol::MIN_REST_SECONDS > $expiresAt) {
            return null;
        }
        if ($leaseTerms === null || $leaseTerms['sats'] !== $sats || $leaseTerms['expiresAt'] !== $expiresAt) {
            return null;
        }
        if (BigInt::cmp($rest->sats, $min) < 0 || BigInt::cmp($rest->sats, $max) > 0) {
            return null;
        }

        return [$rest->sats, $expiresAt - $now];
    }
}
