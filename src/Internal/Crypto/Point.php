<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Crypto;

use Sattleme\Checkout\Internal\BigInt;

/**
 * A point of secp256k1 other than the point at infinity (null stands for that one), its
 * coordinates as decimal strings below p.
 *
 * @internal
 */
final class Point
{
    /**
     * @param numeric-string $x
     * @param numeric-string $y
     */
    public function __construct(
        public readonly string $x,
        public readonly string $y,
    ) {}

    /** The x coordinate as 32 big-endian bytes. */
    public function xBytes(): string
    {
        return BigInt::toBytes($this->x, 32);
    }

    public function hasEvenY(): bool
    {
        return !BigInt::isOdd($this->y);
    }
}
