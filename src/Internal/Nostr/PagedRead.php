<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Internal\Protocol;

/**
 * One relay's paged read (SPEC.md §13, "Paged reads": a sale's declarations): the first page with
 * the filter, then the next one until the oldest second of the last, page after page, on the raw
 * `id` and `created_at` of each event the relay sent, whatever else it holds.
 *
 * @internal
 */
final class PagedRead
{
    private function __construct() {}

    /**
     * Reads pages from $page, which asks the relay for the page until a second (null: the first
     * page, without `until`) and gives what it sent, each event as an entry (RelayConnection::
     * entryOf()). Until a page holds no event with a string `id` and a safe integer `created_at`,
     * the MAX_PAGES-th page, or $over says the read went on without this relay. Each event the
     * read keeps goes into $kept, once per id, as its page comes. A failure of the first page is
     * the relay's (thrown); a later one ends the read with what it read.
     *
     * @param \Closure(?int): list<array{0: string|null, 1: int|null, 2: \stdClass|null}> $page
     * @param \ArrayObject<int, \stdClass>                                                  $kept
     * @param (\Closure(): bool)|null                                                        $over
     */
    public static function read(\Closure $page, \ArrayObject $kept, ?\Closure $over = null): void
    {
        $seen = [];
        $until = null;
        for ($number = 1; ; ++$number) {
            try {
                $entries = $page($until);
            } catch (CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($number === 1) {
                    throw $e;
                }

                return;
            }
            $until = self::afterPage($entries, $seen, $kept);
            if ($until === null || $number >= Protocol::MAX_PAGES || ($over !== null && $over())) {
                return;
            }
        }
    }

    /**
     * After a page: the `until` of the next one, or null when the read ends there. Only an
     * entry's string `id` and safe integer `created_at` page the read (one whose event does not
     * check out pages it all the same); an entry without both is neither new nor kept. The next
     * page is asked until the oldest `created_at` of those entries, one second earlier when none
     * of them was new to the read. $seen holds the ids of the read so far: the page's new ones are
     * added to it, and the events kept of them to $kept.
     *
     * @param list<array{0: string|null, 1: int|null, 2: \stdClass|null}> $entries
     * @param array<string, true>                                          $seen
     * @param \ArrayObject<int, \stdClass>                                 $kept
     */
    public static function afterPage(array $entries, array &$seen, \ArrayObject $kept): ?int
    {
        $oldest = null;
        $fresh = false;
        foreach ($entries as [$id, $createdAt, $event]) {
            if ($id === null || $createdAt === null) {
                continue;
            }
            $oldest = $oldest === null ? $createdAt : min($oldest, $createdAt);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $fresh = true;
            if ($event !== null) {
                $kept[] = $event;
            }
        }
        if ($oldest === null) {
            return null;
        }

        // Nothing new: the oldest second again would bring the same page.
        return $fresh ? $oldest : $oldest - 1;
    }
}
