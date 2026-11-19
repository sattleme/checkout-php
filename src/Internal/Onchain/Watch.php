<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Future;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Task;
use Sattleme\Checkout\Internal\Loop\TimeoutException;
use Sattleme\Checkout\Internal\Net\TransportException;
use Sattleme\Checkout\Internal\Net\WebSocket;
use Sattleme\Checkout\Internal\Protocol;

/**
 * The explorer's WebSocket while waitForPayment waits (SPEC.md §31, "Waiting for an on-chain
 * payment"): mempool.space's, or the merchant's Bitcoin node's (`ws://` or `wss://`
 * `<url>/api/v1/ws`), of the node the last channel read names (follow()). It says when the
 * address's transactions may have changed (a connection, a message about its transactions, a new
 * block): only a signal, what it pushes is never trusted, a check asks the explorer.
 *
 * Each connection tries the WebSockets of the explorer's sources in order (Explorer::sources()):
 * one that fails, or is lost before any message, goes to the next at once (the node's to
 * mempool.space's with `fallback` "mempool", the node remembered as failed for 60 s; with "none",
 * the node's alone). Lost, it connects again after 1 s, doubled at each failure (of the last one
 * tried) up to 30 s. A connection lost before it received a message is a failure (each starts a
 * check: a server that accepts and drops every connection is not connected to every second); one
 * that received a message sets the delay back to 1 s.
 *
 * @internal
 */
final class Watch
{
    /** The messages that say something changed. */
    private const CHANGES = ['address-transactions', 'block-transactions', 'address-removed-transactions', 'block'];

    /** Without a message for that long, the socket is connected again. */
    private const QUIET_SECONDS = 300;

    private bool $changed = false;

    private ?Future $waiting = null;

    /** The task watching, of the explorer whose key() is $watched; null before follow(). */
    private ?Task $task = null;

    private ?string $watched = null;

    public function __construct(
        private readonly Context $context,
        private readonly string $address,
    ) {}

    /**
     * Watches on the WebSocket of $explorer (the explorer of the channel a check just read): from
     * the first channel read on (before, whether mempool.space may be asked is not known), and
     * again from the start when the channel names another node, or none any more.
     */
    public function follow(Explorer $explorer): void
    {
        if ($explorer->key() === $this->watched) {
            return;
        }
        $this->stop();
        $this->watched = $explorer->key();
        $this->task = Loop::get()->spawn(fn() => $this->run($explorer));
    }

    /** Stops watching (its socket closed as its task unwinds). */
    public function stop(): void
    {
        if ($this->task !== null) {
            Loop::get()->cancel($this->task);
            $this->task = null;
        }
        $this->watched = null;
    }

    /** Watches on $explorer's WebSockets until stopped or the call ends (run it as a task). */
    public function run(Explorer $explorer): void
    {
        $delayMs = Protocol::RECONNECT_FIRST_MS;
        while (true) { // @phpstan-ignore while.alwaysTrue (it runs until its call ends and drops it)
            foreach ($explorer->sources() as $source) {
                if ($this->watchOn($source['webSocket'], $delayMs)) {
                    break; // Lost after a message: connected again after the delay.
                }
                // Failed, or lost before any message: the next one at once.
                if ($source['name'] === Explorer::NODE) {
                    $explorer->nodeFailed();
                }
            }
            Loop::get()->sleepUntil(Loop::now() + $delayMs / 1000);
            $delayMs = min($delayMs * 2, Protocol::RECONNECT_MAX_MS);
        }
    }

    /**
     * Waits until something changed or $deadline (Loop::now()) passed: whether something changed
     * since the last call.
     */
    public function wait(float $deadline): bool
    {
        if (!$this->changed && $deadline > Loop::now()) {
            $this->waiting = new Future();
            try {
                $this->waiting->await($deadline);
            } catch (TimeoutException) {
                // Nothing changed in time.
            } finally {
                $this->waiting = null;
            }
        }
        $changed = $this->changed;
        $this->changed = false;

        return $changed;
    }

    /**
     * One connection to the WebSocket at $url, until it is lost: whether it received a message
     * (which sets $delayMs back to 1 s).
     */
    private function watchOn(string $url, int &$delayMs): bool
    {
        $answered = false;
        $webSocket = null;
        try {
            $webSocket = WebSocket::connect($this->context->connector, $url, Loop::now() + $this->context->httpTimeoutMs / 1000);
            $deadline = Loop::now() + $this->context->httpTimeoutMs / 1000;
            $webSocket->send(Json::encode(['action' => 'want', 'data' => ['blocks']]), $deadline);
            $webSocket->send(Json::encode(['track-address' => $this->address]), $deadline);
            $this->notify(); // What happened before was not pushed.
            // A socket may go quiet without closing: after 5 minutes without a message, connected
            // again.
            while (($message = $webSocket->receive(Loop::now() + self::QUIET_SECONDS)) !== null) {
                $answered = true;
                $delayMs = Protocol::RECONNECT_FIRST_MS; // The explorer answered.
                if ($this->isChange($message)) {
                    $this->notify();
                }
            }
        } catch (TransportException) {
            // Lost.
        } finally {
            $webSocket?->close();
        }

        return $answered;
    }

    private function notify(): void
    {
        $this->changed = true;
        $this->waiting?->resolve(true);
    }

    private function isChange(string $message): bool
    {
        try {
            $value = Json::decode($message);
        } catch (\JsonException) {
            return false;
        }
        if (!$value instanceof \stdClass) {
            return false;
        }
        foreach (self::CHANGES as $name) {
            if (Json::has($value, $name)) {
                return true;
            }
        }

        return false;
    }
}
