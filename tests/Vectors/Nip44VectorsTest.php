<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Crypto\Nip44Exception;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Tests\Support\Vectors;

/** The official NIP-44 v2 vectors of the pinned copy (spec/vectors/nip44/). */
final class Nip44VectorsTest extends TestCase
{
    private const FILE = 'vectors/nip44/nip44.vectors.json';

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function conversationKeys(): array
    {
        return Vectors::named(self::list('get_conversation_key'), 'sec1');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('conversationKeys')]
    public function testConversationKey(array $case): void
    {
        self::assertSame($case['conversation_key'], bin2hex(Nip44::conversationKey(self::bytes($case['sec1']), self::bytes($case['pub2']))));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidConversationKeys(): array
    {
        return Vectors::named(self::list('invalid_get_conversation_key'), 'note');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('invalidConversationKeys')]
    public function testInvalidConversationKey(array $case): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Nip44::conversationKey(self::bytes($case['sec1']), self::bytes($case['pub2']));
    }

    public function testMessageKeys(): void
    {
        $vectors = Vectors::object(self::FILE)['get_message_keys'];
        \assert(\is_array($vectors) && \is_array($vectors['keys']));
        $conversationKey = self::bytes($vectors['conversation_key']);
        self::assertNotEmpty($vectors['keys']);
        foreach ($vectors['keys'] as $case) {
            \assert(\is_array($case));
            [$chachaKey, $chachaNonce, $hmacKey] = Nip44::messageKeys($conversationKey, self::bytes($case['nonce']));
            self::assertSame($case['chacha_key'], bin2hex($chachaKey));
            self::assertSame($case['chacha_nonce'], bin2hex($chachaNonce));
            self::assertSame($case['hmac_key'], bin2hex($hmacKey));
        }
    }

    public function testPaddedLengths(): void
    {
        $cases = self::list('calc_padded_len');
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            \assert(\is_array($case) && \is_int($case[0]) && \is_int($case[1]));
            self::assertSame($case[1], Nip44::paddedLength($case[0]), "calc_padded_len({$case[0]})");
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function encryptions(): array
    {
        return Vectors::named(self::list('encrypt_decrypt'), 'plaintext');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('encryptions')]
    public function testEncryptDecrypt(array $case): void
    {
        \assert(\is_string($case['plaintext']) && \is_string($case['payload']));
        $conversationKey = self::bytes($case['conversation_key']);
        self::assertSame($case['payload'], Nip44::encrypt($case['plaintext'], $conversationKey, self::bytes($case['nonce'])));
        self::assertSame($case['plaintext'], Nip44::decrypt($case['payload'], $conversationKey));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidDecryptions(): array
    {
        return Vectors::named(self::list('invalid_decrypt'), 'note');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('invalidDecryptions')]
    public function testInvalidDecrypt(array $case): void
    {
        \assert(\is_string($case['payload']) && \is_string($case['note']));
        try {
            Nip44::decrypt($case['payload'], self::bytes($case['conversation_key']));
            self::fail('A payload that must fail decrypted');
        } catch (Nip44Exception $e) {
            // SPEC.md §12: an empty payload, a "#" first, or another version byte is a newer format.
            $newer = $case['payload'] === '' || str_starts_with($case['note'], 'unknown encryption version');
            self::assertSame($newer, $e->newerFormat, $e->getMessage());
        }
    }

    public function testTheSharedSecretIsTheUnhashedXOfTheEcdh(): void
    {
        // 3·G's x is the BIP-340 vector 0 key; 1·(3·G) = 3·G.
        $one = str_repeat("\0", 31) . "\1";
        $x = (string) hex2bin('f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9');
        self::assertSame(bin2hex($x), bin2hex(Secp256k1::sharedX($one, $x)));
    }

    /** @return list<mixed> */
    private static function list(string $name): array
    {
        $list = Vectors::object(self::FILE)[$name] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \RuntimeException("No list {$name}");
        }

        return $list;
    }

    private static function bytes(mixed $hex): string
    {
        \assert(\is_string($hex));

        return (string) hex2bin($hex);
    }
}
