<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * The https URLs the SDK asks (SPEC.md §15) and the parts of a URL it reads.
 *
 * @internal
 */
final class Url
{
    /**
     * The characters a redirect URL may hold (SPEC.md §30), as an https URL's: RFC 3986's, or "%"
     * followed by two hex digits.
     */
    public const CHARACTERS = '/^(?:[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=]|%[0-9A-Fa-f]{2})+$/D';

    private function __construct() {}

    /**
     * Whether $host is a host as in a relay address (SPEC.md §9): labels of a–z 0–9 "-" separated
     * by ".", the last of at least two letters a–z and not `localhost` (RFC 6761: the machine's own
     * loopback), at most 253 characters. So no IP address, no `localhost` nor a name under it, no
     * upper case.
     */
    public static function isHost(string $host): bool
    {
        return \strlen($host) <= Protocol::MAX_HOST_LENGTH
            && preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.([a-z]{2,})$/D', $host, $match) === 1
            && $match[1] !== 'localhost';
    }

    /**
     * $value as an https URL (SPEC.md §15, rule 1 of a redirect URL): its host, port (443 when
     * absent) and request target (path and query, percent-encoded as a browser sends them); else
     * null. 1 to 2048 characters a redirect URL may hold, `https://`, a host as in a relay address,
     * an optional port (1 to 5 digits, 1 to 65535), then nothing, or "/", "?" or "#" followed by
     * anything. So no upper case in the scheme or the host, no user name nor password, no IP
     * address, no `localhost` nor a name under it, no whitespace, no "\": nothing is repaired.
     *
     * @return array{host: string, port: int, target: string}|null
     */
    public static function https(mixed $value): ?array
    {
        return \is_string($value) && \strlen($value) <= Protocol::MAX_REDIRECT_URL ? self::request($value) : null;
    }

    /**
     * $url read as https() reads it, whatever its length: a URL the SDK asks, an https URL with the
     * query parameters it added (a callback's: SPEC.md §17, step 5).
     *
     * @return array{host: string, port: int, target: string}|null
     */
    public static function request(string $url): ?array
    {
        if (preg_match(self::CHARACTERS, $url) !== 1
            || preg_match('~^https://([^/?#:]*)(?::([0-9]{1,5}))?([/?#].*)?$~sD', $url, $match) !== 1) {
            return null;
        }
        $host = $match[1];
        $port = ($match[2] ?? '') === '' ? 443 : (int) $match[2];
        if (!self::isHost($host) || $port < 1 || $port > 65535) {
            return null;
        }
        $rest = $match[3] ?? '';
        $hash = strpos($rest, '#');
        $rest = $hash === false ? $rest : substr($rest, 0, $hash);
        $target = $rest === '' ? '/' : ($rest[0] === '?' ? '/' . $rest : $rest);
        $question = strpos($target, '?');
        $path = $question === false ? $target : substr($target, 0, $question);
        $query = $question === false ? null : substr($target, $question + 1);
        $target = self::encodePath($path) . ($query === null ? '' : '?' . self::encodeQuery($query));

        return ['host' => $host, 'port' => $port, 'target' => $target];
    }

    /** Whether $value is an https URL the SDK may ask. */
    public static function isHttps(mixed $value): bool
    {
        return self::https($value) !== null;
    }

    /** $path with what a request target cannot hold percent-encoded, as browsers send it. */
    public static function encodePath(string $path): string
    {
        // WHATWG's path percent-encode set: C0 controls, space, '"', "#", "<", ">", "?", "`", "{",
        // "}", and every byte outside ASCII.
        return (string) preg_replace_callback(
            '/[\x00-\x20"#<>?`{}\x7F-\xFF]/',
            static fn(array $m): string => sprintf('%%%02X', \ord($m[0])),
            $path,
        );
    }

    /** $query with what a request target cannot hold percent-encoded (WHATWG's special-query set). */
    public static function encodeQuery(string $query): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x20"#<>\'\x7F-\xFF]/',
            static fn(array $m): string => sprintf('%%%02X', \ord($m[0])),
            $query,
        );
    }

    /** $value encoded as a query parameter's name or value (application/x-www-form-urlencoded). */
    public static function encodeComponent(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Whether the https URLs $a and $b (as https() reads them) are on the same host: their hosts
     * and ports equal (SPEC.md §15).
     *
     * @param array{host: string, port: int} $a
     * @param array{host: string, port: int} $b
     */
    public static function sameHost(array $a, array $b): bool
    {
        return $a['host'] === $b['host'] && $a['port'] === $b['port'];
    }
}
