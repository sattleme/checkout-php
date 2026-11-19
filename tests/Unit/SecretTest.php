<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Channel;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;
use Sattleme\Checkout\Tests\Support\World;

/** SPEC.md §27: a secret shows in no dump, no serialization, no copy. */
final class SecretTest extends TestCase
{
    private const VALUE = 'TOPSECRETVALUE-0123456789';

    public function testNoDumpShowsIt(): void
    {
        $secret = new Secret(self::VALUE);
        $closure = static fn(): string => $secret->reveal(); // as the SDK captures them
        $holder = new \stdClass();
        $holder->secret = $secret;
        $holder->closure = $closure;
        ob_start();
        var_dump($holder, (array) $secret, (array) $holder);
        $dumps = (string) ob_get_clean();
        $dumps .= print_r($holder, true) . print_r((array) $secret, true) . var_export((array) $secret, true);
        $dumps .= (string) json_encode($holder) . (string) json_encode((array) $secret);
        self::assertStringNotContainsString(self::VALUE, $dumps);
        self::assertSame(self::VALUE, $secret->reveal());
        self::assertSame(self::VALUE, $closure());
    }

    public function testNoDumpOfAClientShowsItsKey(): void
    {
        // The app's vector of integration-keys.json: its secret part, as text and as hex.
        $key = 'sattleme_ck_qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o.uPTAz1d8bbQaw2Jx22Wwrga2vI2F8allY5NrPC3Aogk.cmVsYXkuZXhhbXBsZQ';
        $secretPart = explode('.', substr($key, 7))[1];
        $secretHex = bin2hex(Base64::decodeUrl($secretPart) ?? '');
        $client = new Client(['key' => $key, 'coingeckoApiKey' => 'CG-DEMOKEY0123456789']);
        ob_start();
        var_dump($client, (array) $client);
        $dumps = (string) ob_get_clean();
        $dumps .= print_r($client, true) . print_r((array) $client, true) . var_export($client, true) . (string) json_encode((array) $client);
        foreach ([$key, $secretPart, $secretHex, 'CG-DEMOKEY0123456789'] as $secret) {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $dumps);
        }
    }

    public function testNoDumpOfAChannelOrALeaseShowsTheTolerance(): void
    {
        // SPEC.md §27: the merchant's tolerance is in no log nor error, so in no dump of what holds
        // it (a channel, an order's lease); nor the merchant's Lightning address in their debug
        // dumps (var_dump, print_r: __debugInfo), as in no trace (#[\SensitiveParameter]).
        $team = random_bytes(31) . "\x01";
        $reader = Derive::author(random_bytes(31) . "\x02");
        $event = Event::sign($team, 30078, 1791382000, [['d', str_repeat('ab', 32)]], 'x', random_bytes(32));
        $value = Json::decode(Json::encode([
            'version' => 1, 'status' => 'active', 'role' => 'integration', 'updatedAt' => 1791382000, 'onchainTolerance' => 437,
            'profile' => ['businessName' => 'Sunrise Bakery', 'lightningAddress' => 'tolerant-shop@breez.tips'],
            'registryKey' => bin2hex(random_bytes(32)), 'registryReader' => $reader,
        ]));
        $channel = Channel::validate($value, $event)[1] ?? null;
        self::assertInstanceOf(Channel::class, $channel);
        self::assertSame(437, $channel->tolerance());
        $key = IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']);
        $sale = Sale::of($key, 'ORD-1');
        $registry = new Registry(new Secret(random_bytes(32)), $reader);
        $address = World::address();
        $inside = Lease::inside($address, 49000, null, null, 1791382000, 1791382900, $sale->keyHex(), ['minor' => 49000, 'digits' => 0, 'currency' => 'SAT'], 'ORD-1', $sale->takerTag, 437, true);
        $lease = Lease::open(Lease::event($sale, $registry, $address, $inside, 1791382000, TestContext::create(new TestConnector()))->toJson(), $sale, $registry);
        self::assertNotNull($lease);
        self::assertSame('ok', $lease[0]);
        $lease = $lease[1];
        self::assertSame(437, $lease->tolerance());
        foreach ([$channel, $lease] as $holder) {
            ob_start();
            var_dump($holder);
            $debug = (string) ob_get_clean() . print_r($holder, true);
            ob_start();
            var_dump((array) $holder);
            $dumps = $debug . (string) ob_get_clean() . print_r((array) $holder, true) . var_export($holder, true) . (string) json_encode($holder) . (string) json_encode((array) $holder, \JSON_PARTIAL_OUTPUT_ON_ERROR);
            self::assertDoesNotMatchRegularExpression('/tolerance\W{0,80}437/', $dumps, $holder::class);
            self::assertStringNotContainsString('tolerant-shop', $debug, $holder::class);
        }
    }

    public function testNeverSerializedNorCloned(): void
    {
        $secret = new Secret(self::VALUE);
        try {
            serialize($secret);
            self::fail('Serialized');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $copy = clone $secret;
        unset($copy);
    }

    public function testStillReadAtShutdown(): void
    {
        $file = (new \ReflectionClass(Secret::class))->getFileName();
        self::assertIsString($file);
        $script = \dirname(__DIR__) . '/Support/secret-at-shutdown.php';
        $command = [\PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', $script, $file, Secret::class];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame('', $errors);
        self::assertSame('VALUE', $output);
    }

    public function testEachKeepsItsOwnValue(): void
    {
        // An object id freed with its Secret serves the next: the value goes with it.
        for ($i = 0; $i < 100; ++$i) {
            $secret = new Secret("value {$i}");
            self::assertSame("value {$i}", $secret->reveal());
            unset($secret);
        }
        $a = new Secret('a');
        $b = new Secret('b');
        self::assertSame(['a', 'b'], [$a->reveal(), $b->reveal()]);
    }
}
