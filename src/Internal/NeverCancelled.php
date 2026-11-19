<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Cancellation;

/**
 * The cancellation of a call given none.
 *
 * @internal
 */
final class NeverCancelled implements Cancellation
{
    public function isCancelled(): bool
    {
        return false;
    }
}
