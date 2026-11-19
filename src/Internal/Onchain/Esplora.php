<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\HttpGet;

/**
 * An explorer of the Esplora API at a base URL (SPEC.md §31, "Explorers": the `<explorer>`):
 * mempool.space's, or the merchant's Bitcoin node's (`<url>/api`). A third party: every answer is
 * checked, anything else is `network`. Which one a read asks is the call's Explorer's.
 *
 * @internal
 */
final class Esplora
{
    public function __construct(
        private readonly Context $context,
        public readonly string $base,
    ) {}

    /**
     * The address's transactions (its first answer: a deposit address is paid a few times at most).
     *
     * @return list<Transaction>
     *
     * @throws NetworkException
     */
    public function transactions(string $address): array
    {
        $response = $this->get('/address/' . $address . '/txs');
        $body = self::ok($response) ? HttpGet::json($response) : null;
        if (!\is_array($body) || !array_is_list($body)) {
            throw new NetworkException($this->host() . " gave no transactions of the address (HTTP {$response->status})");
        }
        $transactions = [];
        foreach ($body as $value) {
            $transaction = Transaction::fromJson($value);
            if ($transaction === null) {
                throw new NetworkException($this->host() . ' gave a transaction that is not one');
            }
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * The chain's height: a body of 1 to 9 digits (plain text, not JSON).
     *
     * @throws NetworkException
     */
    public function height(): int
    {
        $response = $this->get('/blocks/tip/height');
        $height = HttpGet::text($response);
        if (!self::ok($response) || preg_match('/^[0-9]{1,9}$/D', $height) !== 1) {
            throw new NetworkException($this->host() . " gave no height of the chain (HTTP {$response->status})");
        }

        return (int) $height;
    }

    /** @throws NetworkException */
    private function get(string $path): HttpResponse
    {
        return HttpGet::get($this->context, $this->base . $path);
    }

    private function host(): string
    {
        return HttpGet::host($this->base);
    }

    private static function ok(HttpResponse $response): bool
    {
        return $response->status >= 200 && $response->status < 300;
    }
}
