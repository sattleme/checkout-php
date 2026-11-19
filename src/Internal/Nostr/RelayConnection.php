<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Future;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Queue;
use Sattleme\Checkout\Internal\Loop\TimeoutException;
use Sattleme\Checkout\Internal\Net\TransportException;
use Sattleme\Checkout\Internal\Net\WebSocket;
use Sattleme\Checkout\Internal\Protocol;

/**
 * One relay's connection for one call (SPEC.md §13, NIP-01, NIP-42): opened at its first request,
 * then every request of the call uses it. One task reads it and hands each message to the request
 * it answers. A relay is never trusted: what it sends that is not of the protocol is ignored.
 *
 * @internal
 */
final class RelayConnection
{
    /**
     * Whether a request to it ran out of time while it was waited for (not once its read went on
     * without it, ReadState), or its connection failed, in this call: a read on every relay counts
     * it as failed at once (SPEC.md §13, "Reads on every relay").
     */
    public bool $failedInCall = false;

    private ?WebSocket $socket = null;

    private ?Future $opening = null;

    private ?\Throwable $failure = null;

    /** @var array<string, Subscription> by subscription id */
    private array $subscriptions = [];

    /** @var array<string, list<Future>> by event id: the OK it awaits */
    private array $oks = [];

    private ?string $challenge = null;

    /** @var list<Future> requests waiting for a challenge */
    private array $challengeWaiters = [];

    /** @var array<string, array{0: string, 1: Future}> by "pubkey challenge": the one authentication per key and challenge, its event's id and the relay's OK to it */
    private array $authentications = [];

    public function __construct(
        public readonly string $url,
        private readonly Context $context,
    ) {}

    /**
     * Reads a query's events until EOSE, at most 1000 (SPEC.md §13): each with the fields of an
     * event alone (NIP-01); what is not an event is left out. Of each event, only what the read
     * needs is kept, as the event comes: its id and date, and the event itself when $keep keeps it
     * (one that opens, SPEC.md §12); any other is dropped there, so that what a relay sends never
     * piles up, whatever its size, and no answer is cut at a size. Fails at $deadline, even while
     * events that came wait.
     *
     * @param array<string, mixed>          $filter
     * @param \Closure(): string            $authSecret the key that authenticates this read, when asked
     * @param \Closure(\stdClass): bool     $keep    which events to keep: those that open
     * @param list<array{0: string|null, 1: int|null, 2: \stdClass|null}>|null $entries filled: see below
     * @param ReadState|null                 $read    the read it is a request of: once it went on
     *                                                without this relay, the request ends at once
     *                                                and asks nothing more (SPEC.md §13), and its
     *                                                running out of time fails the relay no more
     *
     * @param-out list<array{0: string|null, 1: int|null, 2: \stdClass|null}> $entries each EVENT
     *            message's value as a read pages by it (SPEC.md §13), entryOf()
     *
     * @return list<\stdClass>
     *
     * @throws RelayException
     */
    public function query(array $filter, float $deadline, \Closure $authSecret, \Closure $keep, ?array &$entries = null, ?ReadState $read = null): array
    {
        $entries = [];
        $this->open($deadline, $read);
        $authenticated = false;
        while (true) {
            // No query once the read went on (its connection opened, or it authenticated, since):
            // no subscription id drawn, nothing sent.
            ReadState::endIfOver($read);
            $id = bin2hex($this->context->random(8));
            $queue = new Queue();
            $this->subscriptions[$id] = new Subscription($queue, $keep);
            $forget = $read?->wakes($queue);
            try {
                $this->send(['REQ', $id, (object) $filter], $deadline);
                $events = [];
                $received = 0;
                $entries = [];
                while (true) {
                    $message = $this->pop($queue, $deadline, $read);
                    if ($message[0] === 'EVENT') {
                        \assert(\is_array($message[1]) && ($message[1][0] === null || \is_string($message[1][0]))
                            && ($message[1][1] === null || \is_int($message[1][1])) && ($message[1][2] === null || $message[1][2] instanceof \stdClass));
                        /** @var array{0: string|null, 1: int|null, 2: \stdClass|null} $entry */
                        $entry = $message[1];
                        $entries[] = $entry;
                        if ($entry[2] !== null) {
                            $events[] = $entry[2];
                        }
                        if (++$received >= Protocol::MAX_EVENTS_PER_QUERY) {
                            $this->sendQuietly(['CLOSE', $id], $deadline);

                            return $events;
                        }
                        if (Loop::now() >= $deadline) {
                            throw $this->timedOut($read); // As when nothing comes in time.
                        }
                    } elseif ($message[0] === 'EOSE') {
                        $this->sendQuietly(['CLOSE', $id], $deadline);

                        return $events;
                    } else { // CLOSED
                        $reason = \is_string($message[1]) ? $message[1] : '';
                        if (!$authenticated && str_starts_with($reason, 'auth-required:')) {
                            $authenticated = true;
                            if ($this->authenticate($authSecret(), $deadline, $read)) {
                                continue 2;
                            }
                        }
                        throw new RelayException('Query closed by the relay');
                    }
                }
            } finally {
                unset($this->subscriptions[$id]);
                if ($forget !== null) {
                    $forget();
                }
            }
        }
    }

