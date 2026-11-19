<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Queue;
use Sattleme\Checkout\Internal\Loop\TimeoutException;
use Sattleme\Checkout\Internal\Protocol;

/**
 * The relays of one call (SPEC.md §13): one connection each, closed when the call ends. Reads and
 * publications go to every relay of a list at once and go on at a majority; the reads that decide
 * that a record is nobody else's go on once every relay answered, or one relayTimeoutMs after a
 * majority did ("Reads on every relay").
 *
 * @internal
 */
final class RelayPool
{
    /** A relay's read when the read went on (SPEC.md §13): ended, complete. */
    public const COMPLETE = 'complete';

    /** Ended, not complete; for a read that is not a complete read, any read that ended. */
    public const INCOMPLETE = 'incomplete';

    /** Failed: its first page, or earlier in the call (a read on every relay asks it not again). */
    public const FAILED = 'failed';

    /** Still reading (a read on every relay keeps the pages it sent): it has not answered. */
    public const READING = 'reading';

    /** @var array<string, RelayConnection> */
    private array $connections = [];

    /** While it has connections: what forgets its closing at the call's end (Loop::defer()). */
    private ?\Closure $undefer = null;

    public function __construct(private readonly Context $context) {}

    /**
     * Closes its connections now (they would close when the call ends): the next request opens
     * them again. Closed, it leaves nothing for the call's end (a wait closes a pool at each of its
     * checks).
     */
    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        if ($this->undefer !== null) {
            ($this->undefer)();
            $this->undefer = null;
        }
    }

    /** floor(n / 2) + 1 (SPEC.md §2). */
    public static function majority(int $relays): int
    {
        return intdiv($relays, 2) + 1;
    }

    /**
     * Of a read of a list, from each relay's read when the read went on (COMPLETE, INCOMPLETE,
     * FAILED or READING): how many relays answered, and how many read completely. Only a relay
     * whose read ended, complete or not, answered (SPEC.md §13, "Reads on every relay"): one still
     * reading has not, whatever pages it sent (they are kept all the same).
     *
     * @param list<self::COMPLETE|self::INCOMPLETE|self::FAILED|self::READING> $reads
     *
     * @return array{answered: int, complete: int}
     */
    public static function tally(array $reads): array
    {
        $answered = 0;
        $complete = 0;
        foreach ($reads as $read) {
            if ($read === self::COMPLETE || $read === self::INCOMPLETE) {
                ++$answered;
            }
            if ($read === self::COMPLETE) {
                ++$complete;
            }
        }

        return ['answered' => $answered, 'complete' => $complete];
    }

    /**
     * What a read of a list gives, from each relay's read when it went on (one per relay of the
     * list, tally()): whether it answered (a majority of the relays answered: else it is no
     * answer, `network`) and whether it is complete (a majority read completely), SPEC.md §13.
     *
     * @param list<self::COMPLETE|self::INCOMPLETE|self::FAILED|self::READING> $reads
     *
     * @return array{answered: bool, complete: bool}
     */
    public static function outcome(array $reads): array
    {
        $tally = self::tally($reads);
        $majority = self::majority(\count($reads));

        return ['answered' => $tally['answered'] >= $majority, 'complete' => $tally['complete'] >= $majority];
    }

    /**
     * Reads $filter on every relay of $relays at once: the events of each relay that answered,
     * once a majority answered (the others are dropped) or every relay answered or failed.
     *
     * With $everyRelay (SPEC.md §13, "Reads on every relay": the choice among several invoices of
     * an order, the witnesses read again), once every relay answered or failed, or one
     * relayTimeoutMs after a majority answered: a relay that hides a record cannot hide it while
     * one that holds it answers in time. A relay that ran out of time (while a read waited for it)
     * or lost its connection earlier in the call counts as failed at once; the pages a relay still
     * reading sent are kept, though it has not answered (tally()). Once the read goes on, the
     * requests it dropped end at once and ask nothing more: no page, no authentication, no query
     * once their connection opens, no random bytes drawn (ReadState).
     *
     * Of each event a relay sends, only the events $keep keeps (those that open, SPEC.md §12) are
     * kept, as they come; of the others, their ids and dates alone (paging): what a relay sends
     * never piles up, whatever its size (SPEC.md §13).
     *
     * @param list<string>              $relays
     * @param array<string, mixed>      $filter
     * @param \Closure(): string        $authSecret the key of this read, for NIP-42
     * @param \Closure(\stdClass): bool $keep       whether an event opens as a record of this read
     * @param array<string, list<\stdClass>>|null $answered set to the events of the relays whose
     *                                                     read ended, a majority or not (a record
     *                                                     of a newer format one of them sent is
     *                                                     never written over)
     *
     * @param-out array<string, list<\stdClass>> $answered
     *
     * @return array<string, list<\stdClass>> by relay
     *
     * @throws NetworkException when fewer than a majority answered
     */
    public function read(array $relays, array $filter, bool $paged, \Closure $authSecret, \Closure $keep, bool $everyRelay = false, ?array &$answered = null): array
    {
        $count = \count($relays);
        $majority = self::majority($count);
        $asked = $this->asked($relays, $everyRelay);
        $failed = $count - \count($asked);
        /** @var array<string, \ArrayObject<int, \stdClass>> $sent what each relay sent so far, page by page */
        $sent = [];
        $queue = new Queue();
        $read = new ReadState();
        foreach ($asked as $url) {
            $sent[$url] = $pages = new \ArrayObject();
            $this->spawn($queue, $url, fn(RelayConnection $relay): array => $paged
                ? $this->pages($relay, $filter, $authSecret, $keep, $pages, $read)
                : $relay->query($filter, $this->deadline(), $authSecret, $keep, read: $read));
        }
        $results = [];
        $failedUrls = [];
        $graceUntil = null;
        try {
            while (\count($results) + $failed < $count && $count - $failed >= $majority) {
                if (\count($results) >= $majority) {
                    if (!$everyRelay) {
                        break;
                    }
                    $graceUntil ??= $this->deadline();
                }
                $item = $this->next($queue, $graceUntil);
                if ($item === null) {
                    break; // The relays past a majority, waited for one timeout.
                }
                [$url, $ok, $value] = $item;
                if ($ok) {
                    \assert(\is_array($value));
                    $results[$url] = array_values($value);
                } else {
                    ++$failed;
                    $failedUrls[$url] = true;
                }
            }
        } finally {
            $read->end(); // The relays still reading are dropped: they ask nothing more.
        }
        $reads = [];
        foreach ($relays as $url) {
            $reads[] = isset($results[$url]) ? self::INCOMPLETE : (isset($sent[$url]) && !isset($failedUrls[$url]) ? self::READING : self::FAILED);
        }
        /** @var array<string, list<\stdClass>> $results */
        $answered = $results;
        if (!self::outcome($reads)['answered']) {
            throw new NetworkException('Fewer than a majority of the relays answered');
        }
        if ($everyRelay) {
            // Still reading: not answered, its pages kept (SPEC.md §13).
            foreach ($sent as $url => $pages) {
                if (!isset($results[$url]) && !isset($failedUrls[$url]) && \count($pages) > 0) {
                    $results[$url] = array_values($pages->getArrayCopy());
                }
            }
        }

        /** @var array<string, list<\stdClass>> $results */
        return $results;
    }

    /**
     * A complete read of $filter on every relay of $relays at once (SPEC.md §13, "Complete
     * reads"): on each relay, pages of COMPLETE_READ_LIMIT events, the next one from the oldest
     * second of the last, until a page is not full (CompleteRead). It goes on as soon as a
     * majority of the relays read completely (the others are dropped), or once every relay's read
     * ended or failed. With $everyRelay ("Reads on every relay": a lease's confirmation), once
     * every relay's read ended or failed, one relayTimeoutMs after a majority read completely, or
     * as soon as a majority can no longer; a relay that failed earlier in the call counts as
     * failed at once, and the pages a relay still reading sent are kept, though it has not
     * answered (tally()). Once the read goes on, the requests it dropped end at once and ask
     * nothing more (ReadState).
     *
     * $keep is handed each event as it comes (its fields as an event, its id and signature
     * unchecked) and the relay that sent it, and says whether the caller keeps it: the events it
     * refuses take no memory, and every relay's read pages by what its relay sent all the same
     * (SPEC.md §13: what a relay sends never piles up, and no answer is cut at a size).
     *
     * @param list<string>                      $relays
     * @param array<string, mixed>              $filter
     * @param \Closure(): string                $authSecret the key of this read, for NIP-42
     * @param \Closure(\stdClass, string): bool $keep
     *
     * @return array{answers: array<string, list<\stdClass>>, reads: list<self::COMPLETE|self::INCOMPLETE|self::FAILED|self::READING>, answered: int, complete: int}
     *         the events each relay that answered (on every relay, or was still reading) sent and
     *         the caller kept; each relay's read when the read went on, in the list's order (what
     *         the read gives: outcome()); how many relays answered (their read ended), how many
     *         read completely
     */
    public function readComplete(array $relays, array $filter, \Closure $authSecret, \Closure $keep, bool $everyRelay = false): array
    {
        $count = \count($relays);
        $majority = self::majority($count);
        $asked = $this->asked($relays, $everyRelay);
        $settled = $count - \count($asked);
        /** @var array<string, ReadProgress> $states each relay's read so far */
        $states = [];
        $queue = new Queue();
        $read = new ReadState();
        foreach ($asked as $url) {
            $states[$url] = $state = new ReadProgress();
            $this->spawn($queue, $url, fn(RelayConnection $relay): bool => $this->completePages($relay, $filter, $authSecret, $keep, $state, $read));
        }
        $answers = [];
        $complete = 0;
        /** @var array<string, self::COMPLETE|self::INCOMPLETE|self::FAILED> $ended each relay's read that ended */
        $ended = [];
        $graceUntil = null;
        try {
            while ($settled < $count) {
                if ($complete >= $majority) {
                    if (!$everyRelay) {
                        break;
                    }
                    $graceUntil ??= $this->deadline();
                } elseif ($everyRelay && $complete + ($count - $settled) < $majority) {
                    break; // A majority can no longer read completely: not complete.
                }
                $item = $this->next($queue, $graceUntil);
                if ($item === null) {
                    break;
                }
                [$url, $ok, $value] = $item;
                ++$settled;
                $ended[$url] = self::FAILED;
                if ($ok) {
                    $answers[$url] = $states[$url]->events;
                    $ended[$url] = $value === true ? self::COMPLETE : self::INCOMPLETE;
                    if ($value === true) {
                        ++$complete;
                    }
                }
            }
        } finally {
            $read->end(); // The relays still reading are dropped: they ask nothing more.
        }
        $reads = [];
        foreach ($relays as $url) {
            $reads[] = $ended[$url] ?? (isset($states[$url]) ? self::READING : self::FAILED);
        }
        if ($everyRelay) {
            // Still reading: not answered, its pages kept (SPEC.md §13).
            foreach ($states as $url => $state) {
                if (!isset($ended[$url]) && $state->answered) {
                    $answers[$url] = $state->events;
                }
            }
        }
        $tally = self::tally($reads);

        return ['answers' => $answers, 'reads' => $reads, 'answered' => $tally['answered'], 'complete' => $tally['complete']];
    }

    /**
     * Publishes $event on every relay of $relays at once: whether a majority took it. It returns
     * as soon as a majority took it, or can no longer; the others go on while the call runs.
     *
     * @param list<string>       $relays
     * @param \Closure(): string $authSecret the key of this write, for NIP-42
     */
    public function publish(array $relays, Event $event, \Closure $authSecret): bool
    {
        $count = \count($relays);
        $majority = self::majority($count);
        $queue = new Queue();
        foreach ($relays as $url) {
            $this->spawn($queue, $url, fn(RelayConnection $relay): bool => $relay->publish($event, $this->deadline(), $authSecret));
        }
        $took = 0;
        $answered = 0;
        while ($answered < $count) {
            $item = $this->next($queue, null);
            \assert($item !== null);
            ++$answered;
            if ($item[1] && $item[2] === true) {
                ++$took;
            }
            if ($took >= $majority || $count - ($answered - $took) < $majority) {
                break;
            }
        }

        return $took >= $majority;
    }

    /** The connection to $url of this call (opened at its first request), closed when the call ends. */
    public function connection(string $url): RelayConnection
    {
        if (!isset($this->connections[$url])) {
            $this->undefer ??= Loop::get()->defer($this->close(...));
            $this->connections[$url] = new RelayConnection($url, $this->context);
        }

        return $this->connections[$url];
    }

    /**
     * The relays of $relays to ask: on every relay, those that failed earlier in the call are not
     * waited for again.
     *
     * @param list<string> $relays
     *
     * @return list<string>
     */
    private function asked(array $relays, bool $everyRelay): array
    {
        return $everyRelay
            ? array_values(array_filter($relays, fn(string $url): bool => !$this->connection($url)->failedInCall))
            : $relays;
    }

    /**
     * Runs $request on the relay $url as a task: [$url, true, its value] or [$url, false, its
     * exception] into $queue.
     *
     * @param \Closure(RelayConnection): mixed $request
     */
    private function spawn(Queue $queue, string $url, \Closure $request): void
    {
        $connection = $this->connection($url);
        Loop::get()->spawn(static function () use ($queue, $request, $connection, $url): void {
            try {
                $queue->push([$url, true, $request($connection)]);
            } catch (CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $queue->push([$url, false, $e]);
            }
        });
    }

    /**
     * The next outcome of $queue: [url, ok, value]; null once $until (Loop::now()) passed.
     *
     * @return array{0: string, 1: bool, 2: mixed}|null
     */
    private function next(Queue $queue, ?float $until): ?array
    {
        try {
            $item = $queue->pop($until);
        } catch (TimeoutException) {
            return null;
        }
        \assert(\is_array($item) && \is_string($item[0]) && \is_bool($item[1]));

        return [$item[0], $item[1], $item[2]];
    }

    /**
     * A paged read on one relay (SPEC.md §13, "Paged reads", PagedRead): 20 pages at most, each
     * event once per id, each page until the oldest second of the last, by what the relay sent of
     * each event. A failure after the first page keeps what was read. $sent takes each event the
     * read keeps as its page comes: what it sent so far, should the read be cut. Once $read is
     * over, no further page.
     *
     * @param array<string, mixed>          $filter
     * @param \Closure(): string            $authSecret
     * @param \Closure(\stdClass): bool     $keep
     * @param \ArrayObject<int, \stdClass> $sent
     *
     * @return list<\stdClass>
     */
    private function pages(RelayConnection $relay, array $filter, \Closure $authSecret, \Closure $keep, \ArrayObject $sent, ReadState $read): array
    {
        PagedRead::read(function (?int $until) use ($relay, $filter, $authSecret, $keep, $read): array {
            $relay->query($until === null ? $filter : ['until' => $until] + $filter, $this->deadline(), $authSecret, $keep, $entries, $read);

            return $entries;
        }, $sent, static fn(): bool => $read->over);

        return array_values($sent->getArrayCopy());
    }

    /**
     * A complete read on one relay (SPEC.md §13, "Complete reads", CompleteRead::read()): whether
     * it read completely. Its events, once per id, go into $progress->events as each page comes
     * ($keep refusing those the caller does not keep); $progress->answered once its first page
     * came. Throws when its first page fails (the relay failed); a later failure ends the read
     * incomplete, as does $read being over (no further page).
     *
     * @param array<string, mixed>              $filter
     * @param \Closure(): string                $authSecret
     * @param \Closure(\stdClass, string): bool $keep
     */
    private function completePages(RelayConnection $relay, array $filter, \Closure $authSecret, \Closure $keep, ReadProgress $progress, ReadState $read): bool
    {
        $url = $relay->url;
        $keepOf = static fn(\stdClass $event): bool => $keep($event, $url);

        return CompleteRead::read(function (?int $until) use ($relay, $filter, $authSecret, $keepOf, $read): array {
            $pageFilter = ['limit' => Protocol::COMPLETE_READ_LIMIT] + $filter;
            if ($until !== null) {
                $pageFilter = ['until' => $until] + $pageFilter;
            }
            $relay->query($pageFilter, $this->deadline(), $authSecret, $keepOf, $entries, $read);

            return $entries;
        }, $progress, static fn(): bool => $read->over);
    }

    private function deadline(): float
    {
        return Loop::now() + $this->context->relayTimeoutMs / 1000;
    }
}
