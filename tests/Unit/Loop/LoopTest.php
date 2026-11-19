<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit\Loop;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Internal\Loop\Future;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Queue;
use Sattleme\Checkout\Internal\Loop\Scope;
use Sattleme\Checkout\Internal\Loop\TimeoutException;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\TimeoutCancellation;

final class LoopTest extends TestCase
{
    public function testTasksRunAtOnce(): void
    {
        $loop = Loop::get();
        $start = Loop::now();
        $result = $loop->run(static function () use ($loop): array {
            $tasks = [];
            foreach ([0.15, 0.1, 0.05] as $i => $delay) {
                $tasks[] = $loop->spawn(static function () use ($loop, $delay, $i): int {
                    $loop->sleepUntil(TestTime::deadline($delay));

                    return $i;
                });
            }

            return array_map(static fn($task): mixed => $task->await(), $tasks);
        });
        self::assertSame([0, 1, 2], $result);
        self::assertLessThan(TestTime::seconds(0.3), Loop::now() - $start, 'three waits at once, not one after the other');
    }

    public function testAFutureTimesOut(): void
    {
        $loop = Loop::get();
        $this->expectException(TimeoutException::class);
        $loop->run(static fn(): mixed => (new Future())->await(TestTime::deadline(0.05)));
    }

    public function testExceptionsGoThroughTheCall(): void
    {
        $loop = Loop::get();
        try {
            $loop->run(static function () use ($loop): void {
                $task = $loop->spawn(static function (): never {
                    throw new \DomainException('from a task');
                });
                $task->await();
            });
            self::fail('No exception');
        } catch (\DomainException $e) {
            self::assertSame('from a task', $e->getMessage());
        }
    }

    public function testAQueueHandsItemsInOrder(): void
    {
        $loop = Loop::get();
        $items = $loop->run(static function () use ($loop): array {
            $queue = new Queue();
            $loop->spawn(static function () use ($loop, $queue): void {
                foreach (['a', 'b', 'c'] as $item) {
                    $loop->sleepUntil(TestTime::deadline(0.01));
                    $queue->push($item);
                }
            });

            return [$queue->pop(TestTime::deadline(1)), $queue->pop(TestTime::deadline(1)), $queue->pop(TestTime::deadline(1))];
        });
        self::assertSame(['a', 'b', 'c'], $items);
    }

    public function testACancellationStopsTheCall(): void
    {
        $loop = Loop::get();
        $start = Loop::now();
        try {
            $loop->run(static function () use ($loop): void {
                $loop->sleepUntil(TestTime::deadline(5));
            }, new TimeoutCancellation(TestTime::seconds(0.1)));
            self::fail('Not cancelled');
        } catch (CancelledException) {
            self::assertLessThan(TestTime::seconds(1), Loop::now() - $start);
        }
        $this->expectException(CancelledException::class);
        $loop->run(static fn(): int => 1, new TimeoutCancellation(0));
    }

    public function testACleanupForgottenIsNeitherRunNorKept(): void
    {
        // A cleanup done before the call ends (a pool closed early) is forgotten: not run again at
        // the end, and not kept until then (a wait of hours closes a pool at each of its checks).
        $loop = Loop::get();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $scopes = new \ReflectionProperty(Loop::class, 'scopes');
        $kept = $loop->run(static function () use ($loop, $log, $scopes): array {
            $kept = [];
            for ($i = 0; $i < 3; ++$i) {
                $forget = $loop->defer(static function () use ($log, $i): void {
                    $log[] = "early {$i}";
                });
                $forget();
                $running = $scopes->getValue($loop);
                \assert(\is_array($running) && end($running) instanceof Scope);
                $kept[] = \count(end($running)->cleanups);
            }
            $loop->defer(static function () use ($log): void {
                $log[] = 'at the end';
            });

            return $kept;
        });
        self::assertSame([0, 0, 0], $kept);
        self::assertSame(['at the end'], $log->getArrayCopy());
    }

