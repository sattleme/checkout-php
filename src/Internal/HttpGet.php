<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Onchain\NodeUrl;

/**
 * The SDK's HTTP requests (SPEC.md §15) through the call's client, each with the SDK's
 * User-Agent: no answer, a redirect or a body over 1 MiB is NetworkException; one leading byte
 * order mark of a body is ignored; a body that is not JSON in UTF-8 reads as "no JSON".
 *
 * @internal
 */
final class HttpGet
{
    /** "No JSON": a body that is not JSON. */
    public const NO_JSON = 'no JSON';

    /** UTF-8's byte order mark (U+FEFF). */
    private const BOM = "\xEF\xBB\xBF";

    private function __construct() {}

    /**
     * @param array<string, string> $headers
     *
     * @throws NetworkException
     */
    public static function get(Context $context, string $url, #[\SensitiveParameter] array $headers = []): HttpResponse
    {
        // The client looks the name up, which blocks the process (Loop), as a client of the
        // integrator's may block for the whole request: none starts once the call is cancelled,
        // and a cancellation that came meanwhile wins over the answer, or its failure.
        $loop = Loop::get();
        $loop->throwIfCancelled();
        try {
            $response = $context->http->get($url, ['User-Agent' => Protocol::USER_AGENT] + $headers, $context->httpTimeoutMs);
        } catch (CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $loop->throwIfCancelled();
            throw new NetworkException(self::host($url) . ' did not answer: ' . $e->getMessage(), $e);
        }
        $loop->throwIfCancelled();
        // Whatever client: a redirect, never followed, and a body over 1 MiB are no answer.
        if ($response->status >= 300 && $response->status < 400) {
            throw new NetworkException(self::host($url) . " answered a redirect (HTTP {$response->status})");
        }
        if (\strlen($response->body) > Protocol::MAX_BODY_BYTES) {
            throw new NetworkException(self::host($url) . ' answered more than 1 MiB');
        }

        return $response;
    }

    /**
     * The body's text: without one leading byte order mark, as WHATWG's UTF-8 decode (and so fetch)
     * reads it (SPEC.md §15).
     */
    public static function text(HttpResponse $response): string
    {
        return str_starts_with($response->body, self::BOM) ? substr($response->body, \strlen(self::BOM)) : $response->body;
    }

    /** The body's JSON value, or NO_JSON. */
    public static function json(HttpResponse $response): mixed
    {
        try {
            return Json::decode(self::text($response));
        } catch (\JsonException) {
            return self::NO_JSON;
        }
    }

    /** The host of $url, for messages (never its query: a key may be there). */
    public static function host(string $url): string
    {
        $parts = Url::request($url) ?? NodeUrl::request($url);

        return $parts === null ? 'A server' : $parts['host'];
    }
}
