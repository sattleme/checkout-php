<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Exception\InvalidOptionException;

/** SPEC.md §4: the options of the client, checked at construction. */
final class ClientOptionsTest extends TestCase
{
    // The app's vector of integration-keys.json.
    private const KEY = 'sattleme_ck_qEVejHuCZpF4XMmBSw5p7iZYxCtm20XxzT1fnJ9VB8o.uPTAz1d8bbQaw2Jx22Wwrga2vI2F8allY5NrPC3Aogk.cmVsYXkuZXhhbXBsZQ';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function valid(): iterable
    {
        yield 'no option but the key' => [[]];
        yield 'a Demo key' => [['coingeckoApiKey' => 'CG-DEMOKEY0123456789']];
        yield 'a key of one character' => [['coingeckoApiKey' => '!']];
        yield 'a long key: no length cap' => [['coingeckoApiKey' => str_repeat('~', 300)]];
        yield 'no key (null)' => [['coingeckoApiKey' => null]];
        yield 'the shortest timeouts' => [['relayTimeoutMs' => 100, 'httpTimeoutMs' => 100]];
        yield 'the longest timeouts' => [['relayTimeoutMs' => 60000, 'httpTimeoutMs' => 60000]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('valid')]
    public function testValidOptions(array $options): void
    {
        $this->expectNotToPerformAssertions();
        new Client(['key' => self::KEY] + $options);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'an empty key: invalid, not absent' => [['coingeckoApiKey' => ''], 'coingeckoApiKey'];
        yield 'a key with a space' => [['coingeckoApiKey' => 'CG-DEMO KEY'], 'coingeckoApiKey'];
        yield 'a key with a line break' => [['coingeckoApiKey' => "CG-DEMOKEY\r\nX-Other: 1"], 'coingeckoApiKey'];
        yield 'a key with DEL' => [['coingeckoApiKey' => "CG-DEMOKEY\x7F"], 'coingeckoApiKey'];
        yield 'a key not ASCII' => [['coingeckoApiKey' => 'CG-DÉMO'], 'coingeckoApiKey'];
        yield 'a key not a string' => [['coingeckoApiKey' => 42], 'coingeckoApiKey'];
        yield 'a relay timeout too short' => [['relayTimeoutMs' => 99], 'relayTimeoutMs'];
        yield 'a relay timeout too long' => [['relayTimeoutMs' => 60001], 'relayTimeoutMs'];
        yield 'a relay timeout not an integer' => [['relayTimeoutMs' => 1000.0], 'relayTimeoutMs'];
        yield 'an HTTP timeout as text' => [['httpTimeoutMs' => '1000'], 'httpTimeoutMs'];
        yield 'an unknown option' => [['timeout' => 1000], 'options'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalid')]
    public function testInvalidOptions(array $options, string $param): void
    {
        try {
            new Client(['key' => self::KEY] + $options);
            self::fail('Invalid');
        } catch (InvalidOptionException $e) {
            self::assertSame($param, $e->getParam());
            self::assertSame('invalid_option', $e->getErrorCode());
        }
    }
}