    /**
     * Publishes $event: whether the relay took it (OK true).
     *
     * @param \Closure(): string $authSecret the key that authenticates this write, when asked
     *
     * @throws RelayException when the relay does not answer in time
     */
    public function publish(Event $event, float $deadline, \Closure $authSecret): bool
    {
        $this->open($deadline);
        [$ok, $reason] = $this->sendEvent(['EVENT', $event->toJson()], $event->id, $deadline);
        if (!$ok && str_starts_with($reason, 'auth-required:') && $this->authenticate($authSecret(), $deadline)) {
            [$ok] = $this->sendEvent(['EVENT', $event->toJson()], $event->id, $deadline);
        }

        return $ok;
    }

    /** Closes the connection, without waiting. */
    public function close(): void
    {
        $this->fail(new RelayException('Connection closed'));
        $this->socket?->close();
    }

    /**
     * NIP-42: answers the last challenge with $secret, once per key and challenge on this
     * connection, as `["AUTH", <event>]`; whether the relay took it. Its waits (for a challenge,
     * for the relay's OK, which every request of that key and challenge waits for, each on its own)
     * are part of the request's time (SPEC.md §13): a request that runs out of time there ran out
     * of time, never had its authentication refused, and its relay failed in the call as for any
     * request of $read. Once $read went on, it ends at once: no authentication sent, no random
     * bytes drawn.
     *
     * @throws RelayException when the request runs out of time, or its read went on
     */
    private function authenticate(#[\SensitiveParameter] string $secret, float $deadline, ?ReadState $read = null): bool
    {
        $challenge = $this->challenge;
        while ($challenge === null) {
            $waiter = new Future();
            $this->challengeWaiters[] = $waiter;
            $this->await($waiter, $deadline, $read);
            $challenge = $this->challenge;
        }
        \assert(\is_string($challenge));
        $key = bin2hex(Secp256k1::xOnlyPublicKey($secret)) . ' ' . $challenge;
        $authentication = $this->authentications[$key] ?? null;
        if ($authentication === null) {
            $event = Event::sign(
                $secret,
                Protocol::AUTH_KIND,
                $this->context->now(),
                [['relay', $this->url], ['challenge', $challenge]],
                '',
                $this->context->random(32),
            );
            // The relay's OK to it, which every request of this key and challenge waits for.
            $authentication = $this->authentications[$key] = [$event->id, new Future()];
            $this->oks[$event->id][] = $authentication[1];
            $this->send(['AUTH', $event->toJson()], $deadline);
        }
        [$id, $answer] = $authentication;
        if ($answer->isDone()) {
            $result = $answer->value();
        } else {
            // Its own wait for that OK: its read going on ends it, and no other request's.
            $mine = new Future();
            $this->oks[$id][] = $mine;
            $result = $this->await($mine, $deadline, $read);
        }
        \assert(\is_array($result) && \is_bool($result[0]));

        return $result[0];
    }

    /**
     * Waits for $future, of this request alone, until $deadline: running out of time, the request
     * ran out of time (timedOut()). Once $read went on, it ends, whether it was waiting or not.
     *
     * @throws RelayException
     */
    private function await(Future $future, float $deadline, ?ReadState $read): mixed
    {
        $forget = $read?->wakes($future);
        try {
            $value = $future->await($deadline);
        } catch (TimeoutException) {
            throw $this->timedOut($read);
        } finally {
            if ($forget !== null) {
                $forget();
            }
        }
        ReadState::endIfOver($read);

        return $value;
    }

    /**
     * Sends an EVENT message and waits for its OK: running out of time, the relay failed in the
     * call.
     *
     * @param list<mixed> $message
     *
     * @return array{0: bool, 1: string}
     *
     * @throws RelayException
     */
    private function sendEvent(array $message, string $id, float $deadline): array
    {
        $answer = new Future();
        $this->oks[$id][] = $answer;
        $this->send($message, $deadline);
        try {
            $result = $answer->await($deadline);
        } catch (TimeoutException) {
            throw $this->timedOut(null);
        }
        \assert(\is_array($result) && \is_bool($result[0]) && \is_string($result[1]));

        return [$result[0], $result[1]];
    }

    /**
     * A request of $read ran out of time: the relay failed in this call, unless the read went on
     * without it (its request dropped, SPEC.md §13).
     */
    private function timedOut(?ReadState $read): RelayException
    {
        if ($read === null || !$read->over) {
            $this->failedInCall = true;
        }

        return new RelayException('Timed out');
    }

    /**
     * The next message of a query's $queue. Once $read went on, the query ends (ReadState::DROPPED,
     * or a message that came before it went on, read after): nothing more is sent for it.
     *
     * @return array{0: string, 1: mixed}
     */
    private function pop(Queue $queue, float $deadline, ?ReadState $read = null): array
    {
        try {
            $message = $queue->pop($deadline);
        } catch (TimeoutException) {
            throw $this->timedOut($read);
        }
        ReadState::endIfOver($read);
        if ($message instanceof \Throwable) {
            throw new RelayException('Connection lost', 0, $message);
        }
        \assert(\is_array($message) && \is_string($message[0]));

        return [$message[0], $message[1] ?? null];
    }

    /** Connects (once per call), the wait included in the request's $deadline. */
    private function open(float $deadline, ?ReadState $read = null): void
    {
        if ($this->failure !== null) {
            throw new RelayException('Relay unavailable', 0, $this->failure);
        }
        if ($this->socket !== null) {
            return;
        }
        if ($this->opening !== null) {
            try {
                $this->opening->await($deadline);
            } catch (TimeoutException) {
                throw $this->timedOut($read);
            } catch (CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new RelayException('Relay unavailable', 0, $e);
            }

            return;
        }
        $opening = $this->opening = new Future();
        try {
            $socket = WebSocket::connect($this->context->connector, $this->url, $deadline);
        } catch (TransportException $e) {
            // One connection per relay per call: once it failed, the relay fails this call's
            // other requests at once.
            $this->failure = $e;
            $this->failedInCall = true;
            $this->opening = null;
            $opening->reject($e);
            throw new RelayException('Cannot connect', 0, $e);
        } catch (CancelledException $e) {
            // The call was cancelled: so are its other requests that wait for this connection.
            $this->opening = null;
            $opening->reject($e);
            throw $e;
        }
        $failure = $this->failure();
        if ($failure !== null) {
            // Closed while it connected (its pool is gone): no reader, no socket left open.
            $socket->close();
            $this->opening = null;
            $opening->reject($failure);
            throw new RelayException('Relay unavailable', 0, $failure);
        }
        $this->socket = $socket;
        Loop::get()->spawn(fn() => $this->read($socket));
        $opening->resolve(true);
    }

    /** Why it failed or was closed, if it was: it may be while a request waits. */
    private function failure(): ?\Throwable
    {
        return $this->failure;
    }

    /** The connection's reader: hands each message to its request. */
    private function read(WebSocket $socket): void
    {
        try {
            while (($text = $socket->receive(null)) !== null) {
                $this->dispatch($text);
            }
            $this->fail(new RelayException('Connection closed by the relay'));
        } catch (TransportException $e) {
            $this->fail($e);
        }
    }

    private function dispatch(string $text): void
    {
        try {
            $message = Json::decode($text);
        } catch (\JsonException) {
            return;
        }
        if (!\is_array($message) || !array_is_list($message) || !\is_string($message[0] ?? null)) {
            return;
        }
        switch ($message[0]) {
            case 'EVENT':
                $subscription = \is_string($message[1] ?? null) ? ($this->subscriptions[$message[1]] ?? null) : null;
                if ($subscription !== null && \array_key_exists(2, $message) && $subscription->events < Protocol::MAX_EVENTS_PER_QUERY) {
                    ++$subscription->events;
                    // Of each id, the first event is the one read (SPEC.md §13, Limits): a later
                    // one (a copy, or a forgery) counts toward the query's 1000, and pages it as
                    // any event, but nothing of it is kept, opened or checked.
                    $entry = self::entryOf($message[2]);
                    if ($entry[0] !== null) {
                        if (isset($subscription->ids[$entry[0]])) {
                            $subscription->queue->push(['EVENT', $entry]);
                            break;
                        }
                        $subscription->ids[$entry[0]] = true;
                    }
                    // Of each event, only what the read needs, as it comes: its id and date
                    // (paging, by them as sent, whatever else it holds), and its NIP-01 members
                    // alone when it opens (a relay may add any other member to an event that checks
                    // out: its id and signature cover none); any other is dropped here.
                    $event = Event::fromJson($message[2]);
                    $kept = null;
                    if ($event !== null) {
                        $kept = $event->toJson();
                        if (!($subscription->keep)($kept)) {
                            $kept = null;
                        }
                    }
                    $subscription->queue->push(['EVENT', [$entry[0], $entry[1], $kept]]);
                }
                break;
            case 'EOSE':
            case 'CLOSED':
                $subscription = \is_string($message[1] ?? null) ? ($this->subscriptions[$message[1]] ?? null) : null;
                $subscription?->queue->push([$message[0], $message[2] ?? null]);
                break;
            case 'OK':
                $id = $message[1] ?? null;
                if (\is_string($id) && isset($this->oks[$id])) {
                    $answer = [($message[2] ?? null) === true, \is_string($message[3] ?? null) ? $message[3] : ''];
                    foreach ($this->oks[$id] as $future) {
                        $future->resolve($answer);
                    }
                    unset($this->oks[$id]);
                }
                break;
            case 'AUTH':
                if (\is_string($message[1] ?? null)) {
                    $this->challenge = $message[1];
                    foreach ($this->challengeWaiters as $waiter) {
                        $waiter->resolve(true);
                    }
                    $this->challengeWaiters = [];
                }
                break;
        }
    }

    /** The connection is gone: every request waiting on it fails. */
    private function fail(\Throwable $reason): void
    {
        $this->failure ??= $reason;
        $this->failedInCall = true;
        foreach ($this->subscriptions as $subscription) {
            $subscription->queue->push($reason);
        }
        foreach ($this->oks as $futures) {
            foreach ($futures as $future) {
                $future->reject($reason);
            }
        }
        $this->oks = [];
        foreach ($this->challengeWaiters as $waiter) {
            $waiter->reject($reason);
        }
        $this->challengeWaiters = [];
    }

    /** @param list<mixed> $message */
    private function send(array $message, float $deadline): void
    {
        if ($this->socket === null) {
            throw new RelayException('Not connected');
        }
        try {
            $this->socket->send(Json::encode($message), $deadline);
        } catch (TransportException $e) {
            $this->fail($e);
            throw new RelayException('Connection lost', 0, $e);
        }
    }

    /**
     * The entry of what a relay sent as an event (SPEC.md §13: what a read keeps of it, and pages
     * by): its `id` when it is a string, its `created_at` when it is a safe integer, whatever else
     * it holds, and $kept, the event when the read keeps it. A string `id` is itself up to 64
     * bytes (an event's is 64), else "#" and its SHA-256, so that what a read keeps of an id is a
     * few bytes, however long the relay made it (never equal to an id of 64 bytes or fewer).
     *
     * @return array{0: string|null, 1: int|null, 2: \stdClass|null}
     */
    public static function entryOf(mixed $value, ?\stdClass $kept = null): array
    {
        $id = Json::get($value, 'id');

        return [
            \is_string($id) ? (\strlen($id) <= 64 ? $id : '#' . hash('sha256', $id)) : null,
            Json::safeInteger(Json::get($value, 'created_at')),
            $kept,
        ];
    }

    /** @param list<mixed> $message */
    private function sendQuietly(array $message, float $deadline): void
    {
        try {
            $this->send($message, $deadline);
        } catch (RelayException) {
            // A CLOSE that cannot go: the answer is read already.
        }
    }
}
