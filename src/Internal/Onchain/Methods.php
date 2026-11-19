<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\OnchainUnavailableException;
use Sattleme\Checkout\Internal\BigInt;

/**
 * The methods of an order by its amount (SPEC.md §31, "Methods by amount"): Lightning within the
 * Lightning address's sendable sats, on-chain from 1 000 sats when an address could be leased.
 * Nothing is repaired: an amount is never split, nor moved into the sendable sats.
 *
 * @internal
 */
final class Methods
{
    /** An address was leased. */
    public const LEASED = 'leased';
    /** No address could be leased. */
    public const NONE = 'none';
    /** The explorer failed while leasing. */
    public const EXPLORER_FAILED = 'explorer_failed';

    private function __construct() {}

    /**
     * Step 4 of SPEC.md §17: whether Lightning takes the amount (within [$min, $max]), and whether
     * on-chain may (1 000 sats or more, an address still to lease); an order below 1 000 sats
     * outside the sendable sats has no method.
     *
     * @param numeric-string $sats
     * @param numeric-string $min
     * @param numeric-string $max
     *
     * @return array{lightning: bool, onchain: bool}
     *
     * @throws InvalidOrderException naming $param (sats or fiat)
     */
    public static function byAmount(string $sats, string $min, string $max, string $param): array
    {
        $lightning = BigInt::cmp($sats, $min) >= 0 && BigInt::cmp($sats, $max) <= 0;
        $onchain = DepositAddress::allowsOnchain($sats);
        if (!$lightning && !$onchain) {
            throw new InvalidOrderException($param, "{$param}: below 1000 sats, the amount must be within the Lightning address's sendable sats ({$min} to {$max})");
        }

        return ['lightning' => $lightning, 'onchain' => $onchain];
    }

    /**
     * Step 7 of SPEC.md §17: the methods once leasing gave $outcome; an order only payable
     * on-chain without an address fails.
     *
     * @param array{lightning: bool, onchain: bool} $byAmount
     * @param self::LEASED|self::NONE|self::EXPLORER_FAILED $outcome
     *
     * @return list<'lightning'|'onchain'>
     *
     * @throws OnchainUnavailableException|NetworkException
     */
    public static function afterLease(array $byAmount, string $outcome): array
    {
        $methods = $byAmount['lightning'] ? ['lightning'] : [];
        if ($byAmount['onchain'] && $outcome === self::LEASED) {
            $methods[] = 'onchain';
        }
        if ($methods === []) {
            throw $outcome === self::EXPLORER_FAILED
                ? new NetworkException('The explorer did not answer: no deposit address could be checked')
                : new OnchainUnavailableException('No deposit address of the lot could be leased: the merchant fills the lot again when opening the app');
        }

        return $methods;
    }
}
