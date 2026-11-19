<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\Internal\Loop\Future;
use Sattleme\Checkout\Internal\Loop\Queue;

/**
 * One read of RelayPool on its relays: over once the read went on without the relays still
 * reading (SPEC.md §13: they are dropped). Their requests then end at once and ask nothing more
 * (no page, no authentication, no query once their connection opens), drawing no random bytes for
 * it; their running out of time is no failure of their relay: a relay counts as failed in the call
 * only for a request that was still waited for.
 *
 * @internal
 */
final class ReadState
{
    /** What a request of the read finds in its queue once the read went on: it ends there. */
    public const DROPPED = 'dropped';

    public bool $over = false;

    /** @var array<int, Queue|Future> what its requests wait on, by serial: woken when it goes on */
    private array $waits = [];

    private int $serial = 0;

    /**
     * Wakes $wait, a request's own, once the read goes on (end()): a Queue gets DROPPED, a Future
     * is rejected. Returns what forgets it, once its request no longer waits there.
     *
     * @return \Closure(): void
     */
    public function wakes(Queue|Future $wait): \Closure
    {
        $serial = $this->serial++;
        $this->waits[$serial] = $wait;

        return function () use ($serial): void {
            unset($this->waits[$serial]);
        };
    }

    /** The read goes on without the relays still reading: their requests end at once. */
    public function end(): void
    {
        $this->over = true;
        $waits = $this->waits;
        $this->waits = [];
        foreach ($waits as $wait) {
            if ($wait instanceof Queue) {
                $wait->push(self::DROPPED);
            } else {
                $wait->reject(self::dropped());
            }
        }
    }

    /**
     * Throws when $read went on: its request ends there, asking nothing more.
     *
     * @throws RelayException
     */
    public static function endIfOver(?self $read): void
    {
        if ($read !== null && $read->over) {
            throw self::dropped();
        }
    }

    private static function dropped(): RelayException
    {
        return new RelayException('The read went on without this relay');
    }
}
