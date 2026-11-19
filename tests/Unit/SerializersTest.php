<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Channel;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Onchain\Explorer;
use Sattleme\Checkout\Internal\Onchain\Leasing;
use Sattleme\Checkout\Internal\Onchain\LiveSource;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptanceKeys;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\WitnessKeys;
use Sattleme\Checkout\Internal\PaymentCheck;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;

/**
 * SPEC.md §27: no serializer that an application commonly calls on what it logs or returns writes
 * a key. The client, and every object that holds the integration key, a key derived from it or
 * CoinGecko's key: json_encode, var_export, serialize (refused, or without it), print_r and
 * var_dump (__debugInfo where there is one) show none of them, as bytes, hex or base64.
 */
final class SerializersTest extends TestCase
{
    /** The integration key of the app's vector, as text. */
    private const KEY = 'sattleme_ck_qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o.uPTAz1d8bbQaw2Jx22Wwrga2vI2F8allY5NrPC3Aogk.cmVsYXkuZXhhbXBsZQ';

    private const COINGECKO_KEY = 'CG-DEMOKEY0123456789';

    /** @return iterable<string, array{0: \Closure(): object}> */
    public static function holders(): iterable
    {
        $key = static fn(): IntegrationKey => IntegrationKey::parse(self::KEY);
        $registry = static fn(): Registry => new Registry(new Secret(random_bytes(32)), Derive::author(random_bytes(31) . "\x02"));
        $context = static fn() => TestContext::create(new TestConnector());
        yield 'the client' => [static fn(): object => new Client(['key' => self::KEY, 'coingeckoApiKey' => self::COINGECKO_KEY])];
        yield 'the integration key' => [static fn(): object => $key()];
        yield "an order's sale, its inner key made" => [static function () use ($key, $registry): object {
            $sale = Sale::of($key(), 'ORD-1');
            $sale->innerKey($registry());

            return $sale;
        }];
        yield 'the registry' => [static fn(): object => $registry()];
        yield 'the channel' => [static fn(): object => self::channel()];
        yield "an invoice's rest keys" => [static fn(): object => RestKeys::of($key(), 'ORD-1', 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4', 1791382900)];
        yield 'the witness keys' => [static fn(): object => WitnessKeys::of($key())];
        yield "an invoice's merchant's acceptance keys" => [static fn(): object => MerchantAcceptanceKeys::of($key(), Sale::of($key(), 'ORD-1')->author, 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4', 1791382900)];
        yield "a payment check's on-chain source" => [static fn(): object => new LiveSource($context(), new RelayPool($context()), $key(), ['wss://relay.example'], Explorer::mempool($context()))];
        yield 'a payment check' => [static fn(): object => new PaymentCheck($context(), new RelayPool($context()), $key(), self::channel(), ['wss://relay.example'], new Locks(), Explorer::mempool($context()))];
        yield 'a leasing' => [static fn(): object => new Leasing(new RelayPool($context()), $context(), $key(), $registry(), ['wss://relay.example'], Explorer::mempool($context()))];
    }

    /** @param \Closure(): object $make */
    #[DataProvider('holders')]
    public function testNoSerializerWritesAKey(\Closure $make): void
    {
        $holder = $make();
        $secrets = self::secretsOf($holder);
        if ($holder instanceof Client) {
            $secrets[] = self::KEY;
            $secrets[] = self::COINGECKO_KEY;
        }
        self::assertNotEmpty($secrets, 'it holds a key');
        $written = [
            'json_encode' => (string) json_encode($holder, \JSON_PARTIAL_OUTPUT_ON_ERROR),
            'json_encode of its array' => (string) json_encode((array) $holder, \JSON_PARTIAL_OUTPUT_ON_ERROR),
            'var_export' => var_export($holder, true),
            'print_r' => print_r($holder, true),
        ];
        ob_start();
        var_dump($holder);
        $written['var_dump'] = (string) ob_get_clean();
        if (method_exists($holder, '__debugInfo')) {
            $written['__debugInfo'] = print_r($holder->__debugInfo(), true);
        }
        try {
            $written['serialize'] = serialize($holder);
        } catch (\LogicException|\Exception) {
            // Refused: a secret, or what holds one (or a closure), is never serialized.
        }
        foreach ($written as $serializer => $text) {
            foreach ($secrets as $secret) {
                foreach ([$secret, bin2hex($secret), base64_encode($secret), Base64::encodeUrl($secret)] as $form) {
                    self::assertStringNotContainsString($form, $text, $serializer);
                }
            }
        }
    }

    /**
     * The values of every Secret $value holds, in its properties or in the variables of its
     * closures, however deep: the keys a serializer could reach (16 bytes or more: no tolerance).
     *
     * @param array<int, true> $seen
     *
     * @return list<string>
     */
    private static function secretsOf(mixed $value, array &$seen = []): array
    {
        if (\is_array($value)) {
            $found = [];
            foreach ($value as $item) {
                $found = [...$found, ...self::secretsOf($item, $seen)];
            }

            return $found;
        }
        if (!\is_object($value) || isset($seen[spl_object_id($value)])) {
            return [];
        }
        $seen[spl_object_id($value)] = true;
        if ($value instanceof Secret) {
            return \strlen($value->reveal()) >= 16 ? [$value->reveal()] : [];
        }
        if ($value instanceof \Closure) {
            return self::secretsOf(array_values((new \ReflectionFunction($value))->getStaticVariables()), $seen);
        }
        $found = [];
        for ($class = new \ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic() && $property->isInitialized($value)) {
                    $found = [...$found, ...self::secretsOf($property->getValue($value), $seen)];
                }
            }
        }

        return $found;
    }

    /** A channel of the integration, with its registry key. */
    private static function channel(): Channel
    {
        $team = random_bytes(31) . "\x01";
        $event = Event::sign($team, 30078, 1791382000, [['d', str_repeat('ab', 32)]], 'x', random_bytes(32));
        $value = Json::decode(Json::encode([
            'version' => 1, 'status' => 'active', 'role' => 'integration', 'updatedAt' => 1791382000, 'onchainTolerance' => 437,
            'profile' => ['businessName' => 'Sunrise Bakery', 'lightningAddress' => 'shop@breez.tips'],
            'registryKey' => bin2hex(random_bytes(32)), 'registryReader' => Derive::author(random_bytes(31) . "\x02"),
        ]));
        $channel = Channel::validate($value, $event)[1] ?? null;
        self::assertInstanceOf(Channel::class, $channel);
        self::assertSame(437, $channel->tolerance());

        return $channel;
    }
}
