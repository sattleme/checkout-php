<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Crypto;

/**
 * HKDF-SHA256 (RFC 5869). PHP's hash_hkdf() runs extract and expand at once; NIP-44 needs each
 * alone, so both are written on HMAC-SHA256.
 *
 * @internal
 */
final class Hkdf
{
    private function __construct() {}

    /** HKDF-Extract: the pseudorandom key of $ikm with $salt. */
    public static function extract(string $salt, #[\SensitiveParameter] string $ikm): string
    {
        return hash_hmac('sha256', $ikm, $salt, true);
    }

    /** HKDF-Expand: $length bytes (at most 255 × 32) of the pseudorandom key $prk with $info. */
    public static function expand(#[\SensitiveParameter] string $prk, string $info, int $length): string
    {
        if ($length < 1 || $length > 255 * 32) {
            throw new \InvalidArgumentException('Invalid HKDF length');
        }
        $output = '';
        $block = '';
        for ($i = 1; \strlen($output) < $length; ++$i) {
            $block = hash_hmac('sha256', $block . $info . \chr($i), $prk, true);
            $output .= $block;
        }

        return substr($output, 0, $length);
    }

    /** SPEC.md §2: HKDF of $ikm, salt and info as UTF-8 bytes, 32 bytes of output. */
    public static function derive(#[\SensitiveParameter] string $ikm, string $salt, string $info): string
    {
        return self::expand(self::extract($salt, $ikm), $info, 32);
    }
}
