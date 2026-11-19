<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Internal\Protocol;

/**
 * One page of a complete read (SPEC.md §13, "Complete reads"): the leases of deposit addresses,
 * which anybody may write at, are read with a `limit` page after page, so that such a read never
 * stops short unnoticed.
 *
 * @internal
 */
final class CompleteRead
{
    private function __construct() {}

    /**
     * One relay's complete read: whether it read completely. $page asks the relay for the page
     * until a second (null: the first page) and gives what it sent, each event as an entry
     * (RelayConnection::entryOf()); afterPage() says what comes next. Each event the read keeps
     * goes into $progress->events, once per id, as its page comes; $progress->answered once the
     * first page came. A failure of the first page is the relay's (thrown); a later one ends the
     * read incomplete, as does $over saying that the read went on without this relay (no further
     * page).
     *
     * @param \Closure(?int): list<array{0: string|null, 1: int|null, 2: \stdClass|null}> $page
     * @param (\Closure(): bool)|null                                                        $over
     */
    public static function read(\Closure $page, ReadProgress $progress, ?\Closure $over = null): bool
    {
        $seen = [];
        $kept = [];
        $until = null;
        for ($number = 1; ; ++$number) {
            if ($over !== null && $over()) {
                return false;
            }
            try {
                $entries = $page($until);
            } catch (CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($number === 1) {
                    throw $e;
                }

                return false;
            }
            $progress->answered = true;
            foreach ($entries as [$id, , $event]) {
                if ($id !== null && $event !== null && !isset($kept[$id])) {
                    $kept[$id] = true;
                    $progress->events[] = $event;
                }
            }
            $next = self::afterPage($entries, $seen, $number);
            if (isset($next['complete'])) {
                return $next['complete'];
            }
            $until = $next['until'];
        }
    }

    /**
     * After the $pagesRead-th page of one relay, from its values as the relay sent them (each as
     * RelayConnection::entryOf() gives it: its `id` when a string, its `created_at` when a safe
     * integer): ['until' => the oldest `created_at` of the page's events with both] for the next
     * page, or ['complete' => whether the relay's read is complete]. $seen holds the ids of the
     * read so far; the page's new ones are added to it.
     *
     * Complete at a page that is not full (it holds fewer than COMPLETE_READ_LIMIT values).
     * Incomplete at a page that holds more (the relay ignored the limit), or that is full and holds
     * no event with both, or no event with an `id` not seen before in this read, dated or not (one
     * second holds more events than a page), and at a full page that is the MAX_PAGES-th.
     *
     * @param list<array{0: string|null, 1: int|null, 2?: \stdClass|null}> $entries
     * @param array<string, true>                       $seen
     *
     * @return array{until: int}|array{complete: bool}
     */
    public static function afterPage(array $entries, array &$seen, int $pagesRead): array
    {
        $oldest = null;
        $fresh = false;
        foreach ($entries as [$id, $createdAt]) {
            if ($id === null) {
                continue; // No event of the read.
            }
            if ($createdAt !== null) {
                $oldest = $oldest === null ? $createdAt : min($oldest, $createdAt);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $fresh = true; // New, dated or not (SPEC.md §13).
        }
        $count = \count($entries);
        if ($count > Protocol::COMPLETE_READ_LIMIT) {
            return ['complete' => false]; // The limit ignored: what it left out cannot be told.
        }
        if ($count < Protocol::COMPLETE_READ_LIMIT) {
            return ['complete' => true];
        }
        if ($oldest === null || !$fresh || $pagesRead >= Protocol::MAX_PAGES) {
            return ['complete' => false];
        }

        return ['until' => $oldest];
    }
}
