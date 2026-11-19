<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support;

use Sattleme\Checkout\Client;
use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\RelayList;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\FakeHttpServer;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Net\HttpResponseSpec;
use Sattleme\Checkout\Tests\Support\Net\ServerWebSocket;
use Sattleme\Checkout\Tests\Support\Net\TestCertificates;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\Nostr\Relays;

/**
 * Everything a client talks to, local (PORTING.md, steps, 5): three relays, the merchant's
 * Lightning address (breez.tips), the rate sources, the explorer (mempool.space, its WebSocket),
 * a merchant's Bitcoin node (the same chain) of the Internet (INTERNET_NODE) and on its network
 * (NODE, over http), BlockCypher; a team, an integration key, a merchant's registry. Never the
 * network, never a real key or fund: every key is drawn when the test runs.
 */
final class World
{
    /** @var list<string> */
    public const HOSTS = ['breez.tips', 'api.coingecko.com', 'mempool.space', 'node.example', 'api.blockcypher.com', 'api.coinbase.com', 'api.kraken.com', 'api.opennode.com'];

    /** @var list<string> */
    public const RELAYS = ['wss://relay1.example', 'wss://relay2.example', 'wss://relay3.example'];

    /** A merchant's Bitcoin node of the Internet (SPEC.md §31), with a path: read at <url>/api. */
    public const INTERNET_NODE = 'https://node.example/mempool';

    /** A merchant's Bitcoin node on its network (an Umbrel), over http: read at <url>/api. */
    public const NODE = 'http://192.168.1.10:3006';

    /** The local node's server, in clear HTTP (SPEC.md §31: http for a local host only). */
    public readonly FakeHttpServer $plain;

    public const METADATA = '[["text/identifier","shop@breez.tips"],["text/plain","Pay to Sunrise Bakery"]]';

    /** The clock of the client and of the fakes. */
    public int $now = 1791382000;

    public readonly TestCertificates $certificates;

    public readonly TestConnector $connector;

    public readonly Relays $relays;

    public readonly FakeHttpServer $http;

    public readonly string $team;

    /** The integration key, as the merchant's app gives it. */
    public readonly string $key;

    public readonly Registry $registry;

    /** @var list<string> the warnings the client logged */
    public array $warnings = [];

    // The Lightning address.

    /** @var array<string, mixed> the pay request (LUD-06), as breez.tips answers it */
    public array $payRequest;

    /** The pay request's body as it is written, in place of payRequest's JSON: null for that. */
    public ?string $payRequestText = null;

    /** @var array<string, array{pr: string, preimage: string, sats: int, settled: bool, comment: string|null, expiry: int}> by payment hash */
    public array $invoices = [];

    /** @var (\Closure(array<string, mixed>, HttpRequest): (array<string, mixed>|HttpResponseSpec))|null changes the callback's answer */
    public ?\Closure $invoiceAnswer = null;

    /** The length of the invoices it makes, fields it does not read added (Encoders::sizedBolt11()); null: as short as they are. */
    public ?int $invoiceLength = null;

    // The explorer.

    /** @var array<string, list<array<string, mixed>>> the transactions of each address, as mempool.space writes them */
    public array $transactions = [];

    public int $height = 970000;

    public bool $explorerDown = false;

    /** Whether the merchant's Bitcoin node answers its REST reads with HTTP 503. */
    public bool $nodeDown = false;

    /** @var (\Closure(HttpRequest): ?HttpResponseSpec)|null the node's answer instead, when it gives one */
    public ?\Closure $nodeAnswer = null;

    /** @var array<string, string> when BlockCypher received each transaction it knows (RFC 3339) */
    public array $received = [];

    public bool $blockcypherDown = false;

    /** @var list<ServerWebSocket> the WebSocket connections to mempool.space */
    public array $sockets = [];

    /** @var list<ServerWebSocket> the WebSocket connections to the merchant's Bitcoin node */
    public array $nodeSockets = [];

    /** @var list<string> what each connection to the node's WebSocket was sent, in order */
    public array $nodeSent = [];

