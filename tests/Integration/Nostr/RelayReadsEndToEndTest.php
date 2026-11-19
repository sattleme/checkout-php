<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Nostr;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\Nostr\Relays;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * relay-reads.json end to end (PORTING.md, steps 4 and 5): its `lists` and `onEveryRelay` cases,
 * each relay's read made by a local relay and told apart by RelayPool itself, through a complete
 * read of the records at a lease's address (step 3 of "Leasing an address"; on every relay, a
 * lease's confirmation) and what the read gives (RelayPool::outcome(), as Leasing reads it).
 * RelayReadsVectorsTest runs the same cases through outcome() alone.
 *
 * What each relay of a case does, by its state:
 * - complete: holds a few events at the address (one page, not full); or 250, at distinct seconds
 *   (it pages: 100, 100, then 50); or caps its answers at 40 events (a page that is not full:
 *   complete, whatever it leaves out, SPEC.md §13 relies on relays that honor the limit).
 * - incomplete: holds 150 events of one second (a full page, then the same page again: no event
 *   it had not sent); or 2 100 at distinct seconds (a 20th page that is full); or never answers
 *   its second page (that page runs out of time).
 * - failed: refuses connections (nothing listens); or accepts them and never answers (mute: its
 *   first page runs out of time).
 * - reading: a relay still reading when the read goes on: one that holds 2 100 events at distinct
 *   seconds and answers each page a quarter of the timeout late (its read would last five
 *   timeouts); or a mute one, when the read goes on before its first page runs out of time.
 */
final class RelayReadsEndToEndTest extends TestCase
{
    /** relayTimeoutMs of these reads: each page, the connection included (scaled, as every time here: TestTime). */
    private const TIMEOUT_MS = 2000;

    /** How late a relay that reads completely answers each page, when the read must go on with every other relay's read ended by then. */
    private const LATE_COMPLETE_MS = 500;

    private const NOW = 1791378000;

    private const D = 'a6d9a0f4b0b5e4e0c3a2b9f1d6e7c8b9a0f1e2d3c4b5a69788796a5b4c3d2e1f';

    private const MAKERS = ['few', 'pages', 'caps', 'oneSecond', 'twentyPages', 'stallsAtPage2', 'refusesConnections', 'mute', 'slowPager'];

    /** @var array<string, int> how many relays each choice of makers made so far: its makers take turns */
    private static array $turns = [];

    private static int $serial = 0;

    private ?Relays $relays = null;

    protected function tearDown(): void
    {
        $this->relays?->stop();
        $this->relays = null;
    }

    /** @return iterable<string, array{0: list<string>, 1: bool}> */
    public static function lists(): iterable
    {
        foreach (V::named(V::l(V::object('vectors/relay-reads.json')['lists'])) as $name => [$case]) {
            /** @var list<string> $relays */
            $relays = V::l($case['relays']);
            yield $name => [$relays, $case['expected'] === true];
        }
    }

    /**
     * A complete read of a list: complete when a majority read completely. Complete, the read goes
     * on as soon as a majority read completely (the others dropped): so that each other relay's
     * read has ended as the case says by then, they end at once and those that read completely
     * answer late. Not complete, the read waits for every relay.
     *
     * @param list<string> $states
     */
    #[DataProvider('lists')]
    public function testACompleteReadOfAList(array $states, bool $expected): void
    {
        [$outcome] = $this->read(self::listMakers($states, $expected, self::$turns), false);
        self::assertSame($expected, $outcome['complete']);
    }

    /** @return iterable<string, array{0: list<string>, 1: array<string, bool>}> */
    public static function onEveryRelay(): iterable
    {
        foreach (V::named(V::l(V::object('vectors/relay-reads.json')['onEveryRelay'])) as $name => [$case]) {
            /** @var list<string> $relays */
            $relays = V::l($case['relays']);
            /** @var array<string, bool> $expected */
            $expected = V::a($case['expected']);
            yield $name => [$relays, $expected];
        }
    }

