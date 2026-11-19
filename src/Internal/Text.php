<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * The text rules every implementation applies alike (SPEC.md §6): lengths in Unicode code points,
 * trimming and case of ASCII only, strict UTF-8. Built on PCRE, which PHP always has, rather than
 * on mbstring, which a host may lack (WordPress then defines look-alike global functions).
 *
 * @internal
 */
final class Text
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private function __construct() {}

    /**
     * Whether $bytes is valid UTF-8 (RFC 3629: no overlong form, no surrogate, nothing above
     * U+10FFFF), never repaired.
     */
    public static function isUtf8(string $bytes): bool
    {
        return preg_match('//u', $bytes) === 1;
    }

    /** Unicode code points of valid UTF-8 $text: its bytes but the continuation bytes. */
    public static function codePoints(string $text): int
    {
        return \strlen($text) - (int) preg_match_all('/[\x80-\xBF]/', $text);
    }

    /** $text with A–Z lowered to a–z, and nothing else changed (never Unicode case mapping). */
    public static function asciiLower(string $text): string
    {
        return strtr($text, self::UPPER, self::LOWER);
    }

    /** $text without leading and trailing spaces (U+0020): a note's trimming. */
    public static function trimSpaces(string $text): string
    {
        return trim($text, ' ');
    }

    /**
     * $text without leading and trailing ASCII whitespace: space, tab, line feed and carriage
     * return (SPEC.md §6, a Lightning address's trimming).
     */
    public static function trimAscii(string $text): string
    {
        return trim($text, " \t\n\r");
    }

    /** Whether $text holds a C0 control character (U+0000 to U+001F) or DEL (U+007F). */
    public static function hasControl(string $text): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $text) === 1;
    }

    /** Whether $value is lowercase hex of exactly $bytes bytes. */
    public static function isHex(mixed $value, int $bytes): bool
    {
        return \is_string($value)
            && \strlen($value) === 2 * $bytes
            && preg_match('/^[0-9a-f]*$/D', $value) === 1;
    }

    /** Whether $value is hex32: 64 lowercase hex characters (SPEC.md §2). */
    public static function isHex32(mixed $value): bool
    {
        return self::isHex($value, 32);
    }
}
