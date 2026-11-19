<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Onchain\OnchainResult;
use Sattleme\Checkout\Internal\Onchain\RestPart;

/**
 * What each part of an invoice with an address gave at its last check (SPEC.md §31, "Waiting for
 * an on-chain payment"): each status combines the last result of the parts.
 *
 * @internal
 */
final class Snapshot
{
    /**
     * @param array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning
     * @param OnchainResult|SattlemeException|null                                                    $onchain   null once the Lightning invoice paid it
     */
    public function __construct(
        public readonly array|SattlemeException|null $lightning,
        public readonly OnchainResult|SattlemeException|null $onchain,
        public readonly RestPart $rests,
    ) {}
}
