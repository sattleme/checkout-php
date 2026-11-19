<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

/**
 * Named locks of a client (SPEC.md §17: calls for one ref one after the other; §31: the checks of
 * one invoice ask for an invoice of its rest one at a time).
 *
 * @internal
 */
final class Locks
{
    /** @var array<string, Future> */
    private array $held = [];

    /**
     * Runs $critical once no other task holds $name, waiting until $deadline at most.
     *
     * @template T
     *
     * @param \Closure(): T $critical
     *
     * @return T
     *
     * @throws TimeoutException when $deadline passes before the lock is free
     */
    public function with(string $name, \Closure $critical, ?float $deadline = null): mixed
    {
        while (isset($this->held[$name])) {
            $this->held[$name]->await($deadline);
        }
        $released = new Future();
        $this->held[$name] = $released;
        try {
            return $critical();
        } finally {
            // Never suspends: it runs too when a dropped task unwinds.
            unset($this->held[$name]);
            $released->resolve(null);
        }
    }
}
