<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

use Sattleme\Checkout\Cancellation;

/**
 * A call running on the loop: its main fiber, its tasks, its cleanups.
 *
 * @internal
 */
final class Scope
{
    public ?int $main = null;

    /** @var array<int, true> */
    public array $tasks = [];

    /** @var array<int, \Closure(): void> by the order they were deferred in */
    public array $cleanups = [];

    /** How many cleanups were deferred: the next one's key. */
    public int $deferred = 0;

    public bool $cancelled = false;

    public function __construct(public readonly ?Cancellation $cancellation) {}
}
