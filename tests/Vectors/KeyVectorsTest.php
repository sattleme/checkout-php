<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\RelayList;
use Sattleme\Checkout\Tests\Support\Vectors;

/** integration-keys.json (SPEC.md §8) and relay-urls.json (§9). */
final class KeyVectorsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function keys(): array
    {
        $cases = Vectors::object('vectors/integration-keys.json')['cases'];
        \assert(\is_array($cases) && array_is_list($cases));

        return Vectors::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('keys')]
    public function testIntegrationKey(array $case): void
    {
        $expected = $case['expected'];
        \assert(\is_array($expected) && \is_string($case['key']));
        if (isset($expected['error'])) {
            self::assertSame('invalid_key', $expected['error']);
            try {
                IntegrationKey::parse($case['key']);
                self::fail('An invalid key was taken');
            } catch (InvalidKeyException $e) {
                self::assertSame('invalid_key', $e->getErrorCode());
                self::assertFalse($e->isRetryable());
                self::assertStringNotContainsString($case['key'], $e->getMessage());
            }

            return;
        }
        $key = IntegrationKey::parse($case['key']);
        self::assertSame($expected['teamPublicKey'], $key->team);
        self::assertSame($expected['integrationKey'], bin2hex($key->key->reveal()));
        self::assertSame($expected['relays'], $key->relays);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function relayUrls(): array
    {
        $cases = Vectors::object('vectors/relay-urls.json')['cases'];
        \assert(\is_array($cases) && array_is_list($cases));

        return Vectors::named($cases, 'url');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('relayUrls')]
    public function testRelayUrl(array $case): void
    {
        self::assertSame($case['valid'], RelayList::isRelayUrl($case['url']));
        self::assertSame($case['valid'], RelayList::parse([$case['url']]) !== null);
    }

    public function testRelayLists(): void
    {
        self::assertNull(RelayList::parse([]));
        self::assertNull(RelayList::parse('wss://relay.example'));
        self::assertNull(RelayList::parse(['wss://relay.example', 'wss://relay.example']));
        self::assertNull(RelayList::parse(['a' => 'wss://relay.example']));
        $eleven = array_map(static fn(int $i): string => "wss://r{$i}.example", range(0, 10));
        self::assertNull(RelayList::parse($eleven));
        self::assertSame(\array_slice($eleven, 0, 10), RelayList::parse(\array_slice($eleven, 0, 10)));
        // A port of leading zeros is a port (1 to 65535); the 120 code points count the whole URL.
        self::assertTrue(RelayList::isRelayUrl('wss://relay.example:0443'));
        self::assertTrue(RelayList::isRelayUrl('wss://relay.example/' . str_repeat('é', 100)));
        self::assertFalse(RelayList::isRelayUrl('wss://relay.example/' . str_repeat('é', 101)));
        self::assertFalse(RelayList::isRelayUrl("wss://relay.example\n"));
        self::assertFalse(RelayList::isRelayUrl("wss://relay.example/a\u{FEFF}"));
        self::assertFalse(RelayList::isRelayUrl("wss://relay.example/\xff"));
        $relays = ['wss://relay.example', 'wss://nos.lol:4443', 'wss://relay.example.ch/nostr'];
        self::assertSame($relays, RelayList::decode(RelayList::encode($relays)));
        self::assertSame(['host' => 'relay.example.ch', 'port' => 443, 'path' => '/nostr'], RelayList::endpoint('wss://relay.example.ch/nostr'));
        self::assertSame(['host' => 'nos.lol', 'port' => 4443, 'path' => '/'], RelayList::endpoint('wss://nos.lol:4443'));
        self::assertSame('/caf%C3%A9', RelayList::endpoint('wss://relay.example/café')['path']);
    }

    public function testTheKeyStaysOutOfEveryRepresentation(): void
    {
        $text = 'sattleme_ck_qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o.uPTAz1d8bbQaw2Jx22Wwrga2vI2F8allY5NrPC3Aogk.cmVsYXkuZXhhbXBsZQ';
        $key = IntegrationKey::parse($text);
        $secretHex = bin2hex($key->key->reveal());
        $secretRaw = $key->key->reveal();
        foreach ([print_r($key, true), var_export($key, true), (string) json_encode($key), print_r((array) $key, true), self::dump($key)] as $shown) {
            self::assertStringNotContainsString($secretHex, $shown);
            self::assertStringNotContainsString($secretRaw, $shown);
            self::assertStringNotContainsString('uPTAz1d8bb', $shown);
        }
        $this->expectException(\LogicException::class);
        serialize($key);
    }

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
