<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * nostr-events.json (SPEC.md §11): an event's id, as the SDK checks the id of an event a relay
 * sent (Event::fromJson(), then idMatches(), which hashes serialization()): its numbers written as
 * integers whatever their spelling in the vector, its strings as JavaScript's JSON.stringify
 * writes them.
 */
final class NostrEventsVectorsTest extends TestCase
{
    /** @return iterable<string, array{0: array<string, mixed>, 1: int}> */
    public static function cases(): iterable
    {
        yield from V::indexed('vectors/nostr-events.json', ['cases']);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testAnEventsId(array $case, int $index): void
    {
        // The event as a relay would send it, with its id and a signature (not checked here):
        // its members as the SDK's JSON reader reads the vector's text.
        $value = V::json('vectors/nostr-events.json', ['cases', $index, 'event']);
        self::assertInstanceOf(\stdClass::class, $value);
        $value->id = V::s($case['id']);
        $value->sig = str_repeat('00', 64);
        $event = Event::fromJson($value);
        self::assertNotNull($event, 'an event');
        self::assertSame($case['serialized'], $event->serialization());
        self::assertSame($case['id'], $event->computedId());
        self::assertTrue($event->idMatches());
    }
}
