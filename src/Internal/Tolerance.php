<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * The merchant's tolerance of on-chain underpayments (SPEC.md §31, "The merchant's tolerance"):
 * basis points from 0 to 1000; anything else reads as 0.
 *
 * @internal
 */
final class Tolerance
{
    private function __construct() {}

    public static function read(mixed $value): int
    {
        return Json::intIn($value, 0, Protocol::MAX_TOLERANCE) ?? 0;
    }

    /**
     * The least accepted of $sats: sats − floor(sats × T / 10000).
     *
     * @param numeric-string $sats
     *
     * @return numeric-string
     */
    public static function leastAccepted(string $sats, #[\SensitiveParameter] int $tolerance): string
    {
        return BigInt::sub($sats, BigInt::div(BigInt::mul($sats, (string) $tolerance), '10000'));
    }

    /**
     * What is due on-chain (SPEC.md §31, "The on-chain payment", step 4) of an invoice of $sats
     * whose rest invoices paid $paidByRests, with the `received` of the merchant's acceptance that
     * counts, $merchantAccepted (null without one: MerchantAcceptance): 0 when they paid $sats or
     * more (they pay the order), else max(1, min(L, M) − R), L the least accepted, M the merchant's
     * acceptance's `received` ($sats without one), R what they paid. R is taken once from the
     * lesser: M counts the rest invoices paid when the merchant accepted, as L counts every
     * payment. 1 at least: something counts on-chain for the tolerance or the merchant's acceptance
     * to apply.
     *
     * @param numeric-string      $sats
     * @param numeric-string      $paidByRests
     * @param numeric-string|null $merchantAccepted
     *
     * @return numeric-string
     */
    public static function due(string $sats, #[\SensitiveParameter] int $tolerance, string $paidByRests, ?string $merchantAccepted = null): string
    {
        if (BigInt::cmp($paidByRests, $sats) >= 0) {
            return '0';
        }
        $least = self::leastAccepted($sats, $tolerance);
        $accepted = $merchantAccepted !== null && BigInt::cmp($merchantAccepted, $least) < 0 ? $merchantAccepted : $least;
        $due = BigInt::sub($accepted, $paidByRests);

        return BigInt::cmp($due, '1') < 0 ? '1' : $due;
    }
}
