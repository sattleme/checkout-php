<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\UnsupportedEnvironmentException;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;

/**
 * What the SDK needs of PHP, checked once when a Client is made: a shop that embeds it (a
 * WordPress plugin) gets no check from Composer.
 *
 * @internal
 */
final class Environment
{
    private static bool $checked = false;

    private function __construct() {}

    /** @throws UnsupportedEnvironmentException naming everything missing */
    public static function check(): void
    {
        if (self::$checked) {
            return;
        }
        $missing = [];
        if (\PHP_INT_SIZE !== 8) {
            $missing[] = 'a 64-bit PHP';
        }
        foreach (['bcmath', 'hash', 'json', 'openssl', 'pcre'] as $extension) {
            if (!\extension_loaded($extension)) {
                $missing[] = "the {$extension} extension";
            }
        }
        if (\extension_loaded('openssl')) {
            $curves = openssl_get_curve_names();
            if (!\is_array($curves) || !\in_array('secp256k1', $curves, true)) {
                $missing[] = 'OpenSSL with the secp256k1 curve';
            }
            if (!\in_array('chacha20', openssl_get_cipher_methods(), true)) {
                $missing[] = 'OpenSSL with the ChaCha20 cipher';
            }
            if ($missing === [] && !self::computes()) {
                // Listed, yet failing (a FIPS mode may withhold a curve it still lists).
                $missing[] = 'OpenSSL computing on secp256k1 and ChaCha20 (it failed a known-answer test)';
            }
        }
        if (\extension_loaded('pcre') && @preg_match('/^.$/u', "\u{e9}") !== 1) {
            $missing[] = 'PCRE with UTF-8 support';
        }
        if ($missing !== []) {
            throw new UnsupportedEnvironmentException('sattleme/checkout needs ' . implode(', ', $missing) . ' (PHP ' . \PHP_VERSION . ', ' . (\defined('OPENSSL_VERSION_TEXT') ? \OPENSSL_VERSION_TEXT : 'no OpenSSL') . ')');
        }
        self::$checked = true;
    }

    /**
     * Known answers: 1·G and x(2·G) (an ECDH), as SEC 2 and every secp256k1 library give them, and
     * the first ChaCha20 block of a zero key and nonce (RFC 8439, A.1).
     */
    private static function computes(): bool
    {
        try {
            $g = hex2bin('79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798');
            \assert(\is_string($g));

            return Secp256k1::xOnlyPublicKey(str_repeat("\0", 31) . "\1") === $g
                && bin2hex(Secp256k1::sharedX(str_repeat("\0", 31) . "\2", $g)) === 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5'
                && bin2hex((string) openssl_encrypt(str_repeat("\0", 16), 'chacha20', str_repeat("\0", 32), \OPENSSL_RAW_DATA, str_repeat("\0", 16))) === '76b8e0ada0f13d90405d6ae55386bd28';
        } catch (\Throwable) {
            return false;
        }
    }
}
