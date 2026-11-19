<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Nostr;

use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Net\Socket;
use Sattleme\Checkout\Internal\Net\TransportException;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Tests\Support\Net\LocalServer;
use Sattleme\Checkout\Tests\Support\Net\ServerWebSocket;
use Sattleme\Checkout\Tests\Support\TestTime;

/**
 * A Nostr relay for the tests (NIP-01, NIP-42) on a local port: it keeps the newest event of each
 * address (kinds 30000 to 39999), as relays do; it can refuse events or queries, ask for
 * authentication, cap its answers, honor a filter's limit or answer one without it oldest first,
 * hide events, stay mute or answer late (a query, a handshake, a request for authentication). It
 * logs the type of every message a client sends. An authentication counts only as NIP-42 has it,
 * `["AUTH", <event>]` with an event of kind 22242 of the connection's challenge and the relay's
 * URL: one sent in an EVENT is refused.
 */
final class LocalRelay
{
    public readonly LocalServer $server;

    /** @var list<\stdClass> what it holds, valid or not: a relay is never trusted */
    public array $events = [];

    /** @var list<\stdClass> every event a client sent, taken or not */
    public array $received = [];

    /** @var list<\stdClass> every filter a client asked */
    public array $queries = [];

    /** @var list<string> the public keys that authenticated */
    public array $authenticated = [];

    /** @var list<mixed> what came in each authentication a client sent (["AUTH", <event>]), valid or not */
    public array $authentications = [];

    /** The URL clients reach it at (Relays sets it): an authentication names it. */
    public ?string $url = null;

    /** @var list<string> the subscriptions a client closed */
    public array $closed = [];

    /** @var list<string> the type of every message a client sent ("REQ", "EVENT", "AUTH", "CLOSE"…), in order */
    public array $messages = [];

    /** How many of its connections ended: the client closed them, or went away. */
    public int $disconnected = 0;

    /** Refuses every event with this reason. */
    public ?string $refuse = null;

    /** @var (\Closure(Event): ?string)|null a reason to refuse an event, or null */
    public ?\Closure $refuseEvent = null;

    /** Accepts connections, then never sends anything. */
    public bool $mute = false;

    /** Asks every connection to authenticate (NIP-42) before reading or writing. */
    public bool $requireAuth = false;

    /** Requiring authentication: never sends its challenge. */
    public bool $withholdChallenge = false;

    /** Never answers an authentication (no OK). */
    public bool $muteAuth = false;

    /** Sends at most this many events per query, the newest first (strfry: 500). */
    public ?int $cap = null;

    /** @var (\Closure(\stdClass): bool)|null whether it hides an event it holds from every query */
    public ?\Closure $hide = null;

    /** Sends every match, the oldest first, for a query whose filters have no limit (nostr-rs-relay). */
    public bool $oldestFirstWithoutLimit = false;

    /** Answers each query this many milliseconds (scaled: TestTime) late (each on its own; writes at once). */
    public int $queryDelayMs = 0;

    /** Requiring authentication: closes a query that needs it (CLOSED auth-required) this many milliseconds (scaled) late. */
    public int $authRequiredDelayMs = 0;

    /** Answers each connection's WebSocket handshake this many milliseconds (scaled) late. */
    public int $handshakeDelayMs = 0;

    /** Closes every query at once with this reason (CLOSED), events still taken. */
    public ?string $closeQueries = null;

    /** @var (\Closure(): iterable<string>)|null what it sends for each query before its events, as JSON values */
    public ?\Closure $extraEvents = null;

    /** @var (\Closure(Event, LocalRelay): void)|null hears each event it took */
    public ?\Closure $onEvent = null;

    /** @var (\Closure(\stdClass, LocalRelay): void)|null hears each filter asked, before it answers */
    public ?\Closure $onQuery = null;

    public function __construct()
    {
        $this->server = new LocalServer(function (Socket $socket): void {
            $this->serve($socket);
        });
    }

    /** Holds $value as it is (an invalid event too). */
    public function store(\stdClass|Event $value): void
    {
        $this->events[] = $value instanceof Event ? self::object($value) : $value;
    }