    public function testACancellationFoundAfterABlockingStepWinsOverWhatTheStepGave(): void
    {
        // A task blocks the process past the cancellation (as a name lookup does), then finds it
        // cancelled: the call's main task, which waits for its answer, is told at once, and the
        // answer the task hands over as it ends never makes the call succeed (SPEC.md §4).
        $loop = Loop::get();
        $queue = new Queue();
        try {
            $answer = $loop->run(static function () use ($loop, $queue): mixed {
                $loop->spawn(static function () use ($loop, $queue): void {
                    $loop->sleepUntil(TestTime::deadline(0.05)); // the main task waits meanwhile
                    TestTime::block(300);
                    try {
                        $loop->throwIfCancelled();
                    } finally {
                        $queue->push('an answer');
                    }
                });

                return $queue->pop(TestTime::deadline(30));
            }, new TimeoutCancellation(TestTime::seconds(0.1)));
            self::fail('Not cancelled: ' . (\is_string($answer) ? $answer : ''));
        } catch (CancelledException) {
        }
        // Not cancelled: nothing thrown.
        $next = random_int(1, 1000);
        self::assertSame($next, $loop->run(static function () use ($loop, $next): int {
            $loop->throwIfCancelled();

            return $next;
        }, new TimeoutCancellation(TestTime::seconds(30))));
    }

    public function testTheTasksOfACallAreDroppedWhenItEnds(): void
    {
        $loop = Loop::get();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $loop->run(static function () use ($loop, $log): void {
            $loop->spawn(static function () use ($loop, $log): void {
                try {
                    $loop->sleepUntil(TestTime::deadline(10));
                    $log[] = 'woke';
                } finally {
                    $log[] = 'unwound';
                }
            });
            $loop->defer(static function () use ($log): void {
                $log[] = 'cleanup';
            });
        });
        self::assertSame(['unwound', 'cleanup'], $log->getArrayCopy());
        // Nothing of that call is left to wake up in the next one.
        $next = random_int(1, 1000);
        self::assertSame($next, $loop->run(static fn(): int => $next));
    }

    public function testATaskStartsOnlyInACall(): void
    {
        // Every task belongs to a call, and ends with it: none runs on once its call returned.
        $loop = Loop::get();
        try {
            $loop->spawn(static function (): void {});
            self::fail('A task started outside a call');
        } catch (\LogicException $e) {
            self::assertSame('No call is running: a task would outlive it', $e->getMessage());
        }
        self::assertFalse($loop->isRunning());
    }

    public function testACallMadeFromATaskDrivesTheSameLoop(): void
    {
        $loop = Loop::get();
        $result = $loop->run(static function () use ($loop): array {
            $outer = $loop->spawn(static function () use ($loop): string {
                $loop->sleepUntil(TestTime::deadline(0.05));

                return 'outer';
            });
            $inner = $loop->run(static function () use ($loop): string {
                $loop->sleepUntil(TestTime::deadline(0.1));

                return 'inner';
            });

            return [$inner, $outer->await()];
        });
        self::assertSame(['inner', 'outer'], $result);
    }

    public function testStreamsWakeTheirTasks(): void
    {
        $loop = Loop::get();
        $pair = stream_socket_pair(\PHP_OS_FAMILY === 'Windows' ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$a, $b] = $pair;
        stream_set_blocking($a, false);
        stream_set_blocking($b, false);
        $read = $loop->run(static function () use ($loop, $a, $b): string {
            $loop->spawn(static function () use ($loop, $b): void {
                $loop->sleepUntil(TestTime::deadline(0.05));
                fwrite($b, 'ping');
            });
            self::assertFalse($loop->awaitReadable($a, TestTime::deadline(0.01)), 'nothing yet');
            self::assertTrue($loop->awaitReadable($a, TestTime::deadline(1)));

            return (string) fread($a, 10);
        });
        self::assertSame('ping', $read);
        fclose($a);
        fclose($b);
    }
}
