<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Differential;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Crypto\Schnorr;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;

/**
 * The SDK's BIP-340 and ECDH on OpenSSL against libsecp256k1 (the exception to PORTING.md step 2:
 * the curve's operations are OpenSSL's, the BIP-340 steps the SDK's): every signature byte for
 * byte, every verification's verdict, every shared x, every x-only key's validity. The cases come
 * from tools/differential/generate.py (coincurve), whose file SATTLEME_DIFFERENTIAL_CASES names: CI.
 */
#[Group('differential')]
final class LibSecp256k1Test extends TestCase
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $cases = null;

    public function testSignatures(): void
    {
        foreach (self::cases('signatures') as $case) {
            $secret = self::bytes($case['secret']);
            self::assertSame($case['public'], bin2hex(Secp256k1::xOnlyPublicKey($secret)));
            self::assertSame($case['signature'], bin2hex(Schnorr::sign($secret, self::bytes($case['message']), self::bytes($case['auxiliary']))), (string) json_encode($case));
        }
    }

    public function testVerifications(): void
    {
        foreach (self::cases('verifications') as $case) {
            self::assertSame($case['valid'], Schnorr::verify(self::bytes($case['public']), self::bytes($case['message']), self::bytes($case['signature'])), (string) json_encode($case));
        }
    }

    public function testSharedX(): void
    {
        foreach (self::cases('ecdh') as $case) {
            self::assertSame($case['shared'], bin2hex(Secp256k1::sharedX(self::bytes($case['secret']), self::bytes($case['public']))));
        }
    }

    public function testXOnlyPublicKeys(): void
    {
        foreach (self::cases('xonly') as $case) {
            self::assertSame($case['valid'], Secp256k1::isXOnlyPublicKey(self::bytes($case['x'])), (string) json_encode($case));
        }
    }

    /** @return list<array<string, mixed>> */
    private static function cases(string $kind): array
    {
        if (self::$cases === null) {
            $file = getenv('SATTLEME_DIFFERENTIAL_CASES');
            if (!\is_string($file) || $file === '') {
                self::markTestSkipped('SATTLEME_DIFFERENTIAL_CASES names no file of tools/differential/generate.py');
            }
            $decoded = json_decode((string) file_get_contents($file), true, 16, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded) && \is_array($decoded['cases'] ?? null));
            /** @var array<string, list<array<string, mixed>>> $cases */
            $cases = $decoded['cases'];
            self::$cases = $cases;
        }
        $cases = self::$cases[$kind] ?? [];
        self::assertGreaterThan(0, \count($cases));

        return $cases;
    }

    private static function bytes(mixed $hex): string
    {
        \assert(\is_string($hex));
        $bytes = hex2bin($hex);
        \assert($bytes !== false);

        return $bytes;
    }
}