    /**
     * A complete read on every relay (a lease's confirmation): what it gives, the relays of the
     * case as the pool tells them apart when the read goes on. It goes on once no relay is still
     * reading ("settled"), a timeout after a majority read completely ("grace"), or as soon as a
     * majority can no longer read completely ("at once"); otherwise it waits for the relays still
     * reading until each ends or fails, and those are made mute: they fail once their first page
     * runs out of time, and the read gives what the case says (a relay still reading answered no
     * more than one that failed).
     *
     * @param list<string>        $states
     * @param array<string, bool> $expected
     */
    #[DataProvider('onEveryRelay')]
    public function testACompleteReadOnEveryRelay(array $states, array $expected): void
    {
        $when = self::goesOnWith($states);
        [$outcome, $seconds, $made] = $this->read(self::everyRelayMakers($states, self::$turns), true);
        self::assertSame($expected, $outcome);
        if ($when === 'grace') {
            // The relays still reading were waited for a timeout after the majority, not until they end.
            self::assertGreaterThanOrEqual(TestTime::seconds(self::TIMEOUT_MS / 1000 - 0.05), $seconds);
            foreach ($states as $index => $state) {
                if ($state === 'reading') {
                    $relay = $made[$index];
                    self::assertNotNull($relay);
                    self::assertLessThan(Protocol::MAX_PAGES, \count($relay->queries), 'still reading');
                }
            }
        } elseif ($when === 'at once') {
            // Before a mute relay's first page could run out of time: it was still reading.
            self::assertLessThan(TestTime::seconds(self::TIMEOUT_MS / 1000 * 0.75), $seconds);
        } elseif ($when === null) {
            self::assertGreaterThanOrEqual(TestTime::seconds(self::TIMEOUT_MS / 1000 - 0.05), $seconds, 'waited for the relays still reading');
        }
    }

    public function testTheCasesHoldEveryKindOfReadAndOfRelay(): void
    {
        // Every way a read on every relay goes on, and every local relay, each state made more
        // than one way.
        $kinds = [];
        $turns = [];
        $used = [];
        foreach (self::onEveryRelay() as [$states]) {
            $kinds[] = self::goesOnWith($states) ?? 'waited';
            $used = [...$used, ...array_column(self::everyRelayMakers($states, $turns), 0)];
        }
        foreach (self::lists() as [$states, $expected]) {
            $used = [...$used, ...array_column(self::listMakers($states, $expected, $turns), 0)];
        }
        foreach (['settled', 'grace', 'at once', 'waited'] as $kind) {
            self::assertContains($kind, $kinds);
        }
        $used = array_values(array_unique($used));
        sort($used);
        $makers = self::MAKERS;
        sort($makers);
        self::assertSame($makers, $used);
    }

    /**
     * The makers of a case of `lists`, each with how late it answers each page.
     *
     * @param list<string>       $states
     * @param array<string, int> $turns
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function listMakers(array $states, bool $expected, array &$turns): array
    {
        $makers = [];
        foreach ($states as $state) {
            $makers[] = match ($state) {
                'complete' => [self::next($turns, 'few', 'pages', 'caps'), $expected ? self::LATE_COMPLETE_MS : 0],
                'incomplete' => [$expected ? self::next($turns, 'oneSecond') : self::next($turns, 'oneSecond', 'stallsAtPage2', 'twentyPages'), 0],
                default => [$expected ? self::next($turns, 'refusesConnections') : self::next($turns, 'mute', 'refusesConnections'), 0],
            };
        }

        return $makers;
    }

    /**
     * The makers of a case of `onEveryRelay`: a relay that is not still reading has ended or failed
     * when the read goes on.
     *
     * @param list<string>       $states
     * @param array<string, int> $turns
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function everyRelayMakers(array $states, array &$turns): array
    {
        $when = self::goesOnWith($states);
        $makers = [];
        foreach ($states as $state) {
            $makers[] = match ($state) {
                'complete' => [self::next($turns, 'few', 'pages', 'caps'), 0],
                'incomplete' => [$when === 'settled' ? self::next($turns, 'oneSecond', 'stallsAtPage2', 'twentyPages') : self::next($turns, 'oneSecond', 'twentyPages'), 0],
                'failed' => [$when === 'settled' ? self::next($turns, 'refusesConnections', 'mute') : self::next($turns, 'refusesConnections'), 0],
                default => [$when === 'grace' ? self::next($turns, 'slowPager') : self::next($turns, 'mute'), 0],
            };
        }

        return $makers;
    }

    /**
     * When RelayPool goes on with a complete read on every relay while these are its relays'
     * states (SPEC.md §13): once none is still reading; with some still reading, a timeout after a
     * majority read completely, or as soon as a majority can no longer read completely; else
     * (null) it waits for the relays still reading, until each ends or fails.
     *
     * @param list<string> $states
     */
    private static function goesOnWith(array $states): ?string
    {
        $count = static fn(string $state): int => \count(array_filter($states, static fn(string $s): bool => $s === $state));
        $needed = RelayPool::majority(\count($states));

        return match (true) {
            $count('reading') === 0 => 'settled',
            $count('complete') >= $needed => 'grace',
            $count('complete') + $count('reading') < $needed => 'at once',
            default => null,
        };
    }

