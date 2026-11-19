<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

use Sattleme\Checkout\Cancellation;
use Sattleme\Checkout\CancelledException;

/**
 * The SDK's event loop: tasks are fibers, which suspend while they wait for a socket, a deadline
 * or another task; one stream_select() wakes them. It lets one call talk to every relay at once
 * (SPEC.md §13) with nothing but PHP's own streams.
 *
 * One loop per process, driven only while a call runs (run()): the call's tasks belong to it and
 * are dropped when it returns (a dropped fiber is unwound: its `finally` blocks run, and must not
 * wait). A call made while another runs (from a callback) drives the same loop.
 *
 * DNS resolution is the one thing that blocks (PHP has no asynchronous resolver): bounded by the
 * system's resolver, as any PHP connection is. The Cancellation cannot be polled meanwhile: the
 * SDK checks it before and after each connection (throwIfCancelled()).
 *
 * @internal
 */
final class Loop
{
    /** How often, at most, a call's Cancellation is polled while the loop waits. */
    private const CANCELLATION_POLL = 0.1;

    /** How long a task runs, at most, before checkpoint() lets the others have their turn. */
    private const SLICE = 0.02;

    private static ?self $instance = null;

    /** @var array<int, array{0: \Fiber<mixed, mixed, mixed, mixed>, 1: ?Scope}> live fibers, by id */
    private array $fibers = [];

    /** @var list<array{0: int, 1: mixed, 2: ?\Throwable}> fibers to resume: id, value, exception */
    private array $ready = [];

    /** @var array<int, Waiter> */
    private array $waiters = [];

    /** @var list<Scope> the calls running, the innermost last */
    private array $scopes = [];

    /** When the running task was started or resumed (Loop::now()). */
    private float $sliceStart = 0.0;

    private function __construct() {}

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /** Seconds on a monotonic clock, for deadlines. */
    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Runs $main as a call: until it returns (its value) or throws (its exception). Its tasks are
     * dropped once it is done. Once $cancellation reads cancelled, CancelledException is thrown
     * where $main waits.
     *
     * @template T
     *
     * @param \Closure(): T $main
     *
     * @return T
     */
    public function run(\Closure $main, ?Cancellation $cancellation = null): mixed
    {
        $scope = new Scope($cancellation);
        $this->scopes[] = $scope;
        $result = new Future();
        try {
            if ($cancellation !== null && $cancellation->isCancelled()) {
                throw new CancelledException();
            }
            $scope->main = $this->start(static function () use ($main, $result): void {
                try {
                    $result->resolve($main());
                } catch (\Throwable $e) {
                    $result->reject($e);
                }
            }, $scope);
            while (true) {
                $this->dispatch();
                if ($result->isDone()) {
                    break;
                }
                $this->tick($scope);
            }
        } finally {
            array_pop($this->scopes);
            $this->teardown($scope);
        }

        /** @var T */
        return $result->value();
    }

    /** Whether a call runs now. */
    public function isRunning(): bool
    {
        return $this->scopes !== [];
    }

    /**
     * Starts $task now, as a task of the running call: dropped when the call ends, so that nothing
     * a call started runs on once it returned (SPEC.md §13, §23). Never outside a call.
     */
    public function spawn(\Closure $task): Task
    {
        $scope = $this->scopes[\count($this->scopes) - 1] ?? null;
        if ($scope === null) {
            throw new \LogicException('No call is running: a task would outlive it');
        }

        return $this->spawnIn($task, $scope);
    }

    /** Starts $task now, as a task of no call: it lives until it ends or is cancelled (tests' servers). */
    public function spawnDetached(\Closure $task): Task
    {
        return $this->spawnIn($task, null);
    }

    /**
     * Runs $cleanup when the running call ends (it must not wait). Returns what forgets it, for a
     * cleanup done before (a relay pool closed early): what a long call defers, check after check,
     * is freed as it goes, never piled up until its end.
     *
     * @return \Closure(): void
     */
    public function defer(\Closure $cleanup): \Closure
    {
        $scope = $this->scopes[\count($this->scopes) - 1] ?? null;
        if ($scope === null) {
            throw new \LogicException('No call is running');
        }
        $key = $scope->deferred++;
        $scope->cleanups[$key] = $cleanup;

        return static function () use ($scope, $key): void {
            unset($scope->cleanups[$key]);
        };
    }