    /** Takes $event as a client's write: the newest of its address is kept. */
    public function take(Event|\stdClass $event): void
    {
        $parsed = $event instanceof Event ? $event : Event::fromJson($event);
        if ($parsed === null) {
            throw new \InvalidArgumentException('Not an event');
        }
        $this->keep($parsed);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    private function serve(Socket $socket): void
    {
        try {
            $this->connection($socket);
        } finally {
            ++$this->disconnected;
        }
    }

    private function connection(Socket $socket): void
    {
        if ($this->handshakeDelayMs > 0) {
            Loop::get()->sleepUntil(TestTime::deadline($this->handshakeDelayMs / 1000));
        }
        $ws = ServerWebSocket::accept($socket);
        if ($ws === null) {
            return;
        }
        $challenge = bin2hex(random_bytes(8));
        $authed = [];
        // One message at a time on the connection: the answers of late queries go out on their
        // own, while it reads what comes next.
        $locks = new Locks();
        $send = static function (string $text) use ($ws, $locks): void {
            $locks->with('write', static fn() => $ws->send($text));
        };
        if ($this->requireAuth && !$this->mute && !$this->withholdChallenge) {
            $send(self::json(['AUTH', $challenge]));
        }
        while (($text = $ws->receive()) !== null) {
            if ($this->mute) {
                continue;
            }
            try {
                $message = Json::decode($text);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($message) || !\is_string($message[0] ?? null)) {
                continue;
            }
            $this->messages[] = $message[0];
            switch ($message[0]) {
                case 'AUTH':
                    if ($this->muteAuth) {
                        break;
                    }
                    $this->authentications[] = $message[1] ?? null;
                    // NIP-42: a signed event of kind 22242, of this connection's challenge and this
                    // relay, its content empty.
                    $event = Event::fromJson($message[1] ?? null);
                    $ok = $event !== null && $event->verify() && $event->kind === 22242 && $event->hasTag('challenge', $challenge)
                        && ($this->url === null || $event->hasTag('relay', $this->url)) && $event->content === '';
                    if ($ok) {
                        $authed[$event->pubkey] = true;
                        $this->authenticated[] = $event->pubkey;
                    }
                    $send(self::json(['OK', Json::get($message[1] ?? null, 'id'), $ok, $ok ? '' : 'invalid: bad auth']));
                    break;
                case 'EVENT':
                    $value = $message[1] ?? null;
                    $event = Event::fromJson($value);
                    $id = Json::get($value, 'id');
                    $valid = $event !== null && $event->verify();
                    if ($valid && $value instanceof \stdClass) {
                        $this->received[] = $value;
                    }
                    if ($event !== null && $event->kind === 22242) {
                        // NIP-42: an authentication is sent as ["AUTH", <event>], never in an EVENT:
                        // refused, never kept, and it authenticates nothing.
                        $send(self::json(['OK', $id, false, 'invalid: an authentication is sent as ["AUTH", <event>]']));
                    } elseif ($this->requireAuth && ($event === null || !isset($authed[$event->pubkey]))) {
                        $send(self::json(['OK', $id, false, 'auth-required: sign in first']));
                    } elseif ($this->refuse !== null) {
                        $send(self::json(['OK', $id, false, $this->refuse]));
                    } elseif (!$valid) {
                        $send(self::json(['OK', $id, false, 'invalid: bad event']));
                    } elseif ($this->refuseEvent !== null && ($reason = ($this->refuseEvent)($event)) !== null) {
                        $send(self::json(['OK', $id, false, $reason]));
                    } else {
                        $this->keep($event);
                        if ($this->onEvent !== null) {
                            ($this->onEvent)($event, $this);
                        }
                        $send(self::json(['OK', $event->id, true, '']));
                    }
                    break;
                case 'REQ':
                    $id = $message[1] ?? null;
                    $filters = array_values(\array_slice($message, 2));
                    foreach ($filters as $filter) {
                        if ($filter instanceof \stdClass) {
                            $this->queries[] = $filter;
                            if ($this->onQuery !== null) {
                                ($this->onQuery)($filter, $this);
                            }
                        }
                    }
                    if ($this->requireAuth && $authed === []) {
                        $closed = self::json(['CLOSED', $id, 'auth-required: sign in first']);
                        if ($this->authRequiredDelayMs > 0) {
                            $until = TestTime::deadline($this->authRequiredDelayMs / 1000);
                            Loop::get()->spawnDetached(static function () use ($send, $closed, $ws, $until): void {
                                Loop::get()->sleepUntil($until);
                                try {
                                    if ($ws->open) {
                                        $send($closed);
                                    }
                                } catch (TransportException) {
                                    // The client went away meanwhile.
                                }
                            });
                        } else {
                            $send($closed);
                        }
                        break;
                    }
                    if ($this->closeQueries !== null) {
                        $send(self::json(['CLOSED', $id, $this->closeQueries]));
                        break;
                    }
                    // What it holds when it answers, as it answers.
                    $answer = function () use ($send, $id, $filters): void {
                        foreach ($this->extraEvents !== null ? ($this->extraEvents)() : [] as $raw) {
                            $send('["EVENT",' . Json::encode($id) . ',' . $raw . ']');
                        }
                        foreach ($this->match($filters) as $event) {
                            $send(self::json(['EVENT', $id, $event]));
                        }
                        $send(self::json(['EOSE', $id]));
                    };
                    if ($this->queryDelayMs > 0) {
                        // Each query late on its own: what comes next (writes, other queries) at once.
                        $until = TestTime::deadline($this->queryDelayMs / 1000);
                        Loop::get()->spawnDetached(static function () use ($answer, $ws, $until): void {
                            Loop::get()->sleepUntil($until);
                            try {
                                if ($ws->open) {
                                    $answer();
                                }
                            } catch (TransportException) {
                                // The client went away meanwhile.
                            }
                        });
                    } else {
                        $answer();
                    }
                    break;
                case 'CLOSE':
                    $this->closed[] = self::text($message[1] ?? '');
                    break;
            }
        }
    }

    /**
     * The events matching any of $filters, the newest first, capped.
     *
     * @param list<mixed> $filters
     *
     * @return list<\stdClass>
     */
    private function match(array $filters): array
    {
        $matches = [];
        foreach ($this->events as $value) {
            if ($this->hide !== null && ($this->hide)($value)) {
                continue;
            }
            foreach ($filters as $filter) {
                if ($filter instanceof \stdClass && self::matches($value, $filter)) {
                    $matches[] = $value;
                    break;
                }
            }
        }
        usort($matches, static function (\stdClass $a, \stdClass $b): int {
            $byTime = Json::get($b, 'created_at') <=> Json::get($a, 'created_at');

            return $byTime !== 0 ? $byTime : strcmp(self::text(Json::get($a, 'id')), self::text(Json::get($b, 'id')));
        });
        $limit = $this->cap;
        $limited = false;
        foreach ($filters as $filter) {
            $asked = Json::get($filter, 'limit');
            if (\is_int($asked)) {
                $limit = $limit === null ? $asked : min($limit, $asked);
                $limited = true;
            }
        }
        if ($this->oldestFirstWithoutLimit && !$limited) {
            return array_reverse($matches);
        }

        return $limit === null ? $matches : \array_slice($matches, 0, $limit);
    }

    private static function matches(\stdClass $event, \stdClass $filter): bool
    {
        foreach (get_object_vars($filter) as $name => $value) {
            $name = (string) $name;
            if ($name === 'ids' || $name === 'kinds' || $name === 'authors') {
                $field = ['ids' => 'id', 'kinds' => 'kind', 'authors' => 'pubkey'][$name];
                if (!\is_array($value) || !\in_array(Json::get($event, $field), $value, true)) {
                    return false;
                }
            } elseif ($name === 'since' || $name === 'until') {
                $createdAt = Json::get($event, 'created_at');
                if (!\is_int($createdAt) || !\is_int($value) || ($name === 'since' ? $createdAt < $value : $createdAt > $value)) {
                    return false;
                }
            } elseif (str_starts_with($name, '#') && \strlen($name) === 2) {
                $tags = Json::get($event, 'tags');
                $found = false;
                foreach (\is_array($tags) ? $tags : [] as $tag) {
                    if (\is_array($tag) && ($tag[0] ?? null) === $name[1] && \is_array($value) && \in_array($tag[1] ?? null, $value, true)) {
                        $found = true;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Keeps the newest event of an address (kinds 30000 to 39999), as relays do. */
    private function keep(Event $event): void
    {
        if ($event->kind >= 30000 && $event->kind < 40000) {
            $d = $event->tag('d') ?? '';
            $kept = [];
            foreach ($this->events as $other) {
                $old = Event::fromJson($other);
                if ($old !== null && $old->kind === $event->kind && $old->pubkey === $event->pubkey && ($old->tag('d') ?? '') === $d) {
                    if ($old->replaces($event) || $old->id === $event->id) {
                        return;
                    }
                    continue;
                }
                $kept[] = $other;
            }
            $this->events = $kept;
        }
        $this->events[] = self::object($event);
    }

    private static function object(Event $event): \stdClass
    {
        return self::parse($event->toJson());
    }

    private static function parse(mixed $value): \stdClass
    {
        $decoded = Json::decode(Json::encode($value));
        \assert($decoded instanceof \stdClass);

        return $decoded;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** @param list<mixed> $message */
    private static function json(array $message): string
    {
        return Json::encode($message);
    }
}