    /** Whether the node's WebSocket accepts each connection, then closes it before any message. */
    public bool $nodeSocketDrops = false;

    // The rate sources.

    /** @var array<string, string> the price of one bitcoin in each currency */
    public array $prices = ['CHF' => '100000', 'EUR' => '95000', 'USD' => '110000'];

    /** @var array<string, HttpResponseSpec> a source's answer, by host, instead of its price */
    public array $rateAnswers = [];

    /** @var (\Closure(string, HttpRequest): void)|null hears each HTTP request (its host, the request) before it is answered */
    public ?\Closure $onRequest = null;

    private readonly string $teamSecret;

    private readonly IntegrationKey $integration;

    private readonly string $readerSecret;

    public function __construct(int $relayCount = 3)
    {
        $this->certificates = new TestCertificates(self::HOSTS);
        $this->connector = new TestConnector($this->certificates);
        $this->relays = new Relays($this->connector);
        foreach (\array_slice(self::RELAYS, 0, $relayCount) as $url) {
            $this->relays->add($url);
        }
        $this->http = new FakeHttpServer(true, $this->certificates);
        foreach (self::HOSTS as $host) {
            $this->connector->route($host, 443, $this->http->server);
        }
        $this->plain = new FakeHttpServer();
        $this->connector->route('192.168.1.10', 3006, $this->plain->server);
        $this->teamSecret = self::secret();
        $this->team = Derive::author($this->teamSecret);
        $relays = \array_slice(self::RELAYS, 0, $relayCount);
        $this->key = 'sattleme_ck_' . Base64::encodeUrl((string) hex2bin($this->team)) . '.' . Base64::encodeUrl(random_bytes(32)) . '.' . RelayList::encode($relays);
        $this->integration = IntegrationKey::parse($this->key);
        $this->readerSecret = self::secret();
        $this->registry = new Registry(new Secret(random_bytes(32)), Derive::author($this->readerSecret));
        $this->payRequest = [
            'tag' => 'payRequest',
            'callback' => 'https://breez.tips/lnurlp/shop/invoice',
            'minSendable' => 1000,
            'maxSendable' => 4000000000,
            'metadata' => self::METADATA,
            'commentAllowed' => 144,
        ];
        $this->serve();
    }

    /**
     * A client of the integration, on this world (its clock, its connector), logging here; its
     * timeouts, 2 000 ms each or those given, scaled (TestTime).
     *
     * @param array<string, mixed> $options over the world's
     */
    public function client(array $options = []): Client
    {
        $options += [
            'key' => $this->key,
            'connector' => $this->connector,
            'now' => fn(): int => $this->now,
            'logger' => function (string $message): void {
                $this->warnings[] = $message;
            },
            'relayTimeoutMs' => 2000,
            'httpTimeoutMs' => 2000,
        ];
        foreach (['relayTimeoutMs', 'httpTimeoutMs'] as $name) {
            if (\is_int($options[$name])) {
                $options[$name] = TestTime::ms($options[$name]);
            }
        }

        return new Client($options);
    }

    public function integration(): IntegrationKey
    {
        return $this->integration;
    }

    /**
     * Publishes the integration's channel on $on (the key's relays by default), as the merchant's
     * app does.
     *
     * @param array<string, mixed> $fields over the channel's value
     * @param list<string>|null    $on
     */
    public function channel(array $fields = [], ?array $on = null, ?int $createdAt = null): Event
    {
        $createdAt ??= $this->now - 1000;
        $value = $fields + [
            'version' => 1,
            'status' => 'active',
            'role' => 'integration',
            'profile' => ['businessName' => 'Sunrise Bakery', 'lightningAddress' => 'shop@breez.tips', 'currency' => 'CHF'],
            'registryKey' => bin2hex($this->registry->key->reveal()),
            'registryReader' => $this->registry->reader,
            'checkout' => ['origin' => 'https://pay.example'],
            'updatedAt' => $createdAt,
        ];
        $ik = $this->integration->key->reveal();
        $event = SealedRecord::seal(Protocol::CHANNEL_TYPE, $value, $this->teamSecret, Derive::channelEncryptionKey($ik), Derive::channelAddress($ik), $createdAt, self::random(...));
        $this->publish($event, $on);

        return $event;
    }

