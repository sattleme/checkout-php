<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

use Sattleme\Checkout\CancelledException;

/**
 * Runs closures at once as tasks of the running call, and waits for them all.
 *
 * @internal
 */
final class Parallel
{
    private function __construct() {}

    /**
     * Runs each of $tasks, $limit at a time at most: the outcome of each, in their order, as
     * [its value, null] or [null, its exception]. A cancellation of the call is thrown here.
     *
     * @param list<\Closure(): mixed> $tasks
     *
     * @return list<array{0: mixed, 1: \Throwable|null}>
     */
    public static function settle(array $tasks, int $limit = \PHP_INT_MAX): array
    {
        $loop = Loop::get();
        /** @var array<int, array{0: mixed, 1: \Throwable|null}> $outcomes */
        $outcomes = [];
        $next = 0;
        $count = \count($tasks);
        $workers = [];
        for ($worker = 0; $worker < min($limit, $count); ++$worker) {
            $workers[] = $loop->spawn(static function () use (&$next, &$outcomes, $tasks, $count): void {
                while ($next < $count) {
                    $index = $next++;
                    try {
                        $outcomes[$index] = [$tasks[$index](), null];
                    } catch (\Throwable $e) {
                        $outcomes[$index] = [null, $e];
                    }
                }
            });
        }
        foreach ($workers as $worker) {
            $worker->await();
        }
        ksort($outcomes);
        foreach ($outcomes as [, $error]) {
            if ($error instanceof CancelledException) {
                throw $error;
            }
        }

        return array_values($outcomes);
    }

    /**
     * Runs each of $tasks at once: their values in order, or the first exception (in their
     * order) thrown once they all ended.
     *
     * @param list<\Closure(): mixed> $tasks
     *
     * @return list<mixed>
     */
    public static function all(array $tasks): array
    {
        $values = [];
        foreach (self::settle($tasks) as [$value, $error]) {
            if ($error !== null) {
                throw $error;
            }
            $values[] = $value;
        }

        return $values;
    }
}