    /**
     * The next of $makers, in turn: the relays of one state vary from case to case.
     *
     * @param array<string, int> $turns
     */
    private static function next(array &$turns, string ...$makers): string
    {
        $key = implode(',', $makers);
        $turn = $turns[$key] ?? 0;
        $turns[$key] = $turn + 1;

        return $makers[$turn % \count($makers)];
    }

    /**
     * Makes one local relay per maker, reads the records at the address on them (on every relay
     * or not) as Leasing does: what the read gave, how long it took, the relays made (null: nothing
     * listens there).
     *
     * @param list<array{0: string, 1: int}> $makers each maker, and how late it answers each page
     *
     * @return array{0: array{answered: bool, complete: bool}, 1: float, 2: list<LocalRelay|null>}
     */
    private function read(array $makers, bool $everyRelay): array
    {
        $this->relays = new Relays(new TestConnector());
        $urls = [];
        $made = [];
        foreach ($makers as $index => [$maker, $delayMs]) {
            $urls[] = $url = 'wss://' . strtolower($maker) . "-{$index}.example";
            $made[] = $this->make($maker, $url, $delayMs);
        }
        $context = TestContext::create($this->relays->connector, self::NOW, null, self::TIMEOUT_MS);
        $started = Loop::now();
        $read = Loop::get()->run(static fn(): array => (new RelayPool($context))->readComplete(
            $urls,
            ['kinds' => [Protocol::RECORD_KIND], '#d' => [self::D]],
            static fn(): string => str_repeat("\x11", 32),
            static fn(\stdClass $event, string $url): bool => false, // None opens as a lease: they page the read all the same.
            $everyRelay,
        ));


        return [RelayPool::outcome($read['reads']), Loop::now() - $started, $made];
    }

    private function make(string $maker, string $url, int $delayMs): ?LocalRelay
    {
        \assert($this->relays !== null);
        if ($maker === 'refusesConnections') {
            return null; // Nothing listens there.
        }
        $relay = $this->relays->add($url);
        $relay->queryDelayMs = $delayMs;
        match ($maker) {
            'few' => $this->hold($relay, 3),
            'pages' => $this->hold($relay, 250),
            'caps' => $this->hold($relay, 250, false, 40),
            'oneSecond' => $this->hold($relay, 150, true),
            'twentyPages' => $this->hold($relay, 2100),
            'stallsAtPage2' => $this->stallsAtPage2($relay),
            'mute' => $relay->mute = true,
            'slowPager' => $this->hold($relay, 2100, false, null, intdiv(self::TIMEOUT_MS, 4)),
            default => throw new \LogicException("No maker {$maker}"),
        };

        return $relay;
    }

    /** $count events at the address, anybody's, the newest at NOW; of one second each, or all of NOW. */
    private function hold(LocalRelay $relay, int $count, bool $oneSecond = false, ?int $cap = null, int $delayMs = 0): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $relay->events[] = (object) [
                'id' => str_pad(dechex(self::$serial++), 64, '0', \STR_PAD_LEFT),
                'pubkey' => str_repeat('ab', 32),
                'created_at' => $oneSecond ? self::NOW : self::NOW - $i,
                'kind' => Protocol::RECORD_KIND,
                'tags' => [['d', self::D]],
                'content' => '',
                'sig' => str_repeat('00', 64),
            ];
        }
        $relay->cap = $cap;
        if ($delayMs > 0) {
            $relay->queryDelayMs = $delayMs;
        }
    }

    /** 250 events; its second page never answered: it runs out of time. */
    private function stallsAtPage2(LocalRelay $relay): void
    {
        $this->hold($relay, 250);
        $relay->onQuery = static function (\stdClass $filter, LocalRelay $relay): void {
            $relay->queryDelayMs = isset($filter->until) ? 60000 : 0;
        };
    }
}
