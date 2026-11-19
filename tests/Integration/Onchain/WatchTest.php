<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Onchain;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Onchain\Explorer;
use Sattleme\Checkout\Internal\Onchain\Watch;
use Sattleme\Checkout\Tests\Support\Net\FakeHttpServer;
use Sattleme\Checkout\Tests\Support\Net\ServerWebSocket;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;

/**
 * The explorer's WebSocket while waitForPayment waits (SPEC.md §31, "Waiting for an on-chain
 * payment"), against a local server.
 */
final class WatchTest extends TestCase
{
    public function testAConnectionLostBeforeAMessageIsAFailure(): void
    {
        // Lost, the socket connects again after 1 s, doubled at each failure: a connection the
        // explorer drops before a message is one (each connection starts a check), and one that
        // received a message sets the delay back to 1 s. Here the first two are dropped at once,
        // the third once it was sent a block: 1 s, then 2 s, then 1 s again.
        $server = new FakeHttpServer();
        $connector = new TestConnector();
        $connector->route('mempool.space', 443, $server->server);
        $connections = [];
        $server->onWebSocket('mempool.space', static function (ServerWebSocket $socket) use (&$connections): void {
            $connections[] = Loop::now();
            // What the client asks (blocks, the address), then dropped: the third sent a block first.
            $socket->receive();
            $socket->receive();
            if (\count($connections) === 3) {
                $socket->send('{"block":{"height":900000}}');
                // Not scaled (TestTime), as the bounds below: it counts in the SDK's own 1 s.
                Loop::get()->sleepUntil(Loop::now() + 0.1);
            }
        });
        try {
            $context = TestContext::create($connector);
            $watch = new Watch($context, 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4');
            Loop::get()->run(static function () use ($watch, $context, &$connections): void {
                $watch->follow(Explorer::mempool($context));
                $until = TestTime::deadline(20);
                while (\count($connections) < 4 && Loop::now() < $until) {
                    Loop::get()->sleepUntil(TestTime::deadline(0.05));
                }
            });
        } finally {
            $server->stop();
        }
        self::assertCount(4, $connections);
        [$first, $second, $third, $fourth] = $connections;
        // The SDK's own delays, which no scale changes.
        self::assertGreaterThanOrEqual(0.95, $second - $first, 'after a first failure: 1 s');
        self::assertGreaterThanOrEqual(1.95, $third - $second, 'after a second failure: 2 s');
        self::assertGreaterThanOrEqual(0.95, $fourth - $third);
        self::assertLessThan(1.9, $fourth - $third, 'after a message: 1 s again');
    }
}
