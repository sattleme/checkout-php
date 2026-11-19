<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

/**
 * A cancellation, given to any method (SPEC.md §4): once it reads cancelled, the call stops its
 * requests and fails with CancelledException. The SDK polls it while it waits (every 100 ms at
 * most), so it may watch anything: a deadline, a flag, connection_aborted().
 */
interface Cancellation
{
    public function isCancelled(): bool;
}
