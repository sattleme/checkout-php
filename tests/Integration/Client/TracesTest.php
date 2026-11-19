<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Tests\Support\World;

/**
 * The merchant's tolerance never reaches an error (SPEC.md §27), nor the merchant's Lightning
 * address: not in the arguments of an error's stack trace, which PHP keeps unless
 * zend.exception_ignore_args is On (Off is its built-in default, and php.ini-development's),
 * however a logger writes the error (print_r, var_export, json_encode of its trace).
 */
final class TracesTest extends TestCase
{
    private World $world;

    private string $ignoreArgs;

    protected function setUp(): void
    {
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $this->world = new World();
        $this->world->channel(['onchainTolerance' => 437]);
    }

    protected function tearDown(): void
    {
        $this->world->stop();
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
    }

    public function testThePendingErrorOfAnOrderCarriesNeitherTheToleranceNorTheLightningAddress(): void
    {
        // The channel, the sale's records and the lease were arguments of the frames it was thrown
        // through (Client::checkEarlierInvoices(), PaymentCheck…).
        $address = World::address();
        $this->world->lot([$address]);
        // A clock and a logger that capture nothing of the test's world, as a server's would not.
        $now = $this->world->now;
        $client = $this->world->client(['now' => static fn(): int => $now, 'logger' => static function (string $message): void {}]);
        self::assertSame($address, $client->createInvoice(['ref' => 'ORD-T', 'sats' => '49000'])->onchainAddress);
        try {
            $client->createInvoice(['ref' => 'ORD-T', 'sats' => '49000']);
            self::fail('No invoice_pending');
        } catch (InvoicePendingException $e) {
            // The frames of the SDK (the test's own, PHPUnit's, hold the test's world).
            $sdk = static function (mixed $frame): bool {
                $class = \is_array($frame) ? $frame['class'] ?? null : null;

                return \is_string($class) && str_starts_with($class, 'Sattleme\\Checkout\\') && !str_starts_with($class, 'Sattleme\\Checkout\\Tests\\');
            };
            $frames = array_values(array_filter($e->getTrace(), $sdk));
            self::assertNotSame([], array_filter(array_column($frames, 'args')), 'a trace with arguments');
            $dumps = [
                'print_r' => print_r($frames, true),
                'var_export' => var_export($frames, true),
                'json_encode' => (string) json_encode($frames, \JSON_PARTIAL_OUTPUT_ON_ERROR),
            ];
        }
        foreach ($dumps as $how => $dump) {
            self::assertDoesNotMatchRegularExpression('/tolerance\W{0,60}437/', $dump, "{$how} writes the tolerance");
            self::assertStringNotContainsString('shop@breez.tips', $dump, "{$how} writes the Lightning address");
            self::assertDoesNotMatchRegularExpression('/\Wname\W{0,40}shop\W/', $dump, "{$how} writes the Lightning address's name");
        }
    }
}
