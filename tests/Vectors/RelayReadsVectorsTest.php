<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Nostr\CompleteRead;
use Sattleme\Checkout\Internal\Nostr\PagedRead;
use Sattleme\Checkout\Internal\Nostr\ReadProgress;
use Sattleme\Checkout\Internal\Nostr\RelayConnection;
use Sattleme\Checkout\Internal\Nostr\RelayException;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * Relay reads (SPEC.md §13): of a complete read and of a paged read, the pages one relay's read
 * asks, whether it answered (and read completely), what it keeps; when a complete read over a
 * list of relays is complete; and of a read on every relay, whether it answered and is complete.
 */
final class RelayReadsVectorsTest extends TestCase
{
    public function testThePageLimitAndThePagesOfARead(): void
    {
        $vectors = V::object('vectors/relay-reads.json');
        self::assertSame([V::i($vectors['limit']), V::i($vectors['pages'])], [Protocol::COMPLETE_READ_LIMIT, Protocol::MAX_PAGES]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        yield from V::named(V::l(V::object('vectors/relay-reads.json')['cases']));
    }

    /**
     * What RelayPool reads of one relay: CompleteRead::read() over the pages, each event as
     * RelayConnection hands it (entryOf(), the event kept: whether it opens does not matter here),
     * a page that fails as a request that fails: the first one, the relay's failure.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testARelaysRead(array $case): void
    {
        $pages = V::l($case['pages']);
        $untils = [];
        $page = static function (?int $until) use ($pages, &$untils): array {
            $answer = $pages[\count($untils)] ?? throw new \RuntimeException('The vector ends before the read');
            $untils[] = $until;
            if ($answer === 'fails') {
                throw new RelayException('The page fails');
            }

            return self::entries($answer, true);
        };
        $progress = new ReadProgress();
        try {
            $complete = CompleteRead::read($page, $progress);
            $answered = true;
        } catch (RelayException) {
            [$complete, $answered] = [false, false];
        }
        self::assertSame($answered, $progress->answered, 'its first page came');
        self::assertSame(V::a($case['expected']), ['untils' => $untils, 'answered' => $answered, 'complete' => $complete, 'kept' => \count($progress->events)]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function lists(): iterable
    {
        yield from V::named(V::l(V::object('vectors/relay-reads.json')['lists']));
    }

    /**
     * Whether a complete read of a list is complete, from each relay's read, as RelayPool gives it
     * (outcome()).
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('lists')]
    public function testAList(array $case): void
    {
        self::assertSame($case['expected'], RelayPool::outcome(self::reads(V::l($case['relays'])))['complete']);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function paged(): iterable
    {
        yield from V::named(V::l(V::object('vectors/relay-reads.json')['paged']));
    }

    /**
     * One relay's paged read (a sale's declarations), as RelayPool reads it: PagedRead over the
     * pages, each event as RelayConnection hands it (entryOf(), the event kept: whether it checks
     * out does not matter here), a page that fails as a request that fails.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('paged')]
    public function testAPagedRead(array $case): void
    {
        $pages = V::l($case['pages']);
        $untils = [];
        $page = static function (?int $until) use ($pages, &$untils): array {
            $answer = $pages[\count($untils)] ?? throw new \RuntimeException('The vector ends before the read');
            $untils[] = $until;
            if ($answer === 'fails') {
                throw new RelayException('The page fails');
            }

            return self::entries($answer, true);
        };
        /** @var \ArrayObject<int, \stdClass> $kept */
        $kept = new \ArrayObject();
        try {
            PagedRead::read($page, $kept);
            $answered = true;
        } catch (RelayException) {
            $answered = false;
        }
        self::assertSame(V::a($case['expected']), ['untils' => $untils, 'answered' => $answered, 'kept' => \count($kept)]);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function onEveryRelay(): iterable
    {
        yield from V::named(V::l(V::object('vectors/relay-reads.json')['onEveryRelay']));
    }

    /**
     * A read on every relay of a list, from each relay's read when the read went on, as RelayPool
     * gives it (outcome()): whether it answered (else the read is network), whether it is
     * complete. Only a relay whose read ended answered, never one still reading.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('onEveryRelay')]
    public function testAReadOnEveryRelay(array $case): void
    {
        self::assertSame($case['expected'], RelayPool::outcome(self::reads(V::l($case['relays']))));
    }

    /**
     * Each relay's read of a vector as RelayPool names it.
     *
     * @param list<mixed> $relays
     *
     * @return list<RelayPool::COMPLETE|RelayPool::INCOMPLETE|RelayPool::FAILED|RelayPool::READING>
     */
    private static function reads(array $relays): array
    {
        return array_map(static fn(mixed $read): string => match ($read) {
            'complete' => RelayPool::COMPLETE,
            'incomplete' => RelayPool::INCOMPLETE,
            'failed' => RelayPool::FAILED,
            'reading' => RelayPool::READING,
            default => throw new \RuntimeException('Not a read of the vector'),
        }, $relays);
    }

    /**
     * A page of the vector, each `[id, created_at]` as an event a relay sent: its entry as
     * RelayConnection gives it, the event itself kept when $kept.
     *
     * @return list<array{0: string|null, 1: int|null, 2: \stdClass|null}>
     */
    private static function entries(mixed $page, bool $kept = false): array
    {
        return array_map(static function (mixed $pair) use ($kept): array {
            [$id, $createdAt] = V::l($pair);
            $event = (object) ['id' => $id, 'created_at' => $createdAt];

            return RelayConnection::entryOf($event, $kept ? $event : null);
        }, V::l($page));
    }
}