    /**
     * Throws CancelledException when the running call's Cancellation reads cancelled, and tells
     * its main task where it waits: before and after a step that blocks the process (a name
     * lookup), while which the loop polls nothing. So no such step starts once the call is
     * cancelled, and a cancellation that came meanwhile wins over what the step gave (SPEC.md
     * §4: a cancelled call fails with the cancellation, never with the error of a request it
     * stopped). In a task of no call (tests' servers), nothing.
     *
     * @throws CancelledException
     */
    public function throwIfCancelled(): void
    {
        $fiber = \Fiber::getCurrent();
        $scope = $fiber !== null && isset($this->fibers[spl_object_id($fiber)])
            ? $this->fibers[spl_object_id($fiber)][1]
            : $this->scopes[\count($this->scopes) - 1] ?? null;
        if ($scope === null || $scope->cancellation === null || !$scope->cancellation->isCancelled()) {
            return;
        }
        if (!$scope->cancelled) {
            foreach ($this->waiters as $waiter) {
                if ($waiter->fiber === $scope->main) {
                    // Told once; else (it runs, or is about to) the next wait of it tells it.
                    $scope->cancelled = true;
                    $this->fire($waiter, null, new CancelledException());
                }
            }
        }

        throw new CancelledException();
    }

    /**
     * Waits, in a task, until $stream can be read or $deadline (Loop::now()) passes.
     *
     * @param resource $stream
     *
     * @return bool whether it can be read
     */
    public function awaitReadable(mixed $stream, ?float $deadline): bool
    {
        return $this->suspend(new Waiter($this->currentId(), $stream, 'r', $deadline)) === true;
    }

    /**
     * Waits, in a task, until $stream can be written or $deadline passes.
     *
     * @param resource $stream
     *
     * @return bool whether it can be written
     */
    public function awaitWritable(mixed $stream, ?float $deadline): bool
    {
        return $this->suspend(new Waiter($this->currentId(), $stream, 'w', $deadline)) === true;
    }

    /** Waits, in a task, until $deadline. */
    public function sleepUntil(float $deadline): void
    {
        $this->suspend(new Waiter($this->currentId(), null, null, $deadline));
    }

