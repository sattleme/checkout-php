<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * A client's method called from a callback of one of its calls (onStatus, a custom rate source,
 * the logger): refused at once. Such a call would run inside the other, which waits for it.
 *
 * @internal
 */
final class NestedCallException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('A sattle.me client was called from a callback of one of its calls (onStatus, a rate source, the logger): call it once that call returned');
    }
}
