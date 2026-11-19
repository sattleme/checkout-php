<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Onchain;

use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The explorer and BlockCypher of a case of vectors/onchain-payments.json, over HTTP as the SDK
 * asks them (LiveSource): their answers as the case gives them, an answer that is not one
 * included; `error`, a failure; a transaction BlockCypher does not know, HTTP 404.
 */
final class VectorHttp implements HttpClient
{
    /** @var list<string> the transactions BlockCypher was asked about, in order */
    public array $asked = [];

    /**
     * @param array<string, mixed> $case
     */
    public function __construct(
        private readonly array $case,
        private readonly int $index,
        private readonly string $address,
    ) {}

    public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
    {
        $explorer = V::a($this->case['explorer']);
        if ($url === Protocol::MEMPOOL . "/address/{$this->address}/txs") {
            return isset($explorer['error']) ? new HttpResponse(500, 'down') : new HttpResponse(200, Json::encode($this->vector(['explorer', 'txs'])));
        }
        if ($url === Protocol::MEMPOOL . '/blocks/tip/height') {
            return isset($explorer['error']) ? new HttpResponse(500, 'down') : new HttpResponse(200, (string) V::i($explorer['height']));
        }
        if (str_starts_with($url, Protocol::BLOCKCYPHER . '/txs/')) {
            $txid = explode('?', substr($url, \strlen(Protocol::BLOCKCYPHER . '/txs/')))[0];
            $this->asked[] = $txid;
            $blockcypher = V::a($this->case['blockcypher']);
            if (isset($blockcypher['error'])) {
                return new HttpResponse(500, Json::encode(['error' => 'down']));
            }
            if (!\array_key_exists($txid, $blockcypher)) {
                return new HttpResponse(404, Json::encode(['error' => "Transaction {$txid} not found."]));
            }

            return new HttpResponse(200, Json::encode(['hash' => $txid, 'received' => $blockcypher[$txid], 'confirmations' => 0]));
        }

        throw new \LogicException("Not asked in this vector: {$url}");
    }

    /**
     * A member of the case as the vector writes it (its objects as objects).
     *
     * @param list<string> $path from the case
     */
    private function vector(array $path): mixed
    {
        return V::json('vectors/onchain-payments.json', ['cases', $this->index, ...$path]);
    }
}
