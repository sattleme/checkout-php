<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Cancellation;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Exception\RateException;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * A callback of a call (onStatus, a custom rate source, the logger) that calls the client: refused
 * at once, never a call waiting on another that waits on it.
 */
final class CallbacksTest extends TestCase
{
    private World $world;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->channel();
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    public function testARateSourceThatCallsTheClient(): void
    {
        $client = null;
        $client = $this->world->client(['rateSource' => static function (string $currency, Cancellation $cancellation) use (&$client): string {
            \assert($client instanceof Client);
            $client->merchant();

            return '100000';
        }]);
        $started = Loop::now();
        try {
            $client->createInvoice(['ref' => 'ORD-1', 'fiat' => ['amount' => '10', 'currency' => 'CHF']], new TimeoutCancellation(TestTime::seconds(10)));
            self::fail('A nested call is refused');
        } catch (\LogicException $e) {
            self::assertStringContainsString('called from a callback of one of its calls', $e->getMessage());
        }
        self::assertLessThan(TestTime::seconds(5), Loop::now() - $started);
        // The client works again once that call returned.
        self::assertSame('Sunrise Bakery', $client->merchant()->businessName);
    }

    public function testAnOnStatusThatCallsTheClient(): void
    {
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-2', 'sats' => '500']);
        $this->expectException(\LogicException::class);
        $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => static function (PaymentStatus $status) use ($client): void {
            $client->findInvoices('ORD-2');
        }], new TimeoutCancellation(TestTime::seconds(10)));
    }

    public function testALoggerThatCallsTheClientFailsNothing(): void
    {
        $client = null;
        $logged = 0;
        $client = $this->world->client([
            'rateSource' => [
                static fn(string $currency, Cancellation $cancellation): string => throw new RateException('Down'),
                static fn(string $currency, Cancellation $cancellation): string => '100000',
            ],
            'logger' => static function (string $message) use (&$client, &$logged): void {
                ++$logged;
                \assert($client instanceof Client);
                $client->merchant();
            },
        ]);
        $invoice = $client->createInvoice(['ref' => 'ORD-3', 'fiat' => ['amount' => '10', 'currency' => 'CHF']]);
        self::assertSame('10000', $invoice->sats);
        self::assertSame(1, $logged);
    }
}
