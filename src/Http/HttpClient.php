<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Http;

/**
 * The SDK's HTTP client (SPEC.md §15): GET requests to the Lightning address, the rate sources, the
 * explorer (mempool.space or the merchant's Bitcoin node) and BlockCypher. The default is
 * StreamHttpClient; another one (for a proxy, for tests) must keep the same rules.
 *
 * It is called in the SDK's event loop: a client that blocks makes the other requests of the call
 * wait while it runs.
 */
interface HttpClient
{
    /**
     * GET the https URL $url (or, for a merchant's Bitcoin node on a local network, SPEC.md §31,
     * the `http://` URL of an IPv4 address of a local range or of a name under `.local`: a client
     * may refuse it, the node's `fallback` then applies) with $headers (and Accept:
     * application/json), never following a redirect (a 3xx is returned as it is), reading at most 1 MiB (1 048 576 bytes) of body and
     * 10 MiB of head (status line, headers, a chunked body's size lines), the whole request, body
     * included, within $timeoutMs.
     *
     * @param array<string, string> $headers
     *
     * @throws \RuntimeException when there is no answer: no connection, TLS, the timeout, a body or
     *                           a head too large (its message holds no secret: it may be logged)
     */
    public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse;
}
