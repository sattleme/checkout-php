<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;

/**
 * The newest valid copy of a record among what relays sent (SPEC.md §14, "Lookup on a list"):
 * each copy opened once per event id that checked out; the newest wins (the highest created_at,
 * then the lowest id, as relays keep it).
 *
 * @internal
 */
final class Records
{
    /** @var array{0: Event, 1: mixed}|null */
    public ?array $newest = null;

    /** created_at of the newest copy of a newer format, null when none was seen. */
    public ?int $newestNewer = null;

    /**
     * @param array<string, list<mixed>>                              $answers by relay
     * @param \Closure(mixed): ?SealedRecord                          $open    a copy as a sealed record
     * @param \Closure(mixed, Event): (array{0: 'ok', 1: mixed}|array{0: 'newer'}|null) $validate a value: valid, newer or invalid
     */
    public static function newest(array $answers, \Closure $open, \Closure $validate): self
    {
        $records = new self();
        $checked = [];
        foreach ($answers as $events) {
            foreach ($events as $value) {
                $id = Json::get($value, 'id');
                if (\is_string($id) && isset($checked[$id])) {
                    continue;
                }
                $record = $open($value);
                if ($record === null) {
                    continue;
                }
                $checked[$record->event->id] = true;
                $event = $record->event;
                $verdict = $record->status === SealedRecord::NEWER ? ['newer'] : $validate($record->value, $event);
                if ($verdict === null) {
                    continue;
                }
                if ($verdict[0] === 'newer') {
                    $records->newestNewer = max($records->newestNewer ?? -1, $event->createdAt);
                    continue;
                }
                if ($records->newest === null || $event->replaces($records->newest[0])) {
                    $records->newest = [$event, $verdict[1]];
                }
            }
        }

        return $records;
    }
}
