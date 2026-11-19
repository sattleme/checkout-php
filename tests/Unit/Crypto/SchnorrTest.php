<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Crypto\Schnorr;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;

/**
 * BIP-340 against its official test vectors (tests/fixtures/bip340/, see SOURCE.md there):
 * signatures with their auxiliary random data, public keys, and every verification result.
 */
final class SchnorrTest extends TestCase
{
    private const VECTORS_SHA256 = '34c9d1d9c3a88d524bc80778540dc43f8306ec249a7485293063c376db851c2d';

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function officialVectors(): array
    {
        $path = \dirname(__DIR__, 2) . '/fixtures/bip340/test-vectors.csv';
        if (hash_file('sha256', $path) !== self::VECTORS_SHA256) {
            throw new \RuntimeException('tests/fixtures/bip340/test-vectors.csv is not the pinned file');
        }
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        \assert(\is_array($lines));
        $header = array_map(strval(...), str_getcsv((string) array_shift($lines), ',', '"', ''));
        $cases = [];
        foreach ($lines as $line) {
            $row = array_combine($header, array_map(strval(...), str_getcsv($line, ',', '"', '')));
            $cases['vector ' . $row['index'] . ($row['comment'] !== '' ? ': ' . $row['comment'] : '')] = [$row];
        }
        if (\count($cases) !== 19) {
            throw new \RuntimeException('19 BIP-340 vectors expected');
        }

        return $cases;
    }

    /**
     * @param array<string, string> $vector
     */
    #[DataProvider('officialVectors')]
    public function testOfficialVector(array $vector): void
    {
        $publicKey = (string) hex2bin($vector['public key']);
        $message = (string) hex2bin($vector['message']);
        $signature = (string) hex2bin($vector['signature']);
        if ($vector['secret key'] !== '') {
            $secret = (string) hex2bin($vector['secret key']);
            self::assertSame(bin2hex($publicKey), bin2hex(Secp256k1::xOnlyPublicKey($secret)));
            self::assertSame(
                strtolower($vector['signature']),
                bin2hex(Schnorr::sign($secret, $message, (string) hex2bin($vector['aux_rand']))),
            );
        }
        self::assertSame($vector['verification result'] === 'TRUE', Schnorr::verify($publicKey, $message, $signature));
    }

    public function testSigningRefusesASecretOutOfRange(): void
    {
        foreach (['0000000000000000000000000000000000000000000000000000000000000000', 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141', str_repeat('ff', 32)] as $secret) {
            try {
                Schnorr::sign((string) hex2bin($secret), '', str_repeat("\0", 32));
                self::fail("A secret of {$secret} was taken");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSignaturesOfRandomKeysAndMessagesVerify(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $secret = random_bytes(32);
            if (!Secp256k1::isSecretKey($secret)) {
                continue;
            }
            $message = $i > 0 ? random_bytes($i) : '';
            $signature = Schnorr::sign($secret, $message, random_bytes(32));
            $publicKey = Secp256k1::xOnlyPublicKey($secret);
            self::assertTrue(Schnorr::verify($publicKey, $message, $signature));
            $tampered = $signature;
            $tampered[$i % 64] = \chr(\ord($tampered[$i % 64]) ^ 1);
            self::assertFalse(Schnorr::verify($publicKey, $message, $tampered));
            self::assertFalse(Schnorr::verify($publicKey, $message . 'x', $signature));
        }
    }

    public function testVerificationRefusesMalformedInput(): void
    {
        self::assertFalse(Schnorr::verify(str_repeat("\1", 31), '', str_repeat("\0", 64)));
        self::assertFalse(Schnorr::verify(str_repeat("\1", 32), '', str_repeat("\0", 63)));
    }
}