    /**
     * The warnings the client logged that start with $prefix.
     *
     * @return list<string>
     */
    public function warned(string $prefix): array
    {
        return array_values(array_filter($this->warnings, static fn(string $warning): bool => str_starts_with($warning, $prefix)));
    }

    /** A copy of the channel in a newer format (no NIP-44 version 2), signed as a newer app would sign it. */
    public function newerChannel(int $createdAt): Event
    {
        return Event::sign($this->teamSecret, Protocol::RECORD_KIND, $createdAt, [['d', Derive::channelAddress($this->integration->key->reveal())]], '#', random_bytes(32));
    }

    /**
     * Publishes the integration's lot of addresses, as the merchant's app does.
     *
     * @param list<string> $addresses
     */
    public function lot(array $addresses, ?int $createdAt = null): Event
    {
        $createdAt ??= $this->now - 900;
        $ik = $this->integration->key->reveal();
        $event = SealedRecord::seal(Protocol::LOT_TYPE, ['version' => 1, 'addresses' => $addresses, 'updatedAt' => $createdAt], $this->teamSecret, Derive::channelEncryptionKey($ik), Derive::lotAddress($ik), $createdAt, self::random(...));
        $this->publish($event);

        return $event;
    }

    /**
     * Holds $event on the relays $on (every relay by default), as published.
     *
     * @param list<string>|null $on
     */
    public function publish(Event $event, ?array $on = null): void
    {
        foreach ($on ?? array_keys($this->relays->relays) as $url) {
            $this->relays->get($url)->take($event);
        }
    }

    /** One more relay, at $url (none of the key's). */
    public function addRelay(string $url): LocalRelay
    {
        return $this->relays->add($url);
    }

    public function relay(int $index): LocalRelay
    {
        return $this->relays->get(self::RELAYS[$index]);
    }

    /**
     * The events every relay holds by $author, without duplicates.
     *
     * @return list<Event>
     */
    public function eventsBy(string $author): array
    {
        $events = [];
        foreach ($this->relays->relays as $relay) {
            foreach ($relay->events as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->pubkey === $author) {
                    $events[$event->id] = $event;
                }
            }
        }

