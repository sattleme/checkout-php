<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

/**
 * A value to come (or an exception), which tasks wait for.
 *
 * @internal
 */
final class Future
{
    private bool $done = false;

    private mixed $value = null;

    private ?\Throwable $error = null;

    /** @var list<Waiter> */
    private array $waiters = [];

    public function isDone(): bool
    {
        return $this->done;
    }

    public function resolve(mixed $value): void
    {
        $this->settle($value, null);
    }

    public function reject(\Throwable $error): void
    {
        $this->settle(null, $error);
    }

    /**
     * Waits, in a task, for the value; throws its exception, or TimeoutException once $deadline
     * (Loop::now()) passes.
     */
    public function await(?float $deadline = null): mixed
    {
        if (!$this->done) {
            $loop = Loop::get();
            $waiter = new Waiter($loop->currentId(), null, null, $deadline);
            $this->waiters[] = $waiter;
            // settle() fires with true; a deadline, with false.
            if ($loop->suspend($waiter) !== true) {
                throw new TimeoutException('Timed out');
            }
        }

        return $this->value();
    }

    /** The value of a future that is done, or its exception thrown. */
    public function value(): mixed
    {
        if (!$this->done) {
            throw new \LogicException('Not done yet');
        }
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    private function settle(mixed $value, ?\Throwable $error): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->value = $value;
        $this->error = $error;
        $loop = Loop::get();
        foreach ($this->waiters as $waiter) {
            $loop->fire($waiter, true);
        }
        $this->waiters = [];
    }
}
