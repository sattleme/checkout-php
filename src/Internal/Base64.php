<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * Base64 (RFC 4648) decoded strictly (SPEC.md §2): only the canonical encoding of some bytes is
 * accepted. PHP's base64_decode() skips characters outside the alphabet unless strict, and even
 * strict it accepts non-zero trailing bits and misplaced padding: the text is checked against the
 * alphabet first, decoded, then encoded again and compared.
 *
 * @internal
 */
final class Base64
{
    private function __construct() {}

    /** Base64url without padding (RFC 4648 §5). */
    public static function encodeUrl(#[\SensitiveParameter] string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The bytes of unpadded base64url $text, or null when it is not their canonical encoding. */
    public static function decodeUrl(#[\SensitiveParameter] string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $text) !== 1 || \strlen($text) % 4 === 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false || self::encodeUrl($bytes) !== $text) {
            return null;
        }

        return $bytes;
    }

    /** Standard base64 with padding (RFC 4648 §4), as NIP-44 payloads use it. */
    public static function encode(string $bytes): string
    {
        return base64_encode($bytes);
    }

    /** The bytes of padded standard base64 $text, or null when it is not their canonical encoding. */
    public static function decode(string $text): ?string
    {
        if (\strlen($text) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode($text, true);
        if ($bytes === false || base64_encode($bytes) !== $text) {
            return null;
        }

        return $bytes;
    }
}