    /**
     * In a task that may read or compute for long (a peer that never stops sending, records to
     * verify): once it ran a slice, it waits for nothing, so that the other tasks run, the sockets
     * are read and the deadlines and the call's Cancellation are checked. Outside a task, nothing.
     */
    public function checkpoint(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fibers[spl_object_id($fiber)]) && self::now() - $this->sliceStart >= self::SLICE) {
            $this->sleepUntil(self::now());
        }
    }

    /**
     * Wakes the tasks waiting on $stream (as if their deadline passed), before it is closed.
     *
     * @param resource $stream
     */
    public function forget(mixed $stream): void
    {
        foreach ($this->waiters as $waiter) {
            if ($waiter->stream === $stream) {
                $this->fire($waiter, false);
            }
        }
    }

    /**
     * Suspends the running task until $waiter fires: its value, or its exception thrown.
     *
     * @internal for Future and Queue
     */
    public function suspend(Waiter $waiter): mixed
    {
        $this->waiters[$waiter->id] = $waiter;

        return \Fiber::suspend();
    }

    /**
     * Fires $waiter: its task resumes with $value, or $error thrown. A waiter fires once.
     *
     * @internal for Future and Queue
     */
    public function fire(Waiter $waiter, mixed $value, ?\Throwable $error = null): void
    {
        if ($waiter->fired) {
            return;
        }
        $waiter->fired = true;
        unset($this->waiters[$waiter->id]);
        if (isset($this->fibers[$waiter->fiber])) {
            $this->ready[] = [$waiter->fiber, $value, $error];
        }
    }

    /** The id of the task running now (a fiber of this loop). */
    public function currentId(): int
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || !isset($this->fibers[spl_object_id($fiber)])) {
            throw new \LogicException('Not in a task of the loop');
        }

        return spl_object_id($fiber);
    }

    /** Drops a task: its waits are forgotten and its fiber unwound. */
    public function cancel(Task $task): void
    {
        $this->drop($task->id);
    }

    private function spawnIn(\Closure $task, ?Scope $scope): Task
    {
        $handle = new Task();
        $handle->id = $this->start(static function () use ($task, $handle): void {
            try {
                $handle->future->resolve($task());
            } catch (\Throwable $e) {
                $handle->future->reject($e);
            }
        }, $scope);

        return $handle;
    }

    /** @param \Closure(): void $body */
    private function start(\Closure $body, ?Scope $scope): int
    {
        $fiber = new \Fiber($body);
        $id = spl_object_id($fiber);
        $this->fibers[$id] = [$fiber, $scope];
        if ($scope !== null) {
            $scope->tasks[$id] = true;
        }
        $this->sliceStart = self::now();
        $fiber->start();
        $this->settle($id);

        return $id;
    }

    /** Forgets a fiber that ended. */
    private function settle(int $id): void
    {
        $fiber = $this->fibers[$id][0] ?? null;
        if ($fiber !== null && $fiber->isTerminated()) {
            $scope = $this->fibers[$id][1];
            unset($this->fibers[$id]);
            if ($scope !== null) {
                unset($scope->tasks[$id]);
            }
        }
    }

    private function tick(Scope $scope): void
    {
        if ($this->hasResumable()) {
            return;
        }
        if ($scope->cancellation !== null && !$scope->cancelled && $scope->cancellation->isCancelled()) {
            $scope->cancelled = true;
            foreach ($this->waiters as $waiter) {
                if ($waiter->fiber === $scope->main) {
                    $this->fire($waiter, null, new CancelledException());
                }
            }

            return;
        }
        $this->wait();
    }

    /** Resumes the fibers that may run. */
    private function dispatch(): void
    {
        while ($this->ready !== []) {
            $this->ready = array_values(array_filter($this->ready, fn(array $item): bool => isset($this->fibers[$item[0]])));
            $resumable = null;
            foreach ($this->ready as $index => [$id]) {
                if ($this->fibers[$id][0]->isSuspended()) {
                    $resumable = $index;
                    break;
                }
            }
            if ($resumable === null) {
                return; // Only fibers running further up the stack: their own loop resumes them.
            }
            [$id, $value, $error] = $this->ready[$resumable];
            array_splice($this->ready, $resumable, 1);
            $fiber = $this->fibers[$id][0];
            $this->sliceStart = self::now();
            if ($error !== null) {
                $fiber->throw($error);
            } else {
                $fiber->resume($value);
            }
            $this->settle($id);
        }
    }

    private function hasResumable(): bool
    {
        foreach ($this->ready as [$id]) {
            if (($this->fibers[$id][0] ?? null)?->isSuspended() === true) {
                return true;
            }
        }

        return false;
    }

    /** Waits for a socket or the next deadline, and fires the waiters it concerns. */
    private function wait(): void
    {
        $read = [];
        $write = [];
        $next = null;
        foreach ($this->waiters as $waiter) {
            if ($waiter->stream !== null) {
                if (!\is_resource($waiter->stream)) {
                    $this->fire($waiter, false);
                    continue;
                }
                if ($waiter->mode === 'r') {
                    $read[(int) $waiter->stream] = $waiter->stream;
                } else {
                    $write[(int) $waiter->stream] = $waiter->stream;
                }
            }
            if ($waiter->deadline !== null && ($next === null || $waiter->deadline < $next)) {
                $next = $waiter->deadline;
            }
        }
        if ($this->hasResumable()) {
            return;
        }
        $now = self::now();
        $timeout = $next === null ? null : max(0.0, $next - $now);
        foreach ($this->scopes as $scope) {
            if ($scope->cancellation !== null) {
                $timeout = $timeout === null ? self::CANCELLATION_POLL : min($timeout, self::CANCELLATION_POLL);
            }
        }
        if ($read === [] && $write === []) {
            if ($timeout === null) {
                throw new \LogicException('The loop has nothing to wait for');
            }
            if ($timeout > 0) {
                usleep((int) ceil($timeout * 1e6));
            }
        } else {
            $r = array_values($read);
            $w = array_values($write);
            $e = null;
            $seconds = $timeout === null ? null : (int) floor($timeout);
            $micro = $timeout === null ? null : (int) round(($timeout - floor($timeout)) * 1e6);
            set_error_handler(static fn(): bool => true); // EINTR: "Unable to select", tried again.
            try {
                $count = stream_select($r, $w, $e, $seconds, $micro);
            } finally {
                restore_error_handler();
            }
            if ($count !== false && $count > 0) {
                $readyStreams = [];
                foreach ($r as $stream) {
                    $readyStreams['r' . (int) $stream] = true;
                }
                foreach ($w as $stream) {
                    $readyStreams['w' . (int) $stream] = true;
                }
                foreach ($this->waiters as $waiter) {
                    if ($waiter->stream !== null && isset($readyStreams[$waiter->mode . (int) $waiter->stream])) {
                        $this->fire($waiter, true);
                    }
                }
            }
        }
        $now = self::now();
        foreach ($this->waiters as $waiter) {
            if ($waiter->deadline !== null && $waiter->deadline <= $now) {
                $this->fire($waiter, false);
            }
        }
    }

    /** Ends a call: its cleanups run, its tasks are dropped. */
    private function teardown(Scope $scope): void
    {
        foreach (array_keys($scope->tasks) as $id) {
            $this->drop($id);
        }
        foreach ($scope->cleanups as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable) {
                // A cleanup (closing a socket) never fails the call.
            }
        }
    }

    private function drop(int $id): void
    {
        foreach ($this->waiters as $waiterId => $waiter) {
            if ($waiter->fiber === $id) {
                $waiter->fired = true;
                unset($this->waiters[$waiterId]);
            }
        }
        $this->ready = array_values(array_filter($this->ready, static fn(array $item): bool => $item[0] !== $id));
        $scope = $this->fibers[$id][1] ?? null;
        if ($scope !== null) {
            unset($scope->tasks[$id]);
        }
        // The last reference: PHP unwinds the suspended fiber (its finally blocks run).
        unset($this->fibers[$id]);
    }
}
