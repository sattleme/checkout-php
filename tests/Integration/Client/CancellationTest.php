<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * A cancelled call stops its requests and fails with CancelledException (SPEC.md §4), also when
 * the cancellation comes while a step blocks the process: PHP looks a host's name up before each
 * connection, blocking (README, "Known limits"), as a client of the integrator's may block for a
 * whole request. Here the connector and the HTTP client sleep for 1 s, as a slow resolver would.
 */
final class CancellationTest extends TestCase
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

    public function testNoLookupStartsOnceTheCallIsCancelledAndTheCallFailsWithIt(): void
    {
        // The first relay's lookup blocks past the cancellation: the call fails with it once the
        // lookup returns, and starts no other.
        $connector = new BlockingConnector($this->world->connector);
        $client = $this->world->client(['connector' => $connector]);
        try {
            $client->merchant(new TimeoutCancellation(TestTime::seconds(0.2)));
            self::fail('Not cancelled');
        } catch (CancelledException) {
        }
        self::assertSame(1, $connector->lookups);
    }

    public function testALookupThatFailsAfterTheCancellationIsTheCancellation(): void
    {
        // Each relay's name cannot be looked up, after 1 s: the call is cancelled meanwhile, and
        // fails with the cancellation, not with the relays' failure (network).
        $connector = new BlockingConnector($this->world->connector, true);
        $client = $this->world->client(['connector' => $connector]);
        try {
            $client->merchant(new TimeoutCancellation(TestTime::seconds(0.2)));
            self::fail('Not cancelled');
        } catch (CancelledException) {
        }
        self::assertSame(1, $connector->lookups);
    }

    public function testAnAnswerThatCameAfterTheCancellationIsTheCancellation(): void
    {
        // An HTTP client that blocks for 1 s, then answers the verify URL without the loop: the
        // Lightning part's answer comes after the cancellation, which wins over it.
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-1', 'sats' => '500']);
        $http = new BlockingHttpClient(static fn(): HttpResponse => new HttpResponse(200, Json::encode(['status' => 'OK', 'settled' => false, 'preimage' => null, 'pr' => $invoice->bolt11])));
        $blocked = $this->world->client(['httpClient' => $http]);
        try {
            $blocked->getStatus($invoice, new TimeoutCancellation(TestTime::seconds(0.2)));
            self::fail('Not cancelled');
        } catch (CancelledException) {
        }
        self::assertSame(1, $http->requests);
        // Not cancelled: its status.
        self::assertSame(PaymentStatus::OPEN, $blocked->getStatus($invoice)->state);
    }
}

/** A connector that blocks the process for 1 s (scaled: TestTime) before each connection, as a slow resolver does. */
final class BlockingConnector implements Connector
{
    public int $lookups = 0;

    public function __construct(private readonly Connector $connector, private readonly bool $fails = false) {}

    public function connect(string $host, int $port, int $timeoutMs): mixed
    {
        ++$this->lookups;
        TestTime::block(1000);
        if ($this->fails) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port}: getaddrinfo failed");
        }

        return $this->connector->connect($host, $port, $timeoutMs);
    }
}

/** An HTTP client that blocks the process for 1 s (scaled: TestTime), then gives its answer. */
final class BlockingHttpClient implements HttpClient
{
    public int $requests = 0;

    /** @param \Closure(): HttpResponse $answer */
    public function __construct(private readonly \Closure $answer) {}

    public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
    {
        ++$this->requests;
        TestTime::block(1000);

        return ($this->answer)();
    }
}
