<?php

declare(strict_types=1);

namespace Sattleme\Checkout;

use Sattleme\Checkout\Exception\CheckoutUnavailableException;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\InvalidOptionException;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\StreamHttpClient;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Channel;
use Sattleme\Checkout\Internal\Checkout\CheckoutLink;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Currencies;
use Sattleme\Checkout\Internal\Decimal;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\Environment;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Lightning\Lnurl;
use Sattleme\Checkout\Internal\Lightning\PayRequest;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Parallel;
use Sattleme\Checkout\Internal\NestedCallException;
use Sattleme\Checkout\Internal\NeverCancelled;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Onchain\BitcoinNode;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Internal\Onchain\Explorer;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\Leasing;
use Sattleme\Checkout\Internal\Onchain\Lot;
use Sattleme\Checkout\Internal\Onchain\Methods;
use Sattleme\Checkout\Internal\Onchain\NodeFailures;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\RestPart;
use Sattleme\Checkout\Internal\Onchain\Watch;
use Sattleme\Checkout\Internal\Order;
use Sattleme\Checkout\Internal\PaymentCheck;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Rates;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\RepeatedWarnings;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\SaleInvoice;
use Sattleme\Checkout\Internal\SaleRecords;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Internal\Snapshot;
use Sattleme\Checkout\Internal\StoredInvoice;
use Sattleme\Checkout\Net\Connector;
use Sattleme\Checkout\Net\TlsConnector;

/**
 * sattle.me checkout, for the server of an online shop (checkout-spec, SPEC.md §4): an
 * integration key in, invoices out, and the check of their payment.
 *
 * Every method blocks until it is done, and takes an optional Cancellation (TimeoutCancellation
 * gives a deadline): a cancelled call stops its requests and throws CancelledException. Every
 * other failure is a SattlemeException, with getErrorCode() and isRetryable().
 */
final class Client
{
    /** The SDK's version. */
    public const VERSION = '1.0.0';

    /** The version of checkout-spec it implements. */
    public const SPEC_VERSION = Protocol::SPEC_VERSION;

    /** The options a client takes: any other is refused (a typo would be ignored). */
    private const OPTIONS = ['key', 'rateSource', 'coingeckoApiKey', 'relayTimeoutMs', 'httpTimeoutMs', 'httpClient', 'connector', 'logger', 'now', 'random'];

    /** The options waitForPayment takes. */
    private const WAIT_OPTIONS = ['intervalMs', 'onStatus'];

    private readonly IntegrationKey $key;

    /** @var list<string|RateSource|\Closure> */
    private readonly array $rateSources;

    private readonly ?Secret $coingeckoApiKey;

    private readonly Context $context;

    private readonly Locks $locks;

