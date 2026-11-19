<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

/**
 * Items handed from task to task, first in first out (a relay connection's messages to the
 * request that waits for them).
 *
 * @internal
 */
final class Queue
{
    /** @var list<mixed> */
    private array $items = [];

    private ?Waiter $waiter = null;

    public function push(mixed $item): void
    {
        $this->items[] = $item;
        if ($this->waiter !== null) {
            $waiter = $this->waiter;
            $this->waiter = null;
            Loop::get()->fire($waiter, true);
        }
    }

    /**
     * The next item; waits for it in a task, until $deadline (Loop::now()): TimeoutException.
     * One task at a time waits on a queue.
     */
    public function pop(?float $deadline = null): mixed
    {
        while ($this->items === []) {
            if ($this->waiter !== null && !$this->waiter->fired) {
                throw new \LogicException('Two tasks wait on one queue');
            }
            $loop = Loop::get();
            $this->waiter = new Waiter($loop->currentId(), null, null, $deadline);
            // push() fires with true; a deadline, with false.
            $woke = $loop->suspend($this->waiter);
            $this->waiter = null;
            if ($woke !== true) {
                throw new TimeoutException('Timed out');
            }
        }

        return array_shift($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
