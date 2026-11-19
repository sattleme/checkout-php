<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

/**
 * A task started on the loop: its result is its future's.
 *
 * @internal
 */
final class Task
{
    public int $id = 0;

    public readonly Future $future;

    public function __construct()
    {
        $this->future = new Future();
    }

    /** Waits for the task's end: its value, or its exception thrown. */
    public function await(?float $deadline = null): mixed
    {
        return $this->future->await($deadline);
    }

    public function isDone(): bool
    {
        return $this->future->isDone();
    }
}
