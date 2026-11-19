<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Net;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Http\StreamHttpClient;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Net\TransportException;
use Sattleme\Checkout\Internal\Net\WebSocket;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * A peer that never stops sending (tests/Support/Net/flood-server.php, in its own process, for
 * 10 s): it holds neither the loop, nor a deadline, nor the call's Cancellation.
 */
final class FloodTest extends TestCase
{
    /** @var resource|null */
    private $process;

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function testFramesThatKeepComingHoldNeitherTheLoopNorTheDeadline(): void
    {
        $connector = new TestConnector();
        $connector->routePort('relay.example', 443, $this->flood('ws-pong'));
        $started = Loop::now();
        $timedOut = Loop::get()->run(static function () use ($connector): bool {
            $ws = WebSocket::connect($connector, 'wss://relay.example', TestTime::deadline(2));
            // A reader that is never done, as a relay's: the call goes on beside it.
            Loop::get()->spawn(static fn() => $ws->receive(null));
            Loop::get()->sleepUntil(TestTime::deadline(0.3));
            try {
                $ws->receive(TestTime::deadline(0.3));
            } catch (TransportException) {
                return true;
            }

            return false;
        });
        self::assertTrue($timedOut);
        self::assertLessThan(TestTime::seconds(2.0), Loop::now() - $started);
    }

    public function testAPeerThatStopsReadingHoldsNoWrite(): void
    {
        // It pings without reading the pongs: they fill the connection, then the reader's pong
        // waits, holding the right to write. A message sent meanwhile still ends by its deadline
        // (it waited for the call's Cancellation before). However busy the machine, the message
        // is sent once a pong is held up: the same one for half a second. Filling the connection
        // is the reader's work, pong after pong, as much as the system's buffers hold: its pings
        // carry 125 bytes, so that their pongs fill them in 20 times fewer frames than empty ones
        // (with empty ones it took 3.5 s of CPU here, 36 s with the CPU busy beside it, and more
        // than the call's 50 s on a busy CI runner). Its times from the pong held up on are not
        // scaled (TestTime): they count against the pong's own 5 s, the SDK's, never scaled.
        $connector = new TestConnector();
        $connector->routePort('relay.example', 443, $this->flood('ws-ping', 60));
        $outcome = Loop::get()->run(static function () use ($connector): string {
            $ws = WebSocket::connect($connector, 'wss://relay.example', TestTime::deadline(5));
            $reader = Loop::get()->spawn(static fn() => $ws->receive(null));
            $locks = (new \ReflectionProperty(WebSocket::class, 'writes'))->getValue($ws);
            \assert($locks instanceof Locks);
            $held = new \ReflectionProperty(Locks::class, 'held');
            $writing = static function () use ($held, $locks): mixed {
                $frames = $held->getValue($locks);

                return \is_array($frames) ? $frames['frame'] ?? null : null;
            };
            $pong = null;
            $since = Loop::now();
            while (true) {
                Loop::get()->sleepUntil(Loop::now() + 0.1);
                if ($reader->isDone()) {
                    // Its pong gave up (5 s) before it was seen held: nothing more is written.
                    return 'the reader ended before a pong was seen held up';
                }
                $current = $writing();
                if ($current === null || $current !== $pong) {
                    [$pong, $since] = [$current, Loop::now()];
                } elseif (Loop::now() - $since >= 0.5) {
                    break; // A pong held up: the connection is full.
                }
            }
            $sendStarted = Loop::now();
            try {
                $ws->send(str_repeat('x', 1048576), Loop::now() + 0.5);
            } catch (TransportException) {
                return 'timed out after ' . round(Loop::now() - $sendStarted, 1);
            }

            return 'sent after ' . round(Loop::now() - $sendStarted, 1);
        }, new TimeoutCancellation(TestTime::seconds(50)));
        self::assertStringStartsWith('timed out', $outcome);
        // By its deadline, not once the pong gives up (5 s after it began).
        self::assertLessThan(3.0, (float) substr($outcome, \strlen('timed out after ')), $outcome);
    }

    public function testInterimAnswersWithoutEnd(): void
    {
        $connector = new TestConnector();
        $connector->routePort('lnurl.example', 443, $this->flood('http-continue'));
        $started = Loop::now();
        try {
            Loop::get()->run(static fn() => (new StreamHttpClient($connector))->get('https://lnurl.example/', [], TestTime::ms(1000)));
            self::fail('No answer expected');
        } catch (\RuntimeException $e) {
            self::assertSame('Too many interim HTTP answers', $e->getMessage());
        }
        self::assertLessThan(TestTime::seconds(2.0), Loop::now() - $started);
    }

    public function testARelayThatNeverStopsSendingHoldsNoCall(): void
    {
        $world = new World();
        try {
            $world->channel();
            $world->connector->routePort('relay1.example', 443, $this->flood('ws-pong'));
            $started = Loop::now();
            try {
                // The two other relays are a majority.
                $merchant = $world->client(['relayTimeoutMs' => 1000])->merchant(new TimeoutCancellation(TestTime::seconds(4)));
                self::assertSame(0, $merchant->onchainAddresses);
            } catch (SattlemeException $e) {
                self::fail('A majority answered: ' . $e->getMessage());
            }
            self::assertLessThan(TestTime::seconds(3.0), Loop::now() - $started);
        } finally {
            $world->stop();
        }
    }

    /** Starts a flooding peer of $mode, for $seconds (scaled: TestTime): its port. */
    private function flood(string $mode, int $seconds = 10): int
    {
        $script = \dirname(__DIR__, 2) . '/Support/Net/flood-server.php';
        $process = proc_open([\PHP_BINARY, $script, $mode, (string) ($seconds * TestTime::scale())], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $port = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/^[0-9]+$/D', $port);

        return (int) $port;
    }
}
