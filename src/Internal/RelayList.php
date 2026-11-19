<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * Lists of Nostr relays as the merchant's app writes them (SPEC.md §9): every address a `wss://`
 * URL in its one spelling, 1 to 10 of them, none twice. Refused, never repaired.
 *
 * @internal
 */
final class RelayList
{
    private const PREFIX = 'wss://';

    /**
     * A lowercase host of at least two labels, the last of letters only; an optional port; an
     * optional path without ECMAScript's whitespace (the app checks with JavaScript's \s), "?",
     * "#" nor ",".
     */
    private const ADDRESS = '~^wss://([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?::([0-9]{1,5}))?(/[^\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}?#,]*)?$~uD';

    private function __construct() {}

    /** Whether $url is a relay address in its one spelling. */
    public static function isRelayUrl(mixed $url): bool
    {
        if (!\is_string($url) || !Text::isUtf8($url) || preg_match(self::ADDRESS, $url, $match) !== 1) {
            return false;
        }
        $port = $match[2] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            return false;
        }
        $path = $match[3] ?? '';

        return !str_ends_with($path, '/')
            && Url::isHost($match[1])
            && Text::codePoints($url) <= Protocol::MAX_RELAY_URL_LENGTH;
    }

    /**
     * $value as a relay list: an array of 1 to 10 relay addresses, none twice; else null.
     *
     * @return list<string>|null
     */
    public static function parse(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value) || $value === [] || \count($value) > Protocol::MAX_RELAYS) {
            return null;
        }
        $list = [];
        foreach ($value as $url) {
            if (!self::isRelayUrl($url) || \in_array($url, $list, true)) {
                return null;
            }
            \assert(\is_string($url));
            $list[] = $url;
        }

        return $list;
    }

    /**
     * The list an integration key or a checkout link writes: base64url of the UTF-8 of the hosts
     * without `wss://`, joined by ",".
     *
     * @return list<string>|null
     */
    public static function decode(string $part): ?array
    {
        $bytes = Base64::decodeUrl($part);
        if ($bytes === null || $bytes === '' || !Text::isUtf8($bytes)) {
            return null;
        }

        return self::parse(array_map(static fn(string $host): string => self::PREFIX . $host, explode(',', $bytes)));
    }

    /** @param list<string> $relays */
    public static function encode(array $relays): string
    {
        return Base64::encodeUrl(implode(',', array_map(static fn(string $url): string => substr($url, \strlen(self::PREFIX)), $relays)));
    }

    /**
     * Whether two lists are one: the same addresses in the same order.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function same(array $a, array $b): bool
    {
        return $a === $b;
    }

    /**
     * The host, port and request target of a relay address, for its WebSocket connection.
     *
     * @return array{host: string, port: int, path: string}
     */
    public static function endpoint(string $url): array
    {
        if (!self::isRelayUrl($url)) {
            throw new \InvalidArgumentException('Not a relay address');
        }
        if (preg_match(self::ADDRESS, $url, $match) !== 1) {
            throw new \LogicException('A relay address no longer matches');
        }
        $port = ($match[2] ?? '') !== '' ? (int) $match[2] : 443;
        $path = ($match[3] ?? '') !== '' ? $match[3] : '/';

        return ['host' => $match[1], 'port' => $port, 'path' => Url::encodePath($path)];
    }
}