        return array_values($events);
    }

    /** A deposit address of the merchant's wallet, made for the test. */
    public static function address(): string
    {
        return Encoders::taproot(random_bytes(32));
    }

    /**
     * A transaction as mempool.space writes it, paying $outputs ([sats, address] each).
     *
     * @param list<string>                $coins  `<txid>:<vout>` it spends
     * @param list<array{0: int, 1: string}> $outputs
     *
     * @return array<string, mixed>
     */
    public static function transaction(string $txid, array $coins, array $outputs, ?int $blockHeight = null): array
    {
        return [
            'txid' => $txid,
            'vin' => array_map(static function (string $coin): array {
                [$spent, $vout] = explode(':', $coin);

                return ['txid' => $spent, 'vout' => (int) $vout, 'is_coinbase' => false];
            }, $coins),
            'vout' => array_map(static fn(array $output): array => ['value' => $output[0], 'scriptpubkey_address' => $output[1]], $outputs),
            'status' => $blockHeight === null ? ['confirmed' => false] : ['confirmed' => true, 'block_height' => $blockHeight],
        ];
    }

    /**
     * The merchant's acceptance of the invoice of $ref at $address whose end is $expiresAt, as the
     * merchant's app makes it (SPEC.md §31, "The merchant's acceptance"): of $value, sealed with the
     * channel's key at its merchant's acceptance address, signed by the team (or $signer), dated
     * $createdAt.
     */
    public function merchantAcceptance(string $ref, string $address, int $expiresAt, mixed $value, int $createdAt, #[\SensitiveParameter] ?string $signer = null): Event
    {
        $key = $this->integration->key->reveal();

        return SealedRecord::seal(
            Protocol::MERCHANT_ACCEPTANCE_TYPE,
            $value,
            $signer ?? $this->teamSecret,
            Derive::channelEncryptionKey($key),
            Derive::merchantAcceptanceAddress($key, Sale::of($this->integration, $ref)->author, $address, $expiresAt),
            $createdAt,
            self::random(...),
        );
    }

    /** $event of the team under another signature of the team (BIP-340: any of them verifies). */
    public function resigned(Event $event): Event
    {
        return Event::sign($this->teamSecret, $event->kind, $event->createdAt, $event->tags, $event->content, random_bytes(32));
    }

    /** The txid of the $index-th transaction of $address. */
    public function txid(string $address, int $index = 0): string
    {
        $txid = $this->transactions[$address][$index]['txid'] ?? null;
        \assert(\is_string($txid));

        return $txid;
    }

    /** The first coin the $index-th transaction of $address spends. */
    public function coin(string $address, int $index = 0): string
    {
        $inputs = $this->transactions[$address][$index]['vin'] ?? null;
        \assert(\is_array($inputs) && \is_array($inputs[0] ?? null) && \is_string($inputs[0]['txid'] ?? null) && \is_int($inputs[0]['vout'] ?? null));

        return $inputs[0]['txid'] . ':' . $inputs[0]['vout'];
    }

    /** Changes a field of an invoice of the Lightning address (a service that answers wrong). */
    public function tamper(string $paymentHash, string $field, string $value): void
    {
        $invoice = $this->invoices[$paymentHash] ?? throw new \OutOfBoundsException('No such invoice');
        if ($field === 'pr') {
            $invoice['pr'] = $value;
        } elseif ($field === 'preimage') {
            $invoice['preimage'] = $value;
        } else {
            throw new \InvalidArgumentException($field);
        }
        $this->invoices[$paymentHash] = $invoice;
    }

    /** Pays an invoice of the Lightning address: its verify URL then says settled. */
    public function settle(string $paymentHash): void
    {
        if (!isset($this->invoices[$paymentHash])) {
            throw new \OutOfBoundsException('No such invoice');
        }
        $this->invoices[$paymentHash]['settled'] = true;
    }

    /**
     * Pushes a message to every client of mempool.space's WebSocket (of the node's, $node).
     *
     * @param array<string, mixed> $message
     */
    public function push(array $message, bool $node = false): void
    {
        foreach ($node ? $this->nodeSockets : $this->sockets as $socket) {
            if ($socket->open) {
                $socket->send(Json::encode($message));
            }
        }
    }

    public function stop(): void
    {
        $this->relays->stop();
        $this->http->stop();
        $this->plain->stop();
        $this->certificates->remove();
    }

    /** RFC 3339 of Unix milliseconds, as BlockCypher writes it. */
    public static function rfc3339(int $milliseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000)) . sprintf('.%03dZ', $milliseconds % 1000);
    }

    private function serve(): void
    {
        $this->http->on('breez.tips', fn(HttpRequest $request): HttpResponseSpec => $this->lightningAddress($this->heard('breez.tips', $request)));
        $this->http->on('mempool.space', fn(HttpRequest $request): HttpResponseSpec => $this->mempool($this->heard('mempool.space', $request)));
        $this->http->on('node.example', fn(HttpRequest $request): HttpResponseSpec => $this->node($this->heard('node.example', $request), '/mempool/api'));
        $this->http->onWebSocket('node.example', fn(ServerWebSocket $socket) => $this->nodeSocket($socket, '/mempool/api/v1/ws'));
        $this->plain->on('192.168.1.10:3006', fn(HttpRequest $request): HttpResponseSpec => $this->node($this->heard('192.168.1.10:3006', $request), '/api'));
        $this->plain->onWebSocket('192.168.1.10:3006', fn(ServerWebSocket $socket) => $this->nodeSocket($socket, '/api/v1/ws'));
        $this->http->onWebSocket('mempool.space', function (ServerWebSocket $socket): void {
            $this->sockets[] = $socket;
            while ($socket->receive() !== null) {
                // want, track-address: nothing to answer.
            }
        });
        $this->http->on('api.blockcypher.com', fn(HttpRequest $request): HttpResponseSpec => $this->blockcypher($request));
        $this->http->on('api.coingecko.com', fn(HttpRequest $request): HttpResponseSpec => $this->rateAnswers['api.coingecko.com'] ?? $this->coingecko($request));
        $this->http->on('api.coinbase.com', fn(): HttpResponseSpec => $this->rateAnswers['api.coinbase.com']
            ?? HttpResponseSpec::json(['data' => ['currency' => 'BTC', 'rates' => $this->prices]]));
        $this->http->on('api.kraken.com', function (HttpRequest $request): HttpResponseSpec {
            $currency = substr($request->query()['pair'] ?? '', 3);

            return $this->rateAnswers['api.kraken.com'] ?? (isset($this->prices[$currency])
                ? HttpResponseSpec::json(['error' => [], 'result' => ['XXBTZ' . $currency => ['c' => [$this->prices[$currency], '0.1']]]])
                : HttpResponseSpec::json(['error' => ['EQuery:Unknown asset pair']]));
        });
        $this->http->on('api.opennode.com', function (): HttpResponseSpec {
            $data = [];
            foreach ($this->prices as $currency => $price) {
                $data['BTC' . $currency] = [$currency => (float) $price];
            }

            return $this->rateAnswers['api.opennode.com'] ?? HttpResponseSpec::json(['data' => $data]);
        });
    }

    /** $request, once onRequest heard it. */
    private function heard(string $host, HttpRequest $request): HttpRequest
    {
        if ($this->onRequest !== null) {
            ($this->onRequest)($host, $request);
        }

        return $request;
    }

    private function lightningAddress(HttpRequest $request): HttpResponseSpec
    {
        $path = $request->path();
        if ($path === '/.well-known/lnurlp/shop') {
            return $this->payRequestText === null ? HttpResponseSpec::json($this->payRequest) : new HttpResponseSpec(200, $this->payRequestText);
        }
        if ($path === '/lnurlp/shop/invoice') {
            $query = $request->query();
            $sats = intdiv((int) ($query['amount'] ?? 0), 1000);
            $expiry = (int) ($query['expiry'] ?? 3600);
            $preimage = random_bytes(32);
            $hash = hash('sha256', $preimage);
            $pr = $this->invoiceLength === null
                ? Encoders::bolt11($sats, $this->now, $expiry, $hash, hash('sha256', self::METADATA))
                : Encoders::sizedBolt11($this->invoiceLength, $sats, $this->now, $expiry, $hash, hash('sha256', self::METADATA));
            $this->invoices[$hash] = ['pr' => $pr, 'preimage' => bin2hex($preimage), 'sats' => $sats, 'settled' => false, 'comment' => $query['comment'] ?? null, 'expiry' => $expiry];
            $answer = ['pr' => $pr, 'routes' => [], 'verify' => 'https://breez.tips/lnurlp/verify/' . $hash];
            if ($this->invoiceAnswer !== null) {
                $changed = ($this->invoiceAnswer)($answer, $request);
                if ($changed instanceof HttpResponseSpec) {
                    return $changed;
                }
                $answer = $changed;
            }

            return HttpResponseSpec::json($answer);
        }
        if (str_starts_with($path, '/lnurlp/verify/')) {
            $invoice = $this->invoices[substr($path, \strlen('/lnurlp/verify/'))] ?? null;
            if ($invoice === null) {
                return HttpResponseSpec::json(['status' => 'ERROR', 'reason' => 'Not found']);
            }

            return HttpResponseSpec::json(['status' => 'OK', 'settled' => $invoice['settled'], 'preimage' => $invoice['settled'] ? $invoice['preimage'] : null, 'pr' => $invoice['pr']]);
        }

        return new HttpResponseSpec(404, 'Not found');
    }

    private function mempool(HttpRequest $request): HttpResponseSpec
    {
        $path = $request->path();
        if ($path === '/api/v1/prices') {
            return $this->rateAnswers['mempool.space'] ?? HttpResponseSpec::json(['time' => $this->now] + array_map('floatval', $this->prices));
        }
        if ($this->explorerDown) {
            return new HttpResponseSpec(503, 'Unavailable');
        }

        return $this->esplora($path, '/api');
    }

    /** A connection to the node's WebSocket, at $path. */
    private function nodeSocket(ServerWebSocket $socket, string $path): void
    {
        $this->nodeSockets[] = $socket;
        if ($this->nodeSocketDrops || $socket->path !== $path) {
            return;
        }
        while (($message = $socket->receive()) !== null) {
            $this->nodeSent[] = $message;
        }
    }

    /** The merchant's Bitcoin node: mempool.space's API under $api, the same chain. */
    private function node(HttpRequest $request, string $api): HttpResponseSpec
    {
        $answer = $this->nodeAnswer === null ? null : ($this->nodeAnswer)($request);
        if ($answer !== null) {
            return $answer;
        }
        if ($this->nodeDown) {
            return new HttpResponseSpec(503, 'Unavailable');
        }
        $path = $request->path();

        return str_starts_with($path, $api . '/') ? $this->esplora(substr($path, \strlen($api) - \strlen('/api')), '/api') : new HttpResponseSpec(404, 'Not found');
    }

    /** An Esplora API's answer at $path, under $api: the world's chain. */
    private function esplora(string $path, string $api): HttpResponseSpec
    {
        if ($path === $api . '/blocks/tip/height') {
            return new HttpResponseSpec(200, (string) $this->height);
        }
        if (preg_match('~^' . preg_quote($api, '~') . '/address/([a-z0-9]+)/txs$~', $path, $match) === 1) {
            return HttpResponseSpec::json($this->transactions[$match[1]] ?? []);
        }
        if (preg_match('~^' . preg_quote($api, '~') . '/tx/([0-9a-f]{64})$~', $path, $match) === 1) {
            foreach ($this->transactions as $list) {
                foreach ($list as $transaction) {
                    if ($transaction['txid'] === $match[1]) {
                        return HttpResponseSpec::json($transaction);
                    }
                }
            }

            return new HttpResponseSpec(404, 'Transaction not found');
        }

        return new HttpResponseSpec(404, 'Not found');
    }

    private function blockcypher(HttpRequest $request): HttpResponseSpec
    {
        if ($this->blockcypherDown) {
            return new HttpResponseSpec(429, '{"error": "Limits reached."}');
        }
        if (preg_match('~^/v1/btc/main/txs/([0-9a-f]{64})$~', $request->path(), $match) === 1 && isset($this->received[$match[1]])) {
            return HttpResponseSpec::json(['hash' => $match[1], 'received' => $this->received[$match[1]], 'confirmations' => 0]);
        }

        return HttpResponseSpec::json(['error' => 'Transaction not found'], 404);
    }

    private function coingecko(HttpRequest $request): HttpResponseSpec
    {
        // As api.coingecko.com does: a request without a User-Agent is refused.
        if (($request->headers['user-agent'] ?? '') === '') {
            return HttpResponseSpec::json(['status' => ['error_code' => 403, 'error_message' => 'Please add a descriptive User-Agent to your request.']], 403);
        }
        $code = $request->query()['vs_currencies'] ?? '';
        $price = $this->prices[strtoupper($code)] ?? null;

        return HttpResponseSpec::json(['bitcoin' => $price === null ? new \stdClass() : [$code => (float) $price, 'last_updated_at' => $this->now - 30]]);
    }

    private static function secret(): string
    {
        do {
            $secret = random_bytes(32);
        } while (!Secp256k1::isSecretKey($secret));

        return $secret;
    }

    private static function random(int $bytes): string
    {
        return random_bytes(max(1, $bytes));
    }
}
