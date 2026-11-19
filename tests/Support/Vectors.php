<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support;

/**
 * The conformance vectors of the pinned copy of checkout-spec (spec/): the tests read them there
 * only, never from the network.
 */
final class Vectors
{
    public static function root(): string
    {
        return \dirname(__DIR__, 2) . '/spec';
    }

    public static function path(string $relative): string
    {
        return self::root() . '/' . $relative;
    }

    /**
     * A file of spec/ decoded with JSON objects as arrays, as the SDK reads JSON (a string holding a
     * lone surrogate as a LoneSurrogate, SPEC.md §6).
     */
    public static function load(string $relative): mixed
    {
        $text = file_get_contents(self::path($relative));
        if ($text === false) {
            throw new \RuntimeException("Cannot read spec/{$relative}");
        }

        return self::arrays(\Sattleme\Checkout\Internal\Json::decode($text));
    }

    /** $value with its JSON objects as arrays. */
    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return \is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }

    /**
     * A file of spec/ decoded as relays and HTTP servers send JSON: objects as \stdClass.
     *
     * @param list<string|int> $path the member to return, from the root
     */
    public static function json(string $relative, array $path = []): mixed
    {
        $text = file_get_contents(self::path($relative));
        if ($text === false) {
            throw new \RuntimeException("Cannot read spec/{$relative}");
        }
        $value = \Sattleme\Checkout\Internal\Json::decode($text);
        foreach ($path as $member) {
            $value = \is_int($member) ? (\is_array($value) ? $value[$member] ?? null : null) : \Sattleme\Checkout\Internal\Json::get($value, $member);
        }

        return $value;
    }

    /**
     * A file of spec/ decoded as an array, its JSON objects too.
     *
     * @return array<string, mixed>
     */
    public static function object(string $relative): array
    {
        $value = self::load($relative);
        if (!\is_array($value)) {
            throw new \RuntimeException("spec/{$relative} holds no JSON object");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * The cases of a list of a vector file, keyed by a readable name for PHPUnit's data providers.
     *
     * @param list<mixed> $cases
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function named(array $cases, string $nameKey = 'name'): array
    {
        $named = [];
        foreach ($cases as $index => $case) {
            if (!\is_array($case)) {
                throw new \RuntimeException('A vector case is no object');
            }
            /** @var array<string, mixed> $case */
            try {
                $label = \is_string($case[$nameKey] ?? null) ? $case[$nameKey] : json_encode($case, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\LogicException) {
                $label = 'a text holding a lone surrogate'; // No UTF-8 holds it (LoneSurrogate).
            }
            $named[sprintf('#%d %s', $index, substr((string) $label, 0, 120))] = [$case];
        }

        return $named;
    }

    /**
     * The cases of a list of a vector file, with their index, keyed by a readable name.
     *
     * @param list<string> $path to the list, from the root
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function indexed(string $relative, array $path, string $nameKey = 'name'): iterable
    {
        $cases = self::object($relative);
        foreach ($path as $member) {
            $cases = self::o($cases[$member] ?? null);
        }
        foreach (array_values($cases) as $index => $case) {
            $case = self::o($case);
            $label = \is_string($case[$nameKey] ?? null) ? $case[$nameKey] : (string) $index;
            yield sprintf('#%d %s', $index, substr($label, 0, 120)) => [$case, $index];
        }
    }

    /**
     * $value, a JSON object or array decoded as an array.
     *
     * @return array<string, mixed>
     */
    public static function o(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException('An object expected in a vector');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * $value, a JSON array.
     *
     * @return list<mixed>
     */
    public static function l(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('A list expected in a vector');
        }

        return $value;
    }

    /** $value, which must be a string. */
    public static function s(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('A string expected in a vector');
        }

        return $value;
    }

    /** $value, which must be null or a string. */
    public static function ns(mixed $value): ?string
    {
        return $value === null ? null : self::s($value);
    }

    /** $value, which must be an integer. */
    public static function i(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \RuntimeException('An integer expected in a vector');
        }

        return $value;
    }

    /**
     * $value, which must be an array.
     *
     * @return array<mixed>
     */
    public static function a(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException('An array expected in a vector');
        }

        return $value;
    }

    /** The bytes of hex $value. */
    public static function bytes(mixed $value): string
    {
        $bytes = hex2bin(self::s($value));
        if ($bytes === false) {
            throw new \RuntimeException('Hex expected in a vector');
        }

        return $bytes;
    }
}
