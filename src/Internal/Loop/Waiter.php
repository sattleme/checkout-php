<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Loop;

/**
 * What a suspended task waits for: a stream to read ('r') or write ('w'), a deadline, or both;
 * or neither (a Future, a Queue, which fire it themselves).
 *
 * @internal
 */
final class Waiter
{
    private static int $counter = 0;

    public readonly int $id;

    public bool $fired = false;

    /**
     * @param int                $fiber    the id of the waiting fiber
     * @param resource|null      $stream
     * @param 'r'|'w'|null       $mode
     * @param float|null         $deadline Loop::now() after which it fires with false
     */
    public function __construct(
        public readonly int $fiber,
        public readonly mixed $stream,
        public readonly ?string $mode,
        public readonly ?float $deadline,
    ) {
        $this->id = ++self::$counter;
    }
}
