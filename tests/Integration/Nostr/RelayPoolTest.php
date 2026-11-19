<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Nostr;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Tests\Support\Net\LocalServer;
use Sattleme\Checkout\Tests\Support\Net\ServerWebSocket;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\Nostr\Relays;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;

/** SPEC.md §13 against local relays: majorities, paging, NIP-42, mute and late relays. */
final class RelayPoolTest extends TestCase
{
    private Relays $relays;

    private string $secret;

    protected function setUp(): void
    {
        $this->relays = new Relays(new TestConnector());
        $this->secret = str_repeat("\x11", 32);
    }

    protected function tearDown(): void
    {
        $this->relays->stop();
    }

    public function testAReadGoesOnAtAMajorityAndAMuteRelaySlowsNothing(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $this->relays->get('wss://c.example')->mute = true;
        $event = $this->event(1700000000, 'x');
        $this->relays->get('wss://a.example')->store($event);
        $start = Loop::now();
        $answers = $this->call(function (RelayPool $pool) use ($urls): array {
            $answers = $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...));
            Loop::get()->sleepUntil(TestTime::deadline(0.05)); // for the relay to read the CLOSE

            return $answers;
        }, 5000);
        self::assertLessThan(TestTime::seconds(2), Loop::now() - $start, 'the mute relay was not awaited');
        $answered = array_keys($answers);
        sort($answered);
        self::assertSame(['wss://a.example', 'wss://b.example'], $answered);
        self::assertCount(1, $answers['wss://a.example']);
        self::assertCount(0, $answers['wss://b.example']);
        // Each query closed after its EOSE, with an id of 16 hex characters.
        self::assertCount(1, $this->relays->get('wss://a.example')->closed);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $this->relays->get('wss://a.example')->closed[0]);
    }

    public function testFewerThanAMajorityIsNoAnswer(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        $this->relays->add('wss://a.example');
        $this->relays->add('wss://b.example')->mute = true;
        // c.example: no server at all.
        $this->expectException(NetworkException::class);
        $this->call(fn(RelayPool $pool): array => $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...)), 300);
    }

    public function testAPagedReadPagesOnEachEventsRawIdAndDate(): void
    {
        // SPEC.md §13, "Paged reads": an event's string `id` and safe integer `created_at` page the
        // read, whatever else it holds (one that does not check out pages it all the same); an
        // event without them neither pages it nor is kept, nor is one that is not an event.
        $relay = $this->relays->add('wss://a.example');
        $kept = $this->event(1700000100, 'x');
        $relay->store($kept);
        $relay->onQuery = static function (\stdClass $filter, LocalRelay $relay): void {
            $until = $filter->until ?? null;
            $relay->extraEvents = static fn(): array => match ($until) {
                // A bare id and date, older than the event; an id that is not a string, older still.
                null => ['{"id":"zz","created_at":1700000000}', '{"id":7,"created_at":1600000000}'],
                // Of the second page: one more, its date in a string too.
                1700000000 => ['{"id":"yy","created_at":1690000000,"kind":30078}', '{"id":"ww","created_at":"1680000000"}'],
                default => [],
            };
        };
        $answers = $this->call(fn(RelayPool $pool): array => $pool->read(['wss://a.example'], ['kinds' => [30078]], true, fn(): string => $this->secret, self::every(...)));
        self::assertSame([null, 1700000000, 1690000000], array_map(static fn(\stdClass $filter): mixed => $filter->until ?? null, $relay->queries));
        self::assertSame([$kept->id], array_map(static fn(\stdClass $event): mixed => $event->id, $answers['wss://a.example']));
    }

    public function testTheRelaysThatAnsweredAReadOfFewerThanAMajorityAreTold(): void
    {
        // a.example answers; b.example closes the query; c.example answers late, its request
        // running out of time: fewer than a majority answered (NetworkException), and what the
        // relays that did answer sent is told all the same (a record of a newer format one of them
        // sent is never written over, SPEC.md §12).
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $event = $this->event(1700000000, 'x');
        $this->relays->get('wss://a.example')->store($event);
        $this->relays->get('wss://b.example')->closeQueries = 'error: no';
        $this->relays->get('wss://c.example')->queryDelayMs = 60000;
        $answered = null;
        $failed = false;
        $this->call(function (RelayPool $pool) use ($urls, &$answered, &$failed): void {
            try {
                $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...), false, $answered);
            } catch (NetworkException) {
                $failed = true;
            }
        }, 1000);
        self::assertTrue($failed);
        self::assertIsArray($answered);
        self::assertSame(['wss://a.example'], array_keys($answered));
        self::assertSame([$event->id], array_map(static fn(\stdClass $e): mixed => $e->id, $answered['wss://a.example']));
    }

    public function testPagedReadsGoPastARelaysCap(): void
    {
        $relay = $this->relays->add('wss://a.example');
        $relay->cap = 3;
        for ($i = 0; $i < 10; ++$i) {
            // Pairs of events at one second: a page may end in the middle of a second.
            $relay->store($this->event(1700000000 + intdiv($i, 2), "e{$i}"));
        }
        $answers = $this->call(fn(RelayPool $pool): array => $pool->read(['wss://a.example'], ['kinds' => [30078]], true, fn(): string => $this->secret, self::every(...)));
        self::assertCount(10, $answers['wss://a.example']);
        $untils = array_map(static fn(\stdClass $q): mixed => $q->until ?? null, $relay->queries);
        self::assertNull($untils[0]);
        self::assertGreaterThan(3, \count($untils));
    }

    public function testAuthenticationWhenARelayAsksForIt(): void
    {
        $relay = $this->relays->add('wss://a.example');
        $relay->requireAuth = true;
        $relay->store($this->event(1700000000, 'x'));
        $event = $this->event(1700000001, 'y');
        [$answers, $took] = $this->call(fn(RelayPool $pool): array => [
            $pool->read(['wss://a.example'], ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...)),
            $pool->publish(['wss://a.example'], $event, fn(): string => $this->secret),
        ]);
        self::assertCount(1, $answers['wss://a.example']);
        self::assertTrue($took);
        // One authentication for one key and one challenge on one connection.
        self::assertCount(1, $relay->authenticated);
        self::assertSame(1, $relay->server->accepted);
        // NIP-42 (SPEC.md §13): ["AUTH", <event>], never an EVENT, then the query asked again; its
        // event of kind 22242, of the relay and its challenge, content "", signed by the read's key.
        self::assertSame(['REQ', 'AUTH', 'REQ', 'CLOSE', 'EVENT'], $relay->messages);
        self::assertCount(1, $relay->authentications);
        $authentication = Event::fromJson($relay->authentications[0]);
        self::assertNotNull($authentication);
        self::assertTrue($authentication->verify());
        self::assertSame([22242, bin2hex(Secp256k1::xOnlyPublicKey($this->secret)), 'wss://a.example', ''], [$authentication->kind, $authentication->pubkey, $authentication->tag('relay'), $authentication->content]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', (string) $authentication->tag('challenge'));
    }

    public function testAPublicationsRelaysPastItsMajorityEndWithItsCall(): void
    {
        // SPEC.md §13: a publication's relays past its majority may finish their own request after
        // the call returned; on its one thread, this SDK closes them when the call returns (§4:
        // nothing else a call started runs on). c takes the event a second after a and b: the
        // call returns at the majority, no task of it is left, c's connection is closed, and
        // nothing more is sent, nor drawn from the client's random source, once it returned.
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $late = $this->relays->get('wss://c.example');
        $late->refuseEvent = static function (Event $event): ?string {
            Loop::get()->sleepUntil(TestTime::deadline(1.0));

            return null;
        };
        $after = new class {
            public bool $returned = false;

            public int $drawn = 0;
        };
        $context = TestContext::create($this->relays->connector, null, null, 3000, static function (int $bytes) use ($after): string {
            if ($after->returned) {
                ++$after->drawn;
            }

            return $bytes > 0 ? random_bytes($bytes) : '';
        });
        $event = $this->event(1700000000, 'x');
        // Held past the call: its connections are closed by the call's end, not by its going.
        $pool = new RelayPool($context);
        $started = Loop::now();
        $took = Loop::get()->run(fn(): bool => $pool->publish($urls, $event, fn(): string => $this->secret));
        $after->returned = true;
        self::assertTrue($took);
        self::assertLessThan(TestTime::seconds(0.9), Loop::now() - $started, 'returned at the majority');
        $fibers = (new \ReflectionProperty(Loop::class, 'fibers'))->getValue(Loop::get());
        self::assertIsArray($fibers);
        self::assertSame([], array_filter($fibers, static fn(mixed $fiber): bool => \is_array($fiber) && $fiber[1] !== null), 'no task of the call left');
        // Past c's moment: c took the event, its answer to a connection the call closed.
        Loop::get()->run(static fn() => Loop::get()->sleepUntil(TestTime::deadline(1.5)));
        self::assertCount(1, $late->events, 'c took it');
        self::assertSame(['EVENT'], $late->messages, 'nothing more sent to c');
        self::assertSame(0, $after->drawn, 'nothing drawn once the call returned');
        self::assertSame(1, $late->disconnected, "c's connection closed");
    }

    public function testTheLocalRelayRefusesAnAuthenticationSentInAnEvent(): void
    {
        // So that a client sending its authentication any other way than ["AUTH", <event>] fails
        // the tests (one port sent it as an EVENT, which its own test relay took): refused, never
        // kept, and authenticating nothing.
        $relay = $this->relays->add('wss://a.example');
        $relay->requireAuth = true;
        $authentication = Event::sign($this->secret, 22242, 1700000000, [['relay', 'wss://a.example'], ['challenge', 'x']], '', random_bytes(32));
        $took = $this->call(fn(RelayPool $pool): bool => $pool->publish(['wss://a.example'], $authentication, fn(): string => $this->secret));
        self::assertFalse($took);
        self::assertSame([], $relay->events);
        self::assertSame(['EVENT'], $relay->messages);
        self::assertSame([], $relay->authenticated);
    }

    public function testAPublicationSucceedsOnAMajority(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $this->relays->get('wss://c.example')->refuse = 'blocked: no';
        $event = $this->event(1700000000, 'x');
        self::assertTrue($this->call(fn(RelayPool $pool): bool => $pool->publish($urls, $event, fn(): string => $this->secret)));
        $this->relays->get('wss://b.example')->refuse = 'blocked: no';
        $other = $this->event(1700000001, 'z');
        self::assertFalse($this->call(fn(RelayPool $pool): bool => $pool->publish($urls, $other, fn(): string => $this->secret)));
    }

    public function testALateRelayIsATimeout(): void
    {
        $relay = $this->relays->add('wss://a.example');
        $relay->queryDelayMs = 600;
        $this->expectException(NetworkException::class);
        $this->call(fn(RelayPool $pool): array => $pool->read(['wss://a.example'], ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...)), 200);
    }

    public function testAConnectionThatOpensAfterItsPoolClosedIsClosed(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://slow.example'];
        $this->relays->add('wss://a.example');
        $this->relays->add('wss://b.example');
        // A relay whose handshake takes 0.5 s: the read is done, and its pool closed, before.
        $closed = 0;
        $slow = new LocalServer(static function (Socket $socket) use (&$closed): void {
            Loop::get()->sleepUntil(TestTime::deadline(0.5));
            $ws = ServerWebSocket::accept($socket);
            while ($ws !== null && $ws->receive() !== null) {
            }
            ++$closed;
        });
        $this->relays->connector->route('slow.example', 443, $slow);
        try {
            $this->call(function (RelayPool $pool) use ($urls, &$closed): void {
                $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...));
                $pool->close();
                Loop::get()->sleepUntil(TestTime::deadline(1.0));
                // Still in the call: the connection that opened late is closed already.
                self::assertSame(1, $closed);
            });
        } finally {
            $slow->stop();
        }
    }

    public function testARelaySendingManyLargeEventsPilesNothingUpAndFailsNothing(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $event = $this->event(1700000000, 'x');
        foreach ($urls as $url) {
            $this->relays->get($url)->store($event);
        }
        foreach (['wss://b.example', 'wss://c.example'] as $url) {
            $this->relays->get($url)->queryDelayMs = 3000; // a.example's answer comes first
        }
        // Messages of almost 1 MiB that take many times that once decoded: no events (objects
        // among the fields), then events of tiny tags, which do not open. Of each, only what the
        // read needs is kept, as it comes (SPEC.md §13): nothing piles up, and no answer is cut at
        // a size.
        $notEvent = '{"x":[' . str_repeat('{},', 340000) . '{}]}';
        $tags = '[' . str_repeat('["a"],', 170000) . '["a"]]';
        $events = Json::encode($this->event(1700000000, 'y')->toJson());
        $manyTags = str_replace('"tags":[["d","y"]]', '"tags":' . $tags, $events);
        $this->relays->get('wss://a.example')->extraEvents = static fn(): array => [...array_fill(0, 8, $notEvent), ...array_fill(0, 8, $manyTags)];
        $opens = static fn(\stdClass $value): bool => $value->tags === [['d', 'x']];
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $answers = $this->call(fn(RelayPool $pool): array => $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, $opens), 8000);
        self::assertLessThan(64 * 1048576, memory_get_peak_usage() - $before);
        // a.example answered, never failed: the one event of it that opens is kept.
        self::assertArrayHasKey('wss://a.example', $answers);
        self::assertCount(1, $answers['wss://a.example']);
        self::assertSame($event->id, $answers['wss://a.example'][0]->id);
    }

    public function testACompleteReadSaysWhetherAMajorityReadToTheEnd(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $relay = $this->relays->add($url);
            for ($i = 0; $i < 101; ++$i) {
                $relay->events[] = $this->unsigned(1700000000); // more of one second than a page holds
            }
        }
        $read = fn(\Closure $keep): bool => $this->complete($urls, $keep);
        // Pages full of one second's events, the second one with none not seen before: the rest of
        // that second is out of reach, whoever wrote them.
        self::assertFalse($read(static fn(\stdClass $event, string $url): bool => true));
        self::assertFalse($read(static fn(\stdClass $event, string $url): bool => false));
        foreach ($urls as $url) {
            $this->relays->get($url)->events = \array_slice($this->relays->get($url)->events, 0, 99);
        }
        self::assertTrue($read(static fn(\stdClass $event, string $url): bool => false), '99: a page not full holds all of that second');
        // One relay complete is no majority of three.
        $this->relays->get('wss://b.example')->closeQueries = 'error: no';
        $this->relays->get('wss://c.example')->events = [...$this->relays->get('wss://c.example')->events, $this->unsigned(1700000000), $this->unsigned(1700000000)];
        self::assertFalse($read(static fn(\stdClass $event, string $url): bool => true));
    }

    public function testACompleteReadAsksPagesOfAHundredFromTheOldestSecond(): void
    {
        $relay = $this->relays->add('wss://a.example');
        for ($i = 0; $i < 150; ++$i) {
            $relay->events[] = $this->unsigned(1700000000 - $i);
        }
        $ids = [];
        $keep = static function (\stdClass $event, string $url) use (&$ids): bool {
            $ids[] = $event->id;

            return true;
        };
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete(['wss://a.example'], ['kinds' => [30078]], fn(): string => $this->secret, $keep));
        self::assertSame(['answered' => 1, 'complete' => 1], ['answered' => $read['answered'], 'complete' => $read['complete']]);
        // Each event once, though the second page starts at the first one's oldest second.
        self::assertCount(150, $read['answers']['wss://a.example']);
        self::assertCount(151, $ids, 'the keep closure sees every copy a page holds');
        self::assertSame([[100, null], [100, 1700000000 - 99]], array_map(static fn(\stdClass $q): array => [$q->limit ?? null, $q->until ?? null], $relay->queries));
    }

    public function testACompleteReadIsCutAtTwentyPages(): void
    {
        $relay = $this->relays->add('wss://a.example');
        // A page of 100, then 99 new ones a page (each starts at the last one's oldest second):
        // 20 pages read 1 981 events.
        for ($i = 0; $i < 1980; ++$i) {
            $relay->events[] = $this->unsigned(1700000000 - $i);
        }
        $asked = static fn(): int => \count($relay->queries);
        self::assertTrue($this->complete(['wss://a.example']), '1 980 events: the 20th page is not full');
        self::assertSame(20, $asked());
        $relay->events[] = $this->unsigned(1700000000 - 1980);
        self::assertFalse($this->complete(['wss://a.example']), '1 981: the 20th page is full');
        self::assertSame(40, $asked(), 'and no 21st page');
    }

    public function testACompleteReadIsCutByWhatARelaySendsOfNoUse(): void
    {
        $relay = $this->relays->add('wss://a.example');
        // Events whose created_at is no safe integer say nothing of where the next page starts: a
        // page full of them is a cut; a short one, the end.
        $unsafe = fn(int $count): array => array_map(fn(int $i): string => Json::encode($this->unsigned(9007199254740992 + $i)), range(1, $count));
        $relay->extraEvents = static fn(): array => $unsafe(100);
        self::assertFalse($this->complete(['wss://a.example']));
        $relay->extraEvents = static fn(): array => $unsafe(1);
        self::assertTrue($this->complete(['wss://a.example']));
        // More than the limit asked: what the relay left out cannot be told.
        $relay->extraEvents = fn(): array => array_map(fn(int $i): string => Json::encode($this->unsigned(1700000000 - $i)), range(1, 101));
        self::assertFalse($this->complete(['wss://a.example']));
        // Values that are no event still fill a page.
        $relay->extraEvents = static fn(): array => array_fill(0, 100, '{"id":null}');
        self::assertFalse($this->complete(['wss://a.example']));
        $relay->extraEvents = null;
        self::assertTrue($this->complete(['wss://a.example']), 'an empty page: the end');
    }

    public function testACompleteReadOfManyLargeEventsEndsIncompleteNeverFailed(): void
    {
        // A page full of large events anybody wrote at one second, none of which opens, then the
        // same page again (none new): the read ends incomplete, the relay never failed, and only
        // their ids and dates were kept (SPEC.md §13).
        $relay = $this->relays->add('wss://a.example');
        $large = array_map(function (): string {
            $event = $this->unsigned(1700000000);
            $event->content = str_repeat('x', 200000);

            return Json::encode($event);
        }, range(1, 100));
        $relay->extraEvents = static fn(): array => $large;
        $pages = [];
        $relay->onQuery = static function (\stdClass $filter) use (&$pages): void {
            $pages[] = $filter->until ?? null;
        };
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete(['wss://a.example'], ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => false), 8000);
        self::assertLessThan(64 * 1048576, memory_get_peak_usage() - $before - 100 * 200000, 'the relay\'s events, built by the test, aside');
        self::assertSame([null, 1700000000], $pages);
        self::assertSame([1, 0, 0], [$read['answered'], $read['complete'], \count($read['answers']['wss://a.example'] ?? [])]);
    }

    public function testARelaysCopiesOfOneEventAreReadOnceAndKeptAsItsNip01Members(): void
    {
        // SPEC.md §13, Limits: of one relay's answer to a query, the first event of each id is the
        // one read; a later one (a copy, or a forgery) only counts toward the query's 1000, nothing
        // of it kept, opened or checked. And of an event kept, only its NIP-01 members: a relay may
        // add any member to an event that checks out (its id and signature cover none). 50 copies
        // of one record, each with 100 000 characters of a member of its own: one kept, its seven
        // members alone, one opening per query.
        $relay = $this->relays->add('wss://a.example');
        $event = $this->event(1700000000, 'x');
        $members = get_object_vars($event->toJson());
        $copies = array_map(static fn(int $i): string => Json::encode(['grown' . $i => str_repeat('a', 100000)] + $members), range(1, 50));
        $relay->extraEvents = static fn(): array => $copies;
        $opened = new class {
            public int $count = 0;
        };
        $open = static function (\stdClass $value) use ($opened): bool {
            ++$opened->count;

            return true;
        };
        foreach (['a read' => false, 'a paged read' => true] as $case => $paged) {
            $opened->count = 0;
            $before = \count($relay->queries);
            $answers = $this->call(fn(RelayPool $pool): array => $pool->read(['wss://a.example'], ['kinds' => [30078]], $paged, fn(): string => $this->secret, $open));
            self::assertCount(1, $answers['wss://a.example'] ?? [], "{$case}: one kept");
            self::assertSame(['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'], array_keys(get_object_vars($answers['wss://a.example'][0])), $case);
            self::assertEquals($event->toJson(), $answers['wss://a.example'][0], $case);
            self::assertGreaterThan($before, \count($relay->queries), $case);
            self::assertSame(\count($relay->queries) - $before, $opened->count, "{$case}: one opening per query");
        }
        $complete = $this->call(fn(RelayPool $pool): array => $pool->readComplete(['wss://a.example'], ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $value, string $url): bool => $open($value)));
        self::assertCount(1, $complete['answers']['wss://a.example'] ?? [], 'a complete read: one kept');
    }

    public function testARelaySendingLargeIdsPilesNothingUp(): void
    {
        // Values whose `id` is a string of almost 1 MiB: a read keeps a few bytes of each id, to
        // count each event once (SPEC.md §13), never the id as sent.
        $relay = $this->relays->add('wss://a.example');
        $large = array_map(static fn(int $i): string => Json::encode(['id' => str_repeat(\chr(97 + $i % 26), 1000000) . $i, 'created_at' => 1700000000]), range(1, 48));
        $relay->extraEvents = static fn(): array => $large;
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete(['wss://a.example'], ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true), 8000);
        self::assertLessThan(24 * 1048576, memory_get_peak_usage() - $before, '48 MB when each id is kept as sent');
        self::assertSame([1, 1, 0], [$read['answered'], $read['complete'], \count($read['answers']['wss://a.example'] ?? [])], 'a page not full: complete; no event');
    }

    public function testACompleteReadEndsIncompleteWhenALaterPageFails(): void
    {
        $relay = $this->relays->add('wss://a.example');
        for ($i = 0; $i < 150; ++$i) {
            $relay->events[] = $this->unsigned(1700000000 - $i);
        }
        $relay->onQuery = static function (\stdClass $filter, LocalRelay $relay): void {
            if (isset($filter->until)) {
                $relay->closeQueries = 'error: gone';
            }
        };
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete(['wss://a.example'], ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true));
        // It answered (its first page came), incomplete; what it sent is kept.
        self::assertSame([1, 0, 100], [$read['answered'], $read['complete'], \count($read['answers']['wss://a.example'])]);
    }

    public function testAPageEndsByItsDeadlineWhateverItsEventsCost(): void
    {
        // Events already come, each costly to the caller: the page still ends by its deadline.
        $relay = $this->relays->add('wss://a.example');
        for ($i = 0; $i < 50; ++$i) {
            $relay->store($this->event(1700000000 - $i, "e{$i}"));
        }
        $started = Loop::now();
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete(['wss://a.example'], ['kinds' => [30078]], fn(): string => $this->secret, static function (\stdClass $event, string $url): bool {
            TestTime::block(20);

            return true;
        }), 300);
        self::assertSame(0, $read['answered'], 'its first page timed out: the relay failed');
        self::assertLessThan(TestTime::seconds(0.6), Loop::now() - $started, '1 s when the deadline waits for its events');
    }

    public function testAReadOnEveryRelayWaitsOneTimeoutPastAMajority(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        // c.example holds an event the two others hide, and answers 0.3 s after them.
        $late = $this->relays->get('wss://c.example');
        $late->store($this->event(1700000000, 'x'));
        $late->queryDelayMs = 300;
        foreach ([false, true] as $everyRelay) {
            $answers = $this->call(fn(RelayPool $pool): array => $pool->read($urls, ['kinds' => [30078]], true, fn(): string => $this->secret, self::every(...), $everyRelay), 1000);
            self::assertSame($everyRelay, isset($answers['wss://c.example']));
            $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, $everyRelay), 1000);
            self::assertSame($everyRelay, isset($read['answers']['wss://c.example']));
            self::assertSame($everyRelay ? 3 : 2, $read['complete']);
        }

        // A relay that never answers delays it by one relayTimeoutMs past the majority, no more.
        $late->queryDelayMs = 0;
        $late->mute = true;
        $started = Loop::now();
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, true), 400);
        self::assertSame(['answered' => 2, 'complete' => 2], ['answered' => $read['answered'], 'complete' => $read['complete']]);
        self::assertLessThan(TestTime::seconds(0.75), Loop::now() - $started);
    }

    public function testACompleteReadOnEveryRelayStopsWhenAMajorityCanNoLonger(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $this->relays->get('wss://a.example')->mute = true;
        $this->relays->get('wss://b.example')->closeQueries = 'error: no';
        $this->relays->get('wss://c.example')->closeQueries = 'error: no';
        $started = Loop::now();
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, true), 1000);
        self::assertSame(0, $read['complete']);
        self::assertLessThan(TestTime::seconds(0.5), Loop::now() - $started, 'the mute relay is not waited for');
    }

    public function testAReadOnEveryRelayKeepsThePagesARelayStillReadingSent(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        // c.example holds 150 events and sends 100 a query: its first page 1.5 s late, its second
        // never (60 s late). The cut, 3 s after the majority, finds it still reading.
        $slow = $this->relays->get('wss://c.example');
        $slow->cap = 100;
        for ($i = 0; $i < 150; ++$i) {
            $slow->events[] = $this->unsigned(1700000000 - $i);
        }
        $slow->onQuery = static function (\stdClass $filter, LocalRelay $relay): void {
            $relay->queryDelayMs = isset($filter->until) ? 60000 : 1500;
        };
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, true), 3000);
        // Its pages kept, but it has not answered: only a relay whose read ended did (SPEC.md §13).
        self::assertSame(['answered' => 2, 'complete' => 2], ['answered' => $read['answered'], 'complete' => $read['complete']]);
        self::assertCount(100, $read['answers']['wss://c.example']);
        $answers = $this->call(fn(RelayPool $pool): array => $pool->read($urls, ['kinds' => [30078]], true, fn(): string => $this->secret, self::every(...), true), 3000);
        // A paged read: its first page kept, its second one past the cut.
        self::assertCount(100, $answers['wss://c.example'] ?? [], 'its first page kept');
    }

    public function testARelayStillReadingWhenACompleteReadOnEveryRelayGoesOnHasNotAnswered(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        // a.example: a full page of one second, then the same page again, 2 s late: its read ends
        // incomplete. b.example: its first page closed, it failed. c.example: a full first page at
        // once, its second never (60 s late). Once a's read ended, a majority can no longer read
        // completely: the read goes on, c still reading (its request 8 s from its deadline).
        $a = $this->relays->get('wss://a.example');
        for ($i = 0; $i < 101; ++$i) {
            $a->events[] = $this->unsigned(1700000000);
        }
        $a->onQuery = static function (\stdClass $filter, LocalRelay $relay): void {
            $relay->queryDelayMs = isset($filter->until) ? 2000 : 0;
        };
        $this->relays->get('wss://b.example')->closeQueries = 'error: no';
        $c = $this->relays->get('wss://c.example');
        for ($i = 0; $i < 150; ++$i) {
            $c->events[] = $this->unsigned(1700000000 - $i);
        }
        $c->onQuery = static function (\stdClass $filter, LocalRelay $relay): void {
            $relay->queryDelayMs = isset($filter->until) ? 60000 : 0;
        };
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, true), 10000);
        // One of three answered, whatever c sent: fewer than a majority (`network` for whoever
        // reads it, SPEC.md §13), never a read that a majority answered, incomplete.
        self::assertSame(['answered' => 1, 'complete' => 0], ['answered' => $read['answered'], 'complete' => $read['complete']]);
        self::assertCount(100, $read['answers']['wss://a.example'] ?? []);
        self::assertCount(100, $read['answers']['wss://c.example'] ?? [], 'its first page kept');
        self::assertArrayNotHasKey('wss://b.example', $read['answers']);
    }

    public function testARelayThatFailedIsNotWaitedForAgainInTheCall(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $this->relays->get('wss://c.example')->mute = true;
        $started = Loop::now();
        $reads = $this->call(fn(RelayPool $pool): array => [
            $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, true),
            $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true, true),
            $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...), true),
        ], 400);
        self::assertSame([2, 2], [$reads[0]['complete'], $reads[1]['complete']]);
        self::assertCount(2, $reads[2]);
        // One timeout in all: the first read's.
        self::assertLessThan(TestTime::seconds(0.75), Loop::now() - $started);
    }

    public function testARelayWhoseAuthenticationRunsOutOfTimeFailedInTheCall(): void
    {
        // NIP-42's waits are part of the request's time (SPEC.md §13): a read on every relay that
        // waits for a relay's authentication until the request runs out of time counts that relay
        // as failed for the rest of the call, never as a refusal asked again, whether its OK or its
        // challenge never came. The call's next read on every relay does not wait for it again.
        foreach (['its OK never comes' => 'muteAuth', 'its challenge never comes' => 'withholdChallenge'] as $case => $behaviour) {
            $relays = new Relays(new TestConnector());
            $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
            foreach ($urls as $url) {
                $relays->add($url);
            }
            $slow = $relays->get('wss://c.example');
            $slow->requireAuth = true;
            $slow->{$behaviour} = true;
            $context = TestContext::create($relays->connector, null, null, 400);
            try {
                [$first, $second, $seconds] = Loop::get()->run(function () use ($context, $urls): array {
                    $pool = new RelayPool($context);
                    $first = $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...), true);
                    $started = Loop::now();
                    $second = $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...), true);

                    return [$first, $second, Loop::now() - $started];
                });
            } finally {
                $relays->stop();
            }
            self::assertSame(['wss://a.example', 'wss://b.example'], array_keys($first), $case);
            self::assertSame(['wss://a.example', 'wss://b.example'], array_keys($second), $case);
            self::assertCount(1, $slow->queries, "{$case}: asked by the first read alone");
            self::assertLessThan(TestTime::seconds(0.3), $seconds, "{$case}: not waited for again");
        }
    }

    public function testARelayADroppedReadLeftBehindIsNotFailed(): void
    {
        // A read goes on at a majority and drops the late relay (SPEC.md §13): its request running
        // out of time afterwards is no failure of that relay, which the next reads on every relay
        // still ask (review of the PHP port: it was skipped, and what only it held was lost).
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        $late = $this->relays->get('wss://c.example');
        $late->queryDelayMs = 450;
        $read = static fn(\stdClass $event, string $url): bool => true;
        [$normal, $complete, $paged, $everywhere] = $this->call(function (RelayPool $pool) use ($urls, $late, $read): array {
            $normal = $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...));
            $complete = $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, $read);
            $paged = $pool->read($urls, ['kinds' => [30078]], true, fn(): string => $this->secret, self::every(...));
            // Past the dropped requests' deadline: c answers at once, and holds an event alone.
            Loop::get()->sleepUntil(TestTime::deadline(0.4));
            $late->queryDelayMs = 0;
            $late->store($this->event(1700000000, 'only-c'));

            return [$normal, $complete, $paged, [
                $pool->read($urls, ['kinds' => [30078]], false, fn(): string => $this->secret, self::every(...), true),
                $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, $read, true),
            ]];
        }, 300);
        self::assertArrayNotHasKey('wss://c.example', $normal, 'the first reads went on without c');
        self::assertArrayNotHasKey('wss://c.example', $complete['answers']);
        self::assertArrayNotHasKey('wss://c.example', $paged);
        self::assertCount(1, $everywhere[0]['wss://c.example'] ?? [], 'a read on every relay asks c');
        self::assertCount(1, $everywhere[1]['answers']['wss://c.example'] ?? []);
        self::assertSame(3, $everywhere[1]['complete']);
    }

    public function testADroppedPagedReadAsksNoFurtherPage(): void
    {
        $urls = ['wss://a.example', 'wss://b.example', 'wss://c.example'];
        foreach ($urls as $url) {
            $this->relays->add($url);
        }
        // c holds 250 events (a full page of a complete read, more pages to ask), each page 0.2 s
        // late; the reads go on with a and b at once.
        $late = $this->relays->get('wss://c.example');
        $late->queryDelayMs = 200;
        for ($i = 0; $i < 250; ++$i) {
            $late->events[] = $this->unsigned(1700000000 - $i);
        }
        $this->call(function (RelayPool $pool) use ($urls): void {
            $pool->read($urls, ['kinds' => [30078]], true, fn(): string => $this->secret, self::every(...));
            $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, static fn(\stdClass $event, string $url): bool => true);
            Loop::get()->sleepUntil(TestTime::deadline(1.0));
        }, 2000);
        // The first page of each read asked, none after.
        self::assertCount(2, $late->queries);
    }

    /** @return iterable<string, array{0: string}> */
    public static function readsThatGoOn(): iterable
    {
        yield 'a read' => ['read'];
        yield 'a paged read' => ['paged'];
        yield 'a complete read' => ['complete'];
        yield 'a complete read on every relay that a majority can no longer read completely' => ['complete on every relay'];
    }

    /**
     * SPEC.md §13: once a read goes on, the relays it dropped ask nothing more for it: no request,
     * no page, no authentication, and the client draws no random bytes for them (a subscription
     * id, the read's key, an authentication's signature). Of seven relays, a to d end their read at
     * once; e answers its first page after them, a full page it would page on from; f asks for
     * authentication after them (its query CLOSED auth-required); g answers its WebSocket handshake
     * after them. The read goes on with a to d, and in the rest of the call, past the moments of
     * e, f and g, these receive nothing more than the query each had then (g: none).
     */
    #[DataProvider('readsThatGoOn')]
    public function testTheRelaysOfADroppedReadAskNothingMore(string $read): void
    {
        $urls = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $name) {
            $urls[] = $url = "wss://{$name}.example";
            $relay = $this->relays->add($url);
            if (\in_array($name, ['a', 'b', 'c', 'd'], true)) {
                // A page that is not full: complete; on every relay, 150 events of one second (a
                // full page, then the same page again): incomplete, so that a majority can no
                // longer read completely once they ended.
                for ($i = 0; $i < ($read === 'complete on every relay' ? 150 : 3); ++$i) {
                    $relay->events[] = $this->unsigned($read === 'complete on every relay' ? 1700000000 : 1700000000 - $i);
                }
            }
        }
        $late = $this->relays->get('wss://e.example');
        $late->queryDelayMs = 600;
        for ($i = 0; $i < 150; ++$i) {
            $late->events[] = $this->unsigned(1700000000 - $i);
        }
        $asksAuth = $this->relays->get('wss://f.example');
        $asksAuth->requireAuth = true;
        $asksAuth->authRequiredDelayMs = 600;
        $asksAuth->events[] = $this->unsigned(1700000000);
        $lateHandshake = $this->relays->get('wss://g.example');
        $lateHandshake->handshakeDelayMs = 600;
        $lateHandshake->events[] = $this->unsigned(1700000000);

        // Once the read went on: how many times the client's random source is called, how many
        // events are handed to the read (its requests ended: their subscriptions are gone).
        $after = new class {
            public bool $wentOn = false;

            public int $drawn = 0;

            public int $handed = 0;
        };
        $context = TestContext::create($this->relays->connector, null, null, 2000, static function (int $bytes) use ($after): string {
            if ($after->wentOn) {
                ++$after->drawn;
            }

            return $bytes > 0 ? random_bytes($bytes) : '';
        });
        $keep = static function (\stdClass $event, string $url = '') use ($after): bool {
            if ($after->wentOn) {
                ++$after->handed;
            }

            return true;
        };
        $seconds = Loop::get()->run(static function () use ($context, $urls, $read, $keep, $after): float {
            $pool = new RelayPool($context);
            $started = Loop::now();
            match ($read) {
                'read' => $pool->read($urls, ['kinds' => [30078]], false, $context->randomSecret(...), $keep),
                'paged' => $pool->read($urls, ['kinds' => [30078]], true, $context->randomSecret(...), $keep),
                'complete' => $pool->readComplete($urls, ['kinds' => [30078]], $context->randomSecret(...), $keep),
                default => $pool->readComplete($urls, ['kinds' => [30078]], $context->randomSecret(...), $keep, true),
            };
            $after->wentOn = true;
            $seconds = Loop::now() - $started;
            // The rest of the call, past the moments of e, f and g.
            Loop::get()->sleepUntil(TestTime::deadline(1.5));

            return $seconds;
        });
        self::assertLessThan(TestTime::seconds(0.6), $seconds, 'the read went on without e, f and g');
        // e: no CLOSE, no further page; f: no AUTH, no query again; g: no query once it connected.
        self::assertSame(
            ['e' => ['REQ'], 'f' => ['REQ'], 'f authenticated' => [], 'g' => [], 'random bytes drawn once it went on' => 0, 'events handed to it once it went on' => 0],
            ['e' => $late->messages, 'f' => $asksAuth->messages, 'f authenticated' => $asksAuth->authenticated, 'g' => $lateHandshake->messages, 'random bytes drawn once it went on' => $after->drawn, 'events handed to it once it went on' => $after->handed],
        );
        foreach (['a', 'b', 'c', 'd'] as $name) {
            self::assertContains('REQ', $this->relays->get("wss://{$name}.example")->messages, $name);
        }
    }

    /**
     * Whether a complete read of $urls is complete: a majority of them read completely.
     *
     * @param list<string>                            $urls
     * @param (\Closure(\stdClass, string): bool)|null $keep
     */
    private function complete(array $urls, ?\Closure $keep = null): bool
    {
        $read = $this->call(fn(RelayPool $pool): array => $pool->readComplete($urls, ['kinds' => [30078]], fn(): string => $this->secret, $keep ?? static fn(\stdClass $event, string $url): bool => true));

        return RelayPool::outcome($read['reads'])['complete'];
    }

    private function event(int $createdAt, string $d): Event
    {
        return Event::sign($this->secret, 30078, $createdAt, [['d', $d]], 'content', random_bytes(32));
    }

    /** An event of the right shape, neither its id nor its signature true (no read checks them). */
    private function unsigned(int $createdAt): \stdClass
    {
        return (object) ['id' => bin2hex(random_bytes(32)), 'pubkey' => bin2hex(random_bytes(32)), 'created_at' => $createdAt, 'kind' => 30078, 'tags' => [['d', 'x']], 'content' => 'x', 'sig' => bin2hex(random_bytes(64))];
    }

    /** The events of these tests are no records: every one is kept (RelayPool::read()'s $keep). */
    private static function every(\stdClass $event): bool
    {
        return true;
    }

    /**
     * @template T
     *
     * @param \Closure(RelayPool): T $body
     *
     * @return T
     */
    private function call(\Closure $body, int $relayTimeoutMs = 2000): mixed
    {
        $context = TestContext::create($this->relays->connector, null, null, $relayTimeoutMs);

        return Loop::get()->run(static fn(): mixed => $body(new RelayPool($context)));
    }
}