    /**
     * @param array<string, mixed> $options `key` the integration key (a secret: from the server's
     *                                      settings, never logged); `rateSource` the rate sources of
     *                                      orders in a currency (a name, a RateSource, a Closure, or a
     *                                      list of them; by default coingecko, mempool, coinbase,
     *                                      kraken, opennode); `coingeckoApiKey` CoinGecko's Demo key;
     *                                      `relayTimeoutMs` and `httpTimeoutMs` (10 000, 100 to
     *                                      60 000); `httpClient` (an HttpClient) and `connector` (a
     *                                      Connector: TCP and TLS), for proxies and tests; `logger`
     *                                      for warnings (a Closure(string, array), or a PSR-3
     *                                      logger); `now` and `random` (Closures) for tests only
     *
     * @throws Exception\InvalidKeyException|InvalidOptionException
     * @throws Exception\UnsupportedEnvironmentException when PHP lacks what the SDK needs
     */
    public function __construct(#[\SensitiveParameter] array $options)
    {
        Environment::check();
        foreach (array_keys($options) as $name) {
            if (!\in_array($name, self::OPTIONS, true)) {
                throw new InvalidOptionException('options', 'Unknown option: ' . $name);
            }
        }
        $this->key = IntegrationKey::parse($options['key'] ?? null);
        $sources = \array_key_exists('rateSource', $options) ? Rates::sources($options['rateSource']) : Protocol::DEFAULT_RATE_SOURCES;
        if ($sources === null) {
            throw new InvalidOptionException('rateSource', Order::RATE_SOURCE_RULE);
        }
        $this->rateSources = $sources;
        $coingeckoApiKey = $options['coingeckoApiKey'] ?? null;
        if ($coingeckoApiKey !== null && (!\is_string($coingeckoApiKey) || preg_match('/^[\x21-\x7E]+$/D', $coingeckoApiKey) !== 1)) {
            throw new InvalidOptionException('coingeckoApiKey', 'coingeckoApiKey: CoinGecko\'s Demo key, a string of visible ASCII characters');
        }
        $this->coingeckoApiKey = $coingeckoApiKey === null ? null : new Secret($coingeckoApiKey);
        $relayTimeoutMs = self::timeout($options, 'relayTimeoutMs');
        $httpTimeoutMs = self::timeout($options, 'httpTimeoutMs');
        $connector = $options['connector'] ?? new TlsConnector();
        if (!$connector instanceof Connector) {
            throw new InvalidOptionException('connector', 'connector: a ' . Connector::class);
        }
        $http = $options['httpClient'] ?? new StreamHttpClient($connector);
        if (!$http instanceof HttpClient) {
            throw new InvalidOptionException('httpClient', 'httpClient: a ' . HttpClient::class);
        }
        $now = $options['now'] ?? static fn(): int => time();
        if (!$now instanceof \Closure) {
            throw new InvalidOptionException('now', 'now: a Closure giving Unix seconds');
        }
        $random = $options['random'] ?? static fn(int $bytes): string => $bytes > 0 ? random_bytes($bytes) : '';
        if (!$random instanceof \Closure) {
            throw new InvalidOptionException('random', 'random: a Closure giving that many random bytes');
        }
        $this->context = new Context($connector, $http, $now, $random, self::logger($options['logger'] ?? null), $relayTimeoutMs, $httpTimeoutMs);
        $this->locks = new Locks();
    }

    /**
     * The merchant as the integration sees it (SPEC.md §4): its business's name, the sats its
     * Lightning address takes, the deposit addresses of the integration's lot.
     *
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function merchant(?Cancellation $cancellation = null): Merchant
    {
        return $this->call(function (RelayPool $pool): Merchant {
            $channel = Channel::read($pool, $this->context, $this->key, $relays);
            $lightningAddress = $channel->lightningAddress;
            \assert($lightningAddress !== null);
            [$pay, $lot] = Parallel::all([
                fn(): PayRequest => Lnurl::payRequest($this->context, $lightningAddress),
                fn(): array => Lot::read($pool, $this->context, $this->key, $relays),
            ]);
            \assert($pay instanceof PayRequest && \is_array($lot));
            [$min, $max] = $pay->sendable();

            return new Merchant($channel->businessName, $min, $max, \count($lot));
        }, $cancellation);
    }

    /**
     * An invoice for an order (SPEC.md §17): a Lightning invoice, a deposit address, or both,
     * declared for the order's sale before it is returned; with `checkout`, its checkout link.
     *
     * @param array<string, mixed> $order `ref` (1 to 64 of A–Z a–z 0–9 . _ : -, never reused for
     *                                    another order); `sats` (a string of digits, or an int) or
     *                                    `fiat` (['amount' => '24.50', 'currency' => 'CHF']);
     *                                    `rateSource` (with fiat); `durationSeconds` (60 to 3600, 900);
     *                                    `note`; `checkout` (bool); `redirectUrl` (with checkout)
     *
     * @throws InvoicePendingException while an earlier invoice of the order may still be paid
     * @throws OrderPaidException      once an earlier invoice of the order was paid
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function createInvoice(array $order, ?Cancellation $cancellation = null): Invoice
    {
        $checked = Order::check($order, $this->rateSources);

        return $this->call(fn(RelayPool $pool): Invoice => $this->locks->with('ref/' . $checked->ref, fn(): Invoice => $this->create($pool, $checked, $cancellation ?? new NeverCancelled())), $cancellation);
    }

    /**
     * The payment status of an invoice (SPEC.md §22): an Invoice, a DeclaredInvoice, or the JSON
     * stored (decoded, as arrays or objects).
     *
     * @param Invoice|DeclaredInvoice|array<string, mixed>|\stdClass $invoice
     *
     * @throws InvalidOrderException (invoice) when it is not an invoice createInvoice made
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function getStatus(Invoice|DeclaredInvoice|array|\stdClass $invoice, ?Cancellation $cancellation = null): PaymentStatus
    {
        $stored = StoredInvoice::check($invoice);

        return $this->call(function (RelayPool $pool) use ($stored): PaymentStatus {
            if ($stored->onchainAddress === null) {
                return PaymentCheck::lightningOnly($this->context, $stored); // No relay read.
            }
            try {
                $check = $this->paymentCheck($pool, new NodeFailures());
            } catch (SattlemeException $e) {
                return $this->paidByLightningElse($stored, $e);
            }

            return $check->check($stored);
        }, $cancellation);
    }

    /**
     * Waits for the payment of an invoice (SPEC.md §23): checks it, hands each status to
     * `onStatus`, returns once it is paid or expired. A network error is retried; any other error
     * ends it. With an address, its on-chain part is checked when the explorer's WebSocket
     * (mempool.space's, or the merchant's Bitcoin node's) says something changed (and every 60 s
     * at least), its Lightning parts every `intervalMs`. An on-chain payment takes about 30 minutes to be paid once unconfirmed: give a Cancellation
     * (TimeoutCancellation) to stop waiting, and check again later with getStatus.
     *
     * @param Invoice|DeclaredInvoice|array<string, mixed>|\stdClass $invoice
     * @param array<string, mixed> $options `intervalMs` (2000, 250 to 60 000), `onStatus` (a
     *                                      callable taking each PaymentStatus)
     *
     * @throws InvalidOrderException|InvalidOptionException before any request
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function waitForPayment(Invoice|DeclaredInvoice|array|\stdClass $invoice, array $options = [], ?Cancellation $cancellation = null): PaymentStatus
    {
        foreach (array_keys($options) as $name) {
            if (!\in_array($name, self::WAIT_OPTIONS, true)) {
                throw new InvalidOptionException('options', 'Unknown option of waitForPayment: ' . $name);
            }
        }
        $intervalMs = $options['intervalMs'] ?? Protocol::DEFAULT_INTERVAL_MS;
        if (!\is_int($intervalMs) || $intervalMs < Protocol::MIN_INTERVAL_MS || $intervalMs > Protocol::MAX_INTERVAL_MS) {
            throw new InvalidOptionException('intervalMs', 'intervalMs: an integer, 250 to 60000');
        }
        $onStatus = $options['onStatus'] ?? null;
        if ($onStatus !== null && !\is_callable($onStatus)) {
            throw new InvalidOptionException('onStatus', 'onStatus: a callable taking each PaymentStatus');
        }
        $stored = StoredInvoice::check($invoice);
        $report = static function (PaymentStatus $status) use ($onStatus): void {
            if ($onStatus !== null) {
                $onStatus($status);
            }
        };

        return $this->call(fn(RelayPool $pool): PaymentStatus => $stored->onchainAddress === null
            ? $this->waitLightning($stored, $intervalMs, $report)
            : $this->waitOnchain($stored, $intervalMs, $report), $cancellation);
    }

    /**
     * The invoices of an order (SPEC.md §24), oldest first, as the merchant's registry holds them:
     * to find an order's invoice again after a failure.
     *
     * @return list<DeclaredInvoice>
     *
     * @throws InvalidOrderException (ref)
     * @throws SattlemeException
     * @throws CancelledException
     */
    public function findInvoices(string $ref, ?Cancellation $cancellation = null): array
    {
        Order::ref($ref);

        return $this->call(function (RelayPool $pool) use ($ref): array {
            $channel = Channel::read($pool, $this->context, $this->key, $relays);
            $records = SaleRecords::read($pool, $this->context, Sale::of($this->key, $ref), self::registry($channel), $relays);

            return array_map(static fn(SaleInvoice $invoice): DeclaredInvoice => $invoice->invoice, $records->invoices);
        }, $cancellation);
    }

    /**
     * The decimals of the currency whose code is exactly $code in the SDK's table (SPEC.md §4,
     * Functions: checkout-spec's currencies.json, never the platform's locale data): 2 for CHF, 0
     * for JPY, 3 for KWD. Null for any other text: a code in lower case or around spaces, a fund,
     * a metal, a test code, BTC, a numeric code.
     *
     * It needs no client, no key and no request: a shop asks it whether it may offer its customers
     * to pay in a currency (createInvoice's `fiat` takes it), and how many decimals its prices may
     * have there (an amount with more is refused, `invalid_order`).
     */
    public static function currencyDigits(string $code): ?int
    {
        return Currencies::digits($code);
    }

    /** @return array<string, mixed> never a key */
    public function __debugInfo(): array
    {
        return ['team' => $this->key->team, 'relays' => $this->key->relays, 'key' => '[secret]'];
    }

    /** A client holds the integration key: it is never serialized. */
    public function __serialize(): array
    {
        throw new \LogicException('A sattle.me client cannot be serialized: it holds the integration key');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A sattle.me client cannot be unserialized');
    }

    /**
     * Steps 2 to 10 of SPEC.md §17, the order checked.
     */
    private function create(RelayPool $pool, Order $order, Cancellation $cancellation): Invoice
    {
        // 2. The channel.
        $channel = Channel::read($pool, $this->context, $this->key, $relays);
        if ($order->checkout && $channel->checkoutOrigin === null) {
            throw new CheckoutUnavailableException();
        }
        $lightningAddress = $channel->lightningAddress;
        \assert($lightningAddress !== null);
        $registry = self::registry($channel);
        $sale = Sale::of($this->key, $order->ref);
        // The explorer of the call (SPEC.md §31): the channel's node with its fallback, else
        // mempool.space; the node's failures shared by the call's reads.
        $explorer = new Explorer($this->context, $channel->bitcoinNode, new NodeFailures());

        // 3. At once: the earlier invoices of the order, the pay request, the rate.
        [[, $earlierError], [$pay, $payError], [$rate, $rateError]] = Parallel::settle([
            fn() => $this->checkEarlierInvoices($pool, $channel, $relays, $registry, $sale, $order, $explorer),
            fn(): PayRequest => Lnurl::payRequest($this->context, $lightningAddress),
            fn(): ?array => $order->fiat === null || $order->rateSources === null ? null
                : Rates::first($this->context, $order->rateSources, $order->fiat->currency, $this->coingeckoApiKey?->reveal(), $cancellation),
        ]);
        foreach ([$earlierError, $payError, $rateError] as $error) {
            if ($error !== null) {
                throw $error;
            }
        }
        \assert($pay instanceof PayRequest);

        // 4. The amount and its methods.
        $rateInfo = null;
        if ($order->fiat !== null) {
            \assert(\is_array($rate) && $rate[0] instanceof Decimal && $rate[1] instanceof RateInfo);
            $minor = $order->fiat->minor;
            $sats = $rate[0]->sats($minor, $order->fiat->digits);
            $rateInfo = $rate[1];
            if (BigInt::cmp($sats, (string) Protocol::MAX_SATS) > 0) {
                throw new InvalidOrderException('fiat', 'fiat: above 21 000 000 BTC at this rate');
            }
            $declared = ['minor' => (int) $minor, 'digits' => $order->fiat->digits, 'currency' => $order->fiat->currency];
            $linkFiat = $declared;
        } else {
            $sats = $order->sats;
            \assert($sats !== null);
            $declared = ['minor' => (int) $sats, 'digits' => 0, 'currency' => Protocol::SATS_CURRENCY];
            $linkFiat = null;
        }
        [$min, $max] = $pay->sendable();
        $byAmount = Methods::byAmount($sats, $min, $max, $order->fiat === null ? 'sats' : 'fiat');

        // 5. and 6. The Lightning invoice, checked.
        $pr = null;
        $verify = null;
        $decoded = null;
        if ($byAmount['lightning']) {
            $asked = Lnurl::requestInvoice($this->context, $pay, $sats, $order->durationSeconds, $order->label);
            [$pr, $verify] = [$asked['pr'], $asked['verify']];
            $decoded = Lnurl::checkInvoice($pr, $verify, $pay, $sats, $order->durationSeconds, $this->context->now());
        }
        // Without Lightning, the Invoice's createdAt is its lease's as first published.
        $now = $this->context->now();
        $createdAt = $decoded !== null ? $decoded->timestamp : $now;
        $expiresAt = $decoded !== null ? $decoded->expiresAt() : $now + $order->durationSeconds;
        // The lease of an address: it ends at expiresAt + 120 and keeps the channel's tolerance.
        $leaseOf = static fn(string $address, bool $confirmed): array => Lease::inside(
            $address,
            (int) $sats,
            $pr,
            $verify,
            $createdAt,
            $expiresAt,
            $sale->keyHex(),
            $declared,
            $order->label,
            $sale->takerTag,
            $channel->tolerance(),
            $confirmed,
        );
        // An invoice whose declaration or, with on-chain, whose lease (which seals it twice too)
        // does not fit a NIP-44 plaintext fails now, before anything is published (SPEC.md §17,
        // step 6): by their sizes alone, the lease's with an address of the one length of every
        // deposit address (the address is not leased yet).
        $inside = $decoded === null ? null : Declaration::inside($pr, $verify, $sale->keyHex(), $declared, $order->label, $sale->takerTag, null);
        if ($inside !== null && !Declaration::fits($inside)) {
            throw new InvalidInvoiceException('The invoice\'s declaration does not fit a NIP-44 plaintext');
        }
        if ($byAmount['onchain'] && !Lease::fits($leaseOf(DepositAddress::SIZED, false))) {
            throw new InvalidInvoiceException('The invoice\'s lease does not fit a NIP-44 plaintext');
        }

        // 7. The address: published unconfirmed, confirmed, then marked (SPEC.md §31, "Leasing an
        // address").
        $address = null;
        $leasing = new Leasing($pool, $this->context, $this->key, $registry, $relays, $explorer);
        $outcome = [Methods::NONE];
        if ($byAmount['onchain']) {
            $outcome = $leasing->lease($sale, $leaseOf, $now);
        }
        Methods::afterLease($byAmount, $outcome[0]);
        if ($outcome[0] === Methods::LEASED) {
            $address = $outcome[1];
        }

        // A failure below leaves the lease as it is: confirmed, it holds its address until its end
        // (a withdrawal some relays only took would leave them disagreeing), and §21 may hand its
        // invoice out.
        // 8. The checkout link, before the declaration: when it fails, nothing is declared.
        $checkoutUrl = null;
        if ($order->checkout) {
            $value = $address === null
                ? CheckoutLink::valueV1($channel->businessName, (string) $pr, (string) $verify, $linkFiat, $order->label, $order->redirectUrl)
                : CheckoutLink::valueV2($channel->businessName, $pr, $verify, $address, (int) $sats, $createdAt, $expiresAt, $linkFiat, $order->label, $order->redirectUrl, $this->restFields($order->ref, $address, $expiresAt), BitcoinNode::forRecord($channel->bitcoinNode));
            $checkoutUrl = $this->publishLink($pool, $channel, $relays, $value, $expiresAt);
        }
        // 9. The declaration, on a majority: else the invoice is never shown.
        if ($decoded !== null && $inside !== null) {
            try {
                $event = Declaration::event($sale, $registry, $decoded->paymentHash, $inside, $this->context->now(), $this->context);
            } catch (\LengthException) {
                throw new InvalidInvoiceException('The invoice\'s declaration does not fit a NIP-44 plaintext');
            }
            if (!$pool->publish($relays, $event, static fn(): string => $sale->secret->reveal())) {
                throw new NetworkException('Fewer than a majority of the merchant\'s relays took the invoice\'s declaration: do not show this invoice, try again');
            }
        }

        // 10. The invoice.
        return new Invoice(
            $order->ref,
            $pr,
            $decoded?->paymentHash,
            $verify,
            $address,
            DepositAddress::paymentUri($address, $sats, $pr),
            $sats,
            $order->fiat,
            $rateInfo,
            $order->label,
            $createdAt,
            $expiresAt,
            $checkoutUrl,
            $order->checkout ? $order->redirectUrl : null,
        );
    }

    /**
     * One order, one invoice at a time (SPEC.md §21): InvoicePendingException while an earlier
     * invoice may still be paid (published again, with a new checkout link when the order asks
     * for one), OrderPaidException once one was paid.
     *
     * @param list<string> $relays
     *
     * @throws SattlemeException
     */
    private function checkEarlierInvoices(RelayPool $pool, #[\SensitiveParameter] Channel $channel, array $relays, Registry $registry, Sale $sale, Order $order, Explorer $explorer): void
    {
        $records = SaleRecords::read($pool, $this->context, $sale, $registry, $relays);
        $check = new PaymentCheck($this->context, $pool, $this->key, $channel, $relays, $this->locks, $explorer);
        $mayBePaid = array_map(
            static fn(int $index): SaleInvoice => $records->invoices[$index],
            SaleRecords::mayStillBePaid(array_map(static fn(SaleInvoice $earlier): int => $earlier->invoice->expiresAt, $records->invoices), $this->context->now()),
        );
        if ($mayBePaid !== []) {
            // 1. Their payments checked, even of one alone: one already paid is never handed out
            // again. Several: an earlier createInvoice failed once a minority of the relays took
            // its last record, and a later one made another invoice and showed it; the sale's
            // records read again on every relay at the same time: the one shown is the one a
            // majority of the relays holds.
            $results = [];
            $everywhere = null;
            $readError = null;
            $tasks = [function () use (&$results, $mayBePaid, $check, $records): void {
                $results = $this->payments($mayBePaid, $check, $records);
            }];
            if (\count($mayBePaid) > 1) {
                $tasks[] = function () use (&$everywhere, &$readError, $pool, $sale, $registry, $relays): void {
                    try {
                        $everywhere = SaleRecords::read($pool, $this->context, $sale, $registry, $relays, true);
                    } catch (NetworkException $e) {
                        $readError = $e;
                    }
                };
            }
            Parallel::all($tasks);
            $held = [true];
            if (\count($mayBePaid) > 1) {
                $held = $everywhere === null ? null : SaleRecords::held(
                    array_map(static fn(SaleInvoice $earlier): string => $earlier->lastRecord()->id, $mayBePaid),
                    $everywhere->sentBy,
                    \count($relays),
                );
            }
            $outcome = SaleRecords::pendingOutcome($results, $held);
            if (isset($outcome['paid'])) {
                $status = $results[$outcome['paid']];
                \assert($status instanceof PaymentStatus);

                throw new OrderPaidException($mayBePaid[$outcome['paid']]->invoice, $status);
            }
            if (isset($outcome['failed'])) {
                $error = $results[$outcome['failed']];
                \assert($error instanceof SattlemeException);

                throw $error; // That invoice may have been paid.
            }
            if (isset($outcome['readFailed'])) {
                \assert($readError instanceof NetworkException);

                throw $readError; // A payment proves itself; which invoice was shown cannot be told.
            }
            $open = $mayBePaid[$outcome['handOut']];
            // Its declaration and its lease again (their first publication may have failed); its
            // lease, with an address, was confirmed: the address is the order's.
            $secret = static fn(): string => $sale->secret->reveal();
            if ($open->declaration !== null && !$pool->publish($relays, $open->declaration, $secret)) {
                throw new NetworkException('Fewer than a majority of the merchant\'s relays took the pending invoice\'s declaration: try again');
            }
            if ($open->lease !== null && !$pool->publish($relays, $open->lease->event, $secret)) {
                throw new NetworkException('Fewer than a majority of the merchant\'s relays took the pending invoice\'s lease: try again');
            }
            $checkoutUrl = $order->checkout ? $this->publishLink($pool, $channel, $relays, $this->linkValueOf($channel, $open->invoice, $order->redirectUrl), $open->invoice->expiresAt) : null;

            throw new InvoicePendingException($open->invoice, $checkoutUrl);
        }
        // 2. Every earlier invoice's payment, 4 at a time.
        $outcomes = $this->payments($records->invoices, $check, $records);
        foreach ($outcomes as $index => $status) {
            if ($status instanceof PaymentStatus && $status->state === PaymentStatus::PAID) {
                throw new OrderPaidException($records->invoices[$index]->invoice, $status);
            }
        }
        foreach ($outcomes as $index => $status) {
            if ($status instanceof PaymentStatus && ($status->state === PaymentStatus::UNCONFIRMED || $status->state === PaymentStatus::OPEN)) {
                throw new InvoicePendingException($records->invoices[$index]->invoice, null);
            }
        }
        foreach ($outcomes as $status) {
            if ($status instanceof SattlemeException) {
                throw $status;
            }
        }
    }

    /**
     * The payments of $invoices (SPEC.md §22), 4 at a time, the rest invoices from $records: each
     * a PaymentStatus, or its error (a paid invoice wins over a service that did not answer about
     * another one).
     *
     * @param list<SaleInvoice> $invoices
     *
     * @return list<PaymentStatus|SattlemeException>
     */
    private function payments(array $invoices, PaymentCheck $check, #[\SensitiveParameter] SaleRecords $records): array
    {
        $tasks = [];
        foreach ($invoices as $earlier) {
            $tasks[] = function () use ($earlier, $check, $records): PaymentStatus {
                $stored = StoredInvoice::check($earlier->invoice);

                return $stored->onchainAddress === null ? PaymentCheck::lightningOnly($this->context, $stored) : $check->check($stored, $records);
            };
        }
        $results = [];
        foreach (Parallel::settle($tasks, Protocol::MAX_PARALLEL_PAYMENT_CHECKS) as [$status, $error]) {
            if ($error !== null) {
                if (!$error instanceof SattlemeException) {
                    throw $error; // Not of this package (a bug, a callback calling the client): at once.
                }
                $results[] = $error;
            } else {
                \assert($status instanceof PaymentStatus);
                $results[] = $status;
            }
        }

        return $results;
    }

    /**
     * Publishes a checkout link (SPEC.md §30) on a majority of the merchant's relays: its URL.
     * Its author's secret signs and authenticates, then is forgotten.
     *
     * @param list<string>         $relays
     * @param array<string, mixed> $value
     *
     * @throws NetworkException|InvalidInvoiceException|CheckoutUnavailableException
     */
    private function publishLink(RelayPool $pool, #[\SensitiveParameter] Channel $channel, array $relays, array $value, int $expiresAt): string
    {
        $origin = $channel->checkoutOrigin;
        if ($origin === null) {
            throw new CheckoutUnavailableException();
        }
        $linkKey = $this->context->random(32);
        $secret = new Secret($this->context->randomSecret());
        try {
            $event = CheckoutLink::event($value, $linkKey, $secret->reveal(), $this->context->now(), $expiresAt, $this->context->random(...));
        } catch (\LengthException) {
            throw new InvalidInvoiceException('The checkout link\'s record does not fit a NIP-44 plaintext');
        }
        if (!$pool->publish($relays, $event, static fn(): string => $secret->reveal())) {
            throw new NetworkException('Fewer than a majority of the merchant\'s relays took the checkout link: try again');
        }

        return CheckoutLink::url($origin, $event->pubkey, $linkKey, $relays);
    }

    /**
     * The record of a new checkout link to a declared invoice (SPEC.md §21): from the
     * DeclaredInvoice, with this order's redirect URL, and with an address its rest fields.
     *
     * @return array<string, mixed>
     */
    private function linkValueOf(#[\SensitiveParameter] Channel $channel, DeclaredInvoice $invoice, ?string $redirectUrl): array
    {
        $fiat = $invoice->fiat === null ? null : ['minor' => (int) $invoice->fiat->minor, 'digits' => $invoice->fiat->digits, 'currency' => $invoice->fiat->currency];
        if ($invoice->onchainAddress === null) {
            return CheckoutLink::valueV1($channel->businessName, (string) $invoice->bolt11, (string) $invoice->verifyUrl, $fiat, $invoice->note, $redirectUrl);
        }

        return CheckoutLink::valueV2(
            $channel->businessName,
            $invoice->bolt11,
            $invoice->verifyUrl,
            $invoice->onchainAddress,
            (int) $invoice->sats,
            $invoice->createdAt,
            $invoice->expiresAt,
            $fiat,
            $invoice->note,
            $redirectUrl,
            $this->restFields($invoice->ref, $invoice->onchainAddress, $invoice->expiresAt),
            BitcoinNode::forRecord($channel->bitcoinNode),
        );
    }

    /**
     * A checkout link's rest fields: the rest author and the rest key (hex), never the rest secret.
     *
     * @return array{0: string, 1: string}
     */
    private function restFields(string $ref, string $address, int $expiresAt): array
    {
        $keys = RestKeys::of($this->key, $ref, $address, $expiresAt);

        return [$keys->author, bin2hex($keys->key->reveal())];
    }

    /**
     * waitForPayment of an invoice without an address: its Lightning part every $intervalMs.
     *
     * @param \Closure(PaymentStatus): void $report
     */
    private function waitLightning(StoredInvoice $stored, int $intervalMs, \Closure $report): PaymentStatus
    {
        $loop = Loop::get();
        $repeated = $this->context->repeated = new RepeatedWarnings();
        try {
            while (true) {
                $repeated->begin('lightning');
                $status = null;
                try {
                    $status = PaymentCheck::lightningOnly($this->context, $stored);
                } catch (NetworkException $e) {
                    $this->context->warn('A payment check failed, tried again: ' . $e->getMessage());
                }
                $repeated->end();
                if ($status !== null) {
                    $report($status);
                    if ($status->isFinal()) {
                        return $status;
                    }
                }
                $loop->sleepUntil(Loop::now() + $intervalMs / 1000);
            }
        } finally {
            $this->context->repeated = null;
        }
    }

    /**
     * waitForPayment of an invoice with an address (SPEC.md §31, "Waiting for an on-chain
     * payment"): its on-chain part (with its rest part) at each change the explorer's WebSocket
     * tells and every 60 s at least, its Lightning part and its rest invoices every $intervalMs.
     *
     * @param \Closure(PaymentStatus): void $report
     */
    private function waitOnchain(StoredInvoice $stored, int $intervalMs, \Closure $report): PaymentStatus
    {
        $loop = Loop::get();
        // The explorer's WebSocket, opened once a check read the channel, of the node the last
        // channel read names; the node's failures of the call, which its checks and its WebSocket
        // share (SPEC.md §31, "in the same call").
        $watch = new Watch($this->context, (string) $stored->onchainAddress);
        $failures = new NodeFailures();
        $snapshot = null;
        $lastOnchain = -\INF;
        $nextTick = Loop::now();
        // A tick combines the last on-chain result: a failure of it is met again by each tick until
        // the next on-chain check, and warned once (SPEC.md §23).
        $repeated = $this->context->repeated = new RepeatedWarnings();
        try {
            while (true) {
                $onchainDue = $lastOnchain + Protocol::ONCHAIN_CHECK_INTERVAL_MS / 1000;
                $changed = $watch->wait($snapshot === null ? Loop::now() : min($nextTick, $onchainDue));
                if ($snapshot === null || $changed || Loop::now() >= $onchainDue) {
                    $repeated->begin('onchain');
                    [$status, $snapshot] = $this->checkOnchain($stored, $failures, $watch);
                    $lastOnchain = Loop::now();
                } elseif (Loop::now() >= $nextTick) {
                    $repeated->begin('tick');
                    [$status, $snapshot, $restPaid] = PaymentCheck::tick($this->context, $stored, $snapshot);
                    if ($restPaid) {
                        // Judged against what was due before: checked again at once, before any status.
                        [$status, $snapshot] = $this->checkOnchain($stored, $failures, $watch);
                        $lastOnchain = Loop::now();
                    }
                } else {
                    continue; // Woken early: nothing due yet.
                }
                $nextTick = Loop::now() + $intervalMs / 1000;
                if ($status instanceof NetworkException) {
                    $this->context->warn('A payment check failed, tried again: ' . $status->getMessage());
                }
                $repeated->end();
                if ($status instanceof NetworkException) {
                    continue;
                }
                if ($status instanceof SattlemeException) {
                    throw $status;
                }
                $report($status);
                if ($status->isFinal()) {
                    return $status;
                }
            }
        } finally {
            $watch->stop();
            $this->context->repeated = null;
        }
    }

    /**
     * A check of every part of an invoice with an address, on a fresh pool: a relay lost during a
     * long wait is connected again. The wait's WebSocket then follows the channel it read.
     *
     * @return array{0: PaymentStatus|SattlemeException, 1: Snapshot}
     */
    private function checkOnchain(StoredInvoice $stored, NodeFailures $failures, Watch $watch): array
    {
        $pool = new RelayPool($this->context);
        try {
            try {
                $check = $this->paymentCheck($pool, $failures);
                $watch->follow($check->explorer);
            } catch (SattlemeException $e) {
                try {
                    $status = $this->paidByLightningElse($stored, $e);
                } catch (SattlemeException $error) {
                    $status = $error;
                }

                return [$status, new Snapshot(null, $e, new RestPart([], $e))];
            }

            return $check->full($stored);
        } catch (NetworkException $e) {
            return [$e, new Snapshot(null, $e, new RestPart([], $e))];
        } finally {
            $pool->close();
        }
    }

    /**
     * The status of an invoice with an address whose channel could not be read ($error: the
     * integration removed, the relays not answering): paid when its Lightning invoice is (a payment
     * proves itself, SPEC.md §31; a removed integration stops the on-chain part alone, §14), else
     * $error.
     *
     * @throws SattlemeException $error, unless its Lightning invoice is paid
     */
    private function paidByLightningElse(StoredInvoice $stored, SattlemeException $error): PaymentStatus
    {
        if ($stored->bolt11 === null) {
            throw $error;
        }
        try {
            $state = Lnurl::verify($this->context, $stored->bolt11, (string) $stored->paymentHash, (string) $stored->verifyUrl, $stored->expiresAt, $this->context->now());
        } catch (SattlemeException) {
            throw $error; // Its Lightning part told nothing: the channel's error stands.
        }
        if ($state[0] !== 'paid') {
            throw $error;
        }

        return PaymentStatus::paidByLightning($state[1]);
    }

    /**
     * The payment check of invoices with an address: the channel read for the merchant's relays,
     * registry and Bitcoin node, the node's failures those of the call ($failures).
     *
     * @throws SattlemeException
     */
    private function paymentCheck(RelayPool $pool, NodeFailures $failures): PaymentCheck
    {
        $channel = Channel::read($pool, $this->context, $this->key, $relays);

        return new PaymentCheck($this->context, $pool, $this->key, $channel, $relays, $this->locks, new Explorer($this->context, $channel->bitcoinNode, $failures));
    }

    /**
     * Runs a method's body as a call of the loop: its relay connections closed once it is done.
     * Never from a callback of another call: that one would wait for this one, and this one could
     * wait for it.
     *
     * @template T
     *
     * @param \Closure(RelayPool): T $body
     *
     * @return T
     *
     * @throws NestedCallException
     * @throws SattlemeException
     * @throws CancelledException
     */
    private function call(\Closure $body, ?Cancellation $cancellation): mixed
    {
        if (Loop::get()->isRunning()) {
            throw new NestedCallException();
        }

        return Loop::get()->run(fn(): mixed => $body(new RelayPool($this->context)), $cancellation);
    }

    private static function registry(#[\SensitiveParameter] Channel $channel): Registry
    {
        $key = $channel->registryKey;
        \assert($key !== null);

        return new Registry($key, $channel->registryReader);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws InvalidOptionException
     */
    private static function timeout(array $options, string $name): int
    {
        $value = $options[$name] ?? Protocol::DEFAULT_TIMEOUT_MS;
        if (!\is_int($value) || $value < Protocol::MIN_TIMEOUT_MS || $value > Protocol::MAX_TIMEOUT_MS) {
            throw new InvalidOptionException($name, "{$name}: an integer, 100 to 60000");
        }

        return $value;
    }

    /**
     * The logger as a Closure: a Closure, an object with warning() (PSR-3), or by default PHP's
     * error_log. Never given a secret.
     *
     * @return \Closure(string, array<string, mixed>): void
     *
     * @throws InvalidOptionException
     */
    private static function logger(mixed $logger): \Closure
    {
        if ($logger === null) {
            return static function (string $message, array $context): void {
                error_log('sattleme/checkout: ' . $message);
            };
        }
        if ($logger instanceof \Closure) {
            return static function (string $message, array $context) use ($logger): void {
                $logger($message, $context);
            };
        }
        if (\is_object($logger) && method_exists($logger, 'warning')) {
            return static function (string $message, array $context) use ($logger): void {
                $logger->warning('sattleme/checkout: ' . $message, $context);
            };
        }

        throw new InvalidOptionException('logger', 'logger: a Closure(string $message, array $context), or a PSR-3 logger');
    }
}
