<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * JSON as every implementation reads it (RFC 8259, SPEC.md §2): as JavaScript's JSON.parse reads
 * it, but for a text in which more than MAX_DEPTH arrays and objects nest one in another, which is
 * not JSON. Objects decode to stdClass, so that an object and an array stay apart; of duplicate
 * names the last one counts; a name that differs only by its case is another name. A number
 * decodes as PHP does: an integer within 64 bits as an int, anything else as a float (a double, as
 * JavaScript's: an integer of any length, beyond the largest double infinite, as `1e400`), which
 * is what SPEC.md reads (a safe integer, a price as a double); never JSON_BIGINT_AS_STRING.
 *
 * Two texts JavaScript reads and json_decode refuses whole are read here too: a string holding a
 * lone surrogate decodes as a LoneSurrogate, and a member whose name starts with U+0000 (stdClass
 * cannot hold one) is left out, as a member whose name holds a lone surrogate: no name SPEC.md
 * reads holds either. A name holding U+0000 further on is kept as it is.
 *
 * Encoding writes what JavaScript's JSON.stringify writes for the same values: no escaped slash,
 * no escaped non-ASCII character, U+2028 and U+2029 as they are (NIP-01's serialization).
 *
 * @internal
 */
final class Json
{
    /** The most arrays and objects that nest one in another in a JSON text (SPEC.md §2). */
    public const MAX_DEPTH = 64;

    private const ENCODE = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_THROW_ON_ERROR;

    /**
     * json_decode's $depth for MAX_DEPTH: it counts the values inside the innermost array or object
     * as one more level (a depth of 1 refuses `[]`; 64 refuses 64 arrays nested, 65 reads them),
     * as PHP 8.2 to 8.5 do (JsonTest).
     */
    private const DEPTH = self::MAX_DEPTH + 1;

    private function __construct() {}

    /**
     * The value of JSON $text: a relay's message, an HTTP answer, a record's decrypted plaintext
     * (a secret: never in a trace).
     *
     * @throws \JsonException when it is not JSON (or not UTF-8)
     */
    public static function decode(#[\SensitiveParameter] string $text): mixed
    {
        try {
            return json_decode($text, false, self::DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($e->getCode() !== \JSON_ERROR_UTF16 && $e->getCode() !== \JSON_ERROR_INVALID_PROPERTY_NAME) {
                throw $e;
            }
        }
        // A lone surrogate, or a name starting with U+0000: each such escape is read as a sentinel
        // no text holds (a random one: nobody can write it), then the strings and names holding it
        // as SPEC.md §2 reads them. Escapes are read left to right, so that an escaped backslash is
        // never read as the start of one.
        $lone = 'lone-surrogate-' . bin2hex(random_bytes(16));
        $nul = 'nul-' . bin2hex(random_bytes(16));
        $escaped = preg_replace_callback(
            '/\\\\(?:u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}|(u[dD][89a-fA-F][0-9a-fA-F]{2})|(u0000)|.)/s',
            static fn(array $match): string => match (true) {
                ($match[1] ?? '') !== '' => $lone,
                ($match[2] ?? '') !== '' => $nul,
                default => $match[0],
            },
            $text,
        );
        if ($escaped === null) {
            throw new \JsonException('Not UTF-8', \JSON_ERROR_UTF8);
        }

        return self::marked(json_decode($escaped, false, self::DEPTH, \JSON_THROW_ON_ERROR), $lone, $nul);
    }

    /** $value as JSON text. */
    public static function encode(#[\SensitiveParameter] mixed $value): string
    {
        return json_encode($value, self::ENCODE);
    }

    /** Whether $value is a JSON object. */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass;
    }

    /** Whether the JSON object $object has a member $name (its value may be null). */
    public static function has(\stdClass $object, string $name): bool
    {
        return property_exists($object, $name);
    }

    /** The member $name of $object, or null when it has none. */
    public static function get(mixed $object, string $name): mixed
    {
        if (!$object instanceof \stdClass || !property_exists($object, $name)) {
            return null;
        }

        return $object->{$name};
    }

    /** Whether $value is a JSON array. */
    public static function isArray(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value);
    }

    /**
     * $value as an integer when it is a JSON number whose value is an integer of at most 2^53 − 1
     * in absolute value (a "safe integer", as JavaScript's Number.isSafeInteger: 1.0 and 1e3 are
     * ones); else null.
     */
    public static function safeInteger(mixed $value): ?int
    {
        if (\is_int($value)) {
            return abs($value) <= Protocol::MAX_SAFE_INTEGER ? $value : null;
        }
        if (\is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= Protocol::MAX_SAFE_INTEGER) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Whether $value is a JSON number whose value is an integer (JavaScript's Number.isInteger),
     * whatever its size.
     */
    public static function isInteger(mixed $value): bool
    {
        return \is_int($value) || (\is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /** $value as an integer from $min to $max (both safe), else null. */
    public static function intIn(mixed $value, int $min, int $max): ?int
    {
        $int = self::safeInteger($value);

        return $int !== null && $int >= $min && $int <= $max ? $int : null;
    }

    /**
     * $value with each string holding $lone as a LoneSurrogate and $nul as U+0000; each member
     * whose name holds $lone, or starts with U+0000, left out.
     */
    private static function marked(mixed $value, string $lone, string $nul): mixed
    {
        if (\is_string($value)) {
            return str_contains($value, $lone) ? new LoneSurrogate() : str_replace($nul, "\0", $value);
        }
        if (\is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::marked($item, $lone, $nul), $value);
        }
        if ($value instanceof \stdClass) {
            $object = new \stdClass();
            foreach (get_object_vars($value) as $name => $member) {
                $name = str_replace($nul, "\0", (string) $name);
                if (!str_contains($name, $lone) && !str_starts_with($name, "\0")) {
                    $object->{$name} = self::marked($member, $lone, $nul);
                }
            }

            return $object;
        }

        return $value;
    }
}
