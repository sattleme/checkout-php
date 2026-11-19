<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\Internal\Loop\Queue;

/**
 * A query of one connection (SPEC.md §13): what it keeps of each event, decided as the event comes,
 * how many events came, at most 1000, and the ids it read: what a relay sends never piles up.
 *
 * @internal
 */
final class Subscription
{
    /** The EVENT messages it took so far. */
    public int $events = 0;

    /**
     * The ids of the events it read (as entryOf() keeps them): of each id, the first event is the
     * one read; a later one (a copy, or a forgery: an id is its event's hash) only counts.
     *
     * @var array<string, true>
     */
    public array $ids = [];

    /**
     * @param \Closure(\stdClass): bool $keep whether the read keeps an event (one that opens)
     */
    public function __construct(
        public readonly Queue $queue,
        public readonly \Closure $keep,
    ) {}
}
