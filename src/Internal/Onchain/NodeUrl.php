<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Url;

/**
 * A node URL (SPEC.md §31, "A node URL"): the URL of the merchant's Bitcoin node, which follows a
 * rule of its own, not the https URL's (SPEC.md §15): `http` is allowed for a host of a local
 * network, and so is an IPv4 address. `<scheme>://<host>`, an optional `:<port>`, an optional
 * path, 200 characters at most, in its one spelling: nothing is repaired.
 *
 * @internal
 */
final class NodeUrl
{
    /**
     * `<scheme>://<host>[:<port>][<path>]`: the host up to `:` or `/` (nothing that may start a
     * user name, a query or a fragment), a port of 1 to 5 digits without a leading zero, a path of
     * visible ASCII. The parts are then checked one by one.
     */
    private const SHAPE = '~^(https?)://([^/:?#@\\\\\s]+)(?::([1-9][0-9]{0,4}))?(/[\x21-\x7E]*)?$~D';

    /** An IPv4 address: 4 numbers from 0 to 255, none with a leading zero (`0` alone is one). */
    private const IPV4 = '/^(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$/D';

    /** A label of a name: 1 to 63 of `a–z 0–9 -`, neither starting nor ending with `-`. */
    private const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /**
     * @param string $url       as written
     * @param string $rest      `<url>/api`: the `<explorer>` of SPEC.md §31, "Explorers"
     * @param string $webSocket `<url>` with `ws` for its leading `http`, then `/api/v1/ws`
     * @param bool   $local     whether the host is of a local network: a local IPv4 range, or a
     *                          name under `.local`
     * @param bool   $internet  whether it is a node of the Internet: `https`, and a name whose
     *                          last label is not `local` (what a customer's browser may read)
     */
    private function __construct(
        public readonly string $url,
        public readonly string $rest,
        public readonly string $webSocket,
        public readonly bool $local,
        public readonly bool $internet,
    ) {}

    /**
     * $value as a node URL, with where the node is read; null when it is not one. `https`, or
     * `http` for a local host; a host in lowercase, an IPv4 address (never in 0.0.0.0/8 nor
     * 127.0.0.0/8) or a name (never `localhost` nor under it); a port from 1 to 65535; a path that
     * ends neither with `/` nor with `/api` (ignoring ASCII case); 200 characters at most. So no
     * user name nor password, no query, no fragment, no IPv6 address, no whitespace.
     */
    public static function parse(mixed $value): ?self
    {
        if (!\is_string($value) || \strlen($value) > Protocol::MAX_NODE_URL || preg_match(self::SHAPE, $value, $match) !== 1) {
            return null;
        }
        [, $scheme, $host] = $match;
        $port = $match[3] ?? '';
        $path = $match[4] ?? '';
        if (($port !== '' && (int) $port > 65535) || !self::isPath($path)) {
            return null;
        }
        if (preg_match(self::IPV4, $host, $octets) === 1) {
            $a = (int) $octets[1];
            $b = (int) $octets[2];
            // Never the machine itself, nor "this network".
            if ($a === 0 || $a === 127) {
                return null;
            }
            $ipv4 = true;
            $local = $a === 10 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168) || ($a === 100 && $b >= 64 && $b <= 127);
        } else {
            if (!self::isName($host)) {
                return null;
            }
            $ipv4 = false;
            $local = str_ends_with($host, '.local');
        }
        if ($scheme === 'http' && !$local) {
            return null;
        }

        return new self(
            $value,
            $value . '/api',
            'ws' . substr($value, \strlen('http')) . '/api/v1/ws',
            $local,
            $scheme === 'https' && !$ipv4 && !$local,
        );
    }

    /**
     * $url as a request to a merchant's Bitcoin node (SPEC.md §15: the node's URL follows its own
     * rule, every other rule of §15 holds): its host, port (80 or 443 when absent), whether it is
     * asked over TLS, and its request target; null unless its scheme and authority are a node
     * URL's (so `http` only to a local host) and the rest is a path an https URL may hold, with
     * no fragment. What the SDK asks a node: `<url>/api/…`.
     *
     * @return array{host: string, port: int, tls: bool, target: string}|null
     */
    public static function request(string $url): ?array
    {
        if (preg_match('~^(https?://[^/?#]*)(/[^#]*)?$~D', $url, $match) !== 1 || preg_match(Url::CHARACTERS, $url) !== 1) {
            return null;
        }
        $origin = self::parse($match[1]);
        if ($origin === null || preg_match(self::SHAPE, $match[1], $parts) !== 1) {
            return null;
        }
        $tls = $parts[1] === 'https';
        $port = ($parts[3] ?? '') === '' ? ($tls ? 443 : 80) : (int) $parts[3];
        $target = ($match[2] ?? '') === '' ? '/' : $match[2];
        $question = strpos($target, '?');
        $path = $question === false ? $target : substr($target, 0, $question);
        $query = $question === false ? null : substr($target, $question + 1);

        return ['host' => $parts[2], 'port' => $port, 'tls' => $tls, 'target' => Url::encodePath($path) . ($query === null ? '' : '?' . Url::encodeQuery($query))];
    }

    /**
     * Whether $host is a name: 2 labels or more, the last starting with a letter `a–z`, never
     * `localhost` nor a name ending with `.localhost`; 253 characters at most. So no trailing dot
     * and no empty label.
     */
    private static function isName(string $host): bool
    {
        if (\strlen($host) > Protocol::MAX_HOST_LENGTH) {
            return false;
        }
        $labels = explode('.', $host);
        if (\count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (preg_match(self::LABEL, $label) !== 1) {
                return false;
            }
        }
        $last = $labels[\count($labels) - 1];

        return $last[0] >= 'a' && $last[0] <= 'z' && $last !== 'localhost';
    }

    /**
     * Whether $path (after the authority, empty when absent) is a node URL's: empty, or `/` and
     * visible ASCII but `?`, `#`, `@` and `\`, ending neither with `/` (so `/` alone is not one)
     * nor with `/api` ignoring ASCII case (the node's URL, not its API's).
     */
    private static function isPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        return strpbrk($path, '?#@\\') === false && !str_ends_with($path, '/') && strtolower(substr($path, -4)) !== '/api';
    }
}
