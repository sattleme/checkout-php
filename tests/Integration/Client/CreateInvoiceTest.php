<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Cancellation;
use Sattleme\Checkout\Exception\CheckoutUnavailableException;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\RateException;
use Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Checkout\Internal\Checkout\CheckoutLink;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Encoders;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Net\HttpResponseSpec;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\Vectors as V;
use Sattleme\Checkout\Tests\Support\World;

/**
 * createInvoice end to end (SPEC.md §17), against local relays and fakes: never the network.
 */
final class CreateInvoiceTest extends TestCase
{
    private World $world;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->channel();
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    public function testAnOrderInSats(): void
    {
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-1', 'sats' => '500', 'note' => 'Two croissants']);

        self::assertSame('ORD-1', $invoice->ref);
        self::assertSame('500', $invoice->sats);
        self::assertNull($invoice->onchainAddress);
        self::assertNull($invoice->fiat);
        self::assertNull($invoice->rate);
        self::assertSame('Two croissants', $invoice->note);
        self::assertNotNull($invoice->bolt11);
        self::assertSame('lightning:' . $invoice->bolt11, $invoice->paymentUri);
        $decoded = Bolt11::decode($invoice->bolt11);
        self::assertNotNull($decoded);
        self::assertSame($decoded->paymentHash, $invoice->paymentHash);
        self::assertSame($this->world->now, $invoice->createdAt);
        self::assertSame($this->world->now + 900, $invoice->expiresAt);
        self::assertSame('Two croissants', $this->world->invoices[$decoded->paymentHash]['comment']);
        self::assertNull($invoice->checkoutUrl);
        self::assertNull($invoice->redirectUrl);

        // Declared on every relay, for this order's sale: the merchant's app reads it.
        $declarations = $this->declarations('ORD-1');
        self::assertCount(3, $declarations);
        foreach ($declarations as $declaration) {
            self::assertSame($invoice->bolt11, $declaration->invoice);
            self::assertSame(['minor' => 500, 'digits' => 0, 'currency' => 'SAT'], $declaration->fiat);
            self::assertSame('Two croissants', $declaration->note);
        }

        // Its payment.
        self::assertSame(['state' => 'open', 'expiresAt' => $invoice->expiresAt, 'received' => '0', 'rest' => null], $client->getStatus($invoice)->toArray());
        $this->world->settle($decoded->paymentHash);
        $status = $client->getStatus($invoice->toArray());
        self::assertSame(PaymentStatus::PAID, $status->state);
        self::assertSame($this->world->invoices[$decoded->paymentHash]['preimage'], $status->preimage);

        // Found again.
        $found = $client->findInvoices('ORD-1');
        self::assertCount(1, $found);
        self::assertSame($invoice->bolt11, $found[0]->bolt11);
        self::assertSame($invoice->expiresAt, $found[0]->expiresAt);
        self::assertSame($invoice->createdAt, $found[0]->createdAt);
        self::assertSame([], $this->world->warnings);
    }

    public function testAnOrderInACurrency(): void
    {
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-2', 'fiat' => ['amount' => '24.50', 'currency' => 'CHF'], 'durationSeconds' => 600]);

        // 2450 minor units at 100 000 CHF: ceil(2450 × 10^8 / (100 000 × 100)) sats.
        self::assertSame('24500', $invoice->sats);
        self::assertSame(['amount' => '24.50', 'currency' => 'CHF', 'minor' => '2450', 'digits' => 2], $invoice->fiat?->toArray());
        self::assertSame(['source' => 'coingecko', 'currency' => 'CHF', 'price' => '100000', 'fetchedAt' => $this->world->now, 'time' => $this->world->now - 30], $invoice->rate?->toArray());
        self::assertSame([], $this->world->warnings, 'CoinGecko answered at once');
        // Every HTTP request with the User-Agent of SPEC.md §15, the same in every implementation
        // and version (CoinGecko refuses a request without one).
        self::assertNotSame([], $this->world->http->requests);
        foreach ($this->world->http->requests as $request) {
            self::assertSame('sattleme-Checkout/1', $request->headers['user-agent'] ?? null, $request->host());
        }
        self::assertSame('ORD-2', $invoice->note, 'no note: the reference');
        self::assertSame($this->world->now + 600, $invoice->expiresAt);
        $declarations = $this->declarations('ORD-2');
        self::assertSame(['minor' => 2450, 'digits' => 2, 'currency' => 'CHF'], $declarations[0]->fiat);
        // Without a lot, no address: Lightning only (from 1 000 sats, an address when one is leased).
        self::assertNull($invoice->onchainAddress);
    }

    /**
     * @return iterable<string, array{0: \Closure, 1: class-string}>
     */
    public static function invoiceAnswers(): iterable
    {
        $metadataHash = hash('sha256', World::METADATA);
        $hash = str_repeat('ab', 32);
        $sats = static fn(HttpRequest $request): int => intdiv((int) $request->query()['amount'], 1000);
        yield 'bolt11' => [static fn(array $answer): array => ['pr' => 'lnbc1notaninvoice'] + $answer, InvalidInvoiceException::class];
        yield 'network' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request), $now, 900, $hash, $metadataHash, 'tb')] + $answer, InvalidInvoiceException::class];
        yield 'amount' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request) + 1, $now, 900, $hash, $metadataHash)] + $answer, InvalidInvoiceException::class];
        yield 'description_hash' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request), $now, 900, $hash, hash('sha256', 'another merchant'))] + $answer, InvalidInvoiceException::class];
        yield 'verify_url' => [static fn(array $answer): array => ['verify' => 'https://elsewhere.example/verify/x'] + $answer, InvalidInvoiceException::class];
        yield 'duration' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request), $now, 901, $hash, $metadataHash)] + $answer, InvalidInvoiceException::class];
        yield 'expired (no expiry)' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request), $now, 0, $hash, $metadataHash)] + $answer, InvalidInvoiceException::class];
        yield 'clock' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request), $now - 301, 900, $hash, $metadataHash)] + $answer, InvalidInvoiceException::class];
        yield 'expired (ended)' => [static fn(array $answer, HttpRequest $request, int $now): array => ['pr' => Encoders::bolt11($sats($request), $now - 300, 300, $hash, $metadataHash)] + $answer, InvalidInvoiceException::class];
        yield 'no verify URL' => [static function (array $answer): array {
            unset($answer['verify']);

            return $answer;
        }, InvalidInvoiceException::class];
        yield 'a refusal' => [static fn(): HttpResponseSpec => HttpResponseSpec::json(['status' => 'ERROR', 'reason' => 'Amount too large']), NetworkException::class];
        yield 'no answer' => [static fn(): HttpResponseSpec => new HttpResponseSpec(502, 'Bad gateway'), NetworkException::class];
    }

    /**
     * Every invoice check (SPEC.md §18): the invoice is never declared nor returned.
     *
     * @param \Closure $answer the callback's answer (as given, the request, now) changed
     * @param class-string $error
     */
    #[DataProvider('invoiceAnswers')]
    public function testEveryInvoiceCheck(\Closure $answer, string $error): void
    {
        $this->world->invoiceAnswer = function (array $given, HttpRequest $request) use ($answer): array|HttpResponseSpec {
            $changed = $answer($given, $request, $this->world->now);
            \assert(\is_array($changed) || $changed instanceof HttpResponseSpec);

            /** @var array<string, mixed>|HttpResponseSpec $changed */
            return $changed;
        };
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-3', 'sats' => '500']);
            self::fail('An invoice that fails its checks');
        } catch (InvalidInvoiceException|NetworkException $e) {
            self::assertInstanceOf($error, $e);
        }
        self::assertSame([], $this->declarations('ORD-3'), 'nothing declared');
    }

    public function testAnInvoiceIsShownOnlyOnceItsDeclarationIsOnAMajority(): void
    {
        $saleAuthor = Sale::of($this->world->integration(), 'ORD-4')->author;
        foreach ([0, 1] as $index) {
            $this->world->relay($index)->refuseEvent = static fn(Event $event): ?string => $event->pubkey === $saleAuthor ? 'blocked: no' : null;
        }
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-4', 'sats' => '500']);
            self::fail('Declared on one relay of three');
        } catch (NetworkException $e) {
            self::assertTrue($e->isRetryable());
        }
        // One relay of three refusing: on a majority, shown.
        $this->world->relay(1)->refuseEvent = null;
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-5', 'sats' => '500']);
        self::assertNotNull($invoice->bolt11);
    }

    public function testTheChannelMovesToOtherRelays(): void
    {
        $moved = ['wss://relay4.example', 'wss://relay5.example'];
        foreach ($moved as $url) {
            $this->world->addRelay($url);
        }
        $this->world->channel(['relays' => $moved], World::RELAYS, $this->world->now - 500);
        $this->world->channel(['relays' => $moved], $moved, $this->world->now - 500);
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-6', 'sats' => '500']);
        self::assertNotNull($invoice->bolt11);
        $sale = Sale::of($this->world->integration(), 'ORD-6');
        foreach ($moved as $url) {
            self::assertNotSame([], array_filter($this->world->relays->get($url)->events, static fn(\stdClass $e): bool => $e->pubkey === $sale->author), 'declared on the merchant\'s relays');
        }
        foreach (World::RELAYS as $url) {
            self::assertSame([], array_values(array_filter($this->world->relays->get($url)->events, static fn(\stdClass $e): bool => $e->pubkey === $sale->author)), 'not on the key\'s relays');
        }
    }

    public function testARemovedIntegration(): void
    {
        $this->world->channel(['status' => 'removed'], null, $this->world->now - 10);
        $this->expectException(RevokedException::class);
        $this->world->client()->createInvoice(['ref' => 'ORD-7', 'sats' => '500']);
    }

    public function testAMuteRelaySlowsNothing(): void
    {
        $this->world->relay(2)->mute = true;
        $start = Loop::now();
        $invoice = $this->world->client(['relayTimeoutMs' => 5000])->createInvoice(['ref' => 'ORD-8', 'sats' => '500']);
        self::assertNotNull($invoice->bolt11);
        self::assertLessThan(TestTime::seconds(3), Loop::now() - $start, 'the mute relay was never awaited');
    }

    public function testTheRateSourcesInOrder(): void
    {
        // The default list: coingecko first; it failing, mempool.
        $this->world->rateAnswers['api.coingecko.com'] = new HttpResponseSpec(503, 'Unavailable');
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-9', 'fiat' => ['amount' => '10', 'currency' => 'EUR']]);
        self::assertSame('mempool', $invoice->rate?->source);
        self::assertSame(['api.coingecko.com', 'mempool.space'], $this->rateHosts());
        self::assertNotSame([], $this->world->warnings, 'the failure is logged');

        // An order's own list, each named source.
        foreach (['coinbase' => 'api.coinbase.com', 'kraken' => 'api.kraken.com', 'opennode' => 'api.opennode.com', 'mempool' => 'mempool.space'] as $name => $host) {
            $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-10-' . $name, 'fiat' => ['amount' => '11', 'currency' => 'USD'], 'rateSource' => [$name]]);
            self::assertSame($name, $invoice->rate?->source);
            self::assertSame('10000', $invoice->sats, '11 USD at 110 000');
        }

        // A custom source, a Closure.
        $client = $this->world->client(['rateSource' => static fn(string $currency, Cancellation $cancellation): string => '50000']);
        $invoice = $client->createInvoice(['ref' => 'ORD-11', 'fiat' => ['amount' => '5', 'currency' => 'CHF']]);
        self::assertSame(['source' => 'custom', 'price' => '50000', 'sats' => '10000'], ['source' => $invoice->rate?->source, 'price' => $invoice->rate?->price, 'sats' => $invoice->sats]);
    }

    public function testEachNamedSourceFailing(): void
    {
        foreach (['coingecko' => 'api.coingecko.com', 'mempool' => 'mempool.space', 'coinbase' => 'api.coinbase.com', 'kraken' => 'api.kraken.com', 'opennode' => 'api.opennode.com'] as $name => $host) {
            // Unavailable, or a redirect (never followed, SPEC.md §15): no answer.
            foreach ([503, 302] as $status) {
                $this->world->rateAnswers[$host] = new HttpResponseSpec($status, '');
                try {
                    $this->world->client()->createInvoice(['ref' => "ORD-F-{$name}-{$status}", 'fiat' => ['amount' => '10', 'currency' => 'USD'], 'rateSource' => $name]);
                    self::fail("{$name} did not answer");
                } catch (NetworkException $e) {
                    self::assertTrue($e->isRetryable(), $name);
                }
            }
            $this->world->rateAnswers[$host] = HttpResponseSpec::json(['unexpected' => true]);
            try {
                $this->world->client()->createInvoice(['ref' => 'ORD-G-' . $name, 'fiat' => ['amount' => '10', 'currency' => 'USD'], 'rateSource' => $name]);
                self::fail("{$name} answered nothing usable");
            } catch (RateException|UnsupportedCurrencyException $e) {
                self::assertContains($e->getErrorCode(), ['rate', 'unsupported_currency'], $name);
            }
            unset($this->world->rateAnswers[$host]);
        }
        self::assertSame([], $this->world->invoices, 'no invoice asked without a price');
    }

    public function testNoUsablePrice(): void
    {
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-12', 'fiat' => ['amount' => '10', 'currency' => 'JPY'], 'rateSource' => ['coingecko', 'kraken']]);
            self::fail('No source has a price in JPY');
        } catch (UnsupportedCurrencyException $e) {
            self::assertSame('JPY', $e->getCurrency());
        }
        $this->world->rateAnswers['api.coingecko.com'] = HttpResponseSpec::json(['bitcoin' => ['chf' => -5]]);
        $this->expectException(RateException::class);
        $this->world->client()->createInvoice(['ref' => 'ORD-13', 'fiat' => ['amount' => '10', 'currency' => 'CHF'], 'rateSource' => 'coingecko']);
    }

    public function testAPayRequestWhoseMetadataHoldsALoneSurrogateIsInvalid(): void
    {
        // SPEC.md §16: metadata holding a lone surrogate (an escape such as \ud800 in the JSON) has
        // no UTF-8 to hash: invalid_invoice, before the callback is asked.
        $this->world->payRequestText = '{"tag":"payRequest","callback":"https://breez.tips/lnurlp/shop/invoice","minSendable":1000,"maxSendable":4000000000,"metadata":"[[\\"text/plain\\",\\"Sunrise \\ud800\\"]]"}';
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-19', 'sats' => '500']);
            self::fail('A pay request whose metadata holds a lone surrogate');
        } catch (InvalidInvoiceException $e) {
            self::assertSame('breez.tips gave no valid LNURL-pay request', $e->getMessage());
        }
        self::assertSame(['/.well-known/lnurlp/shop'], array_map(static fn(HttpRequest $r): string => $r->path(), $this->world->http->requestsTo('breez.tips')), 'its callback never asked');
    }

    public function testAnInvoiceItsDeclarationCannotHoldIsRefusedBeforeAnythingIsPublished(): void
    {
        // An invoice of 50 000 characters: its declaration, which seals it twice, does not fit a
        // NIP-44 plaintext: invalid_invoice before anything is published (SPEC.md §17, step 6),
        // the checkout link, published before the declaration, included.
        $this->world->invoiceLength = 50000;
        $sent = array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays);
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-18', 'sats' => '500', 'checkout' => true]);
            self::fail('An invoice its declaration cannot hold');
        } catch (InvalidInvoiceException $e) {
            self::assertSame('The invoice\'s declaration does not fit a NIP-44 plaintext', $e->getMessage());
        }
        self::assertCount(1, $this->world->invoices, 'its invoice asked');
        self::assertSame($sent, array_map(static fn(LocalRelay $relay): int => \count($relay->received), $this->world->relays->relays), 'nothing published');
    }

    public function testACheckoutLinkPublishedBeforeTheDeclaration(): void
    {
        $order = [];
        foreach ($this->world->relays->relays as $relay) {
            $relay->onEvent = static function (Event $event) use (&$order): void {
                $order[] = $event->pubkey;
            };
        }
        $invoice = $this->world->client()->createInvoice(['ref' => 'ORD-14', 'sats' => '500', 'checkout' => true, 'redirectUrl' => 'https://shop.example/thanks']);
        self::assertNotNull($invoice->checkoutUrl);
        self::assertSame('https://shop.example/thanks', $invoice->redirectUrl);
        self::assertStringStartsWith('https://pay.example/pay#c.', $invoice->checkoutUrl);

        // What the page reads: the record its URL names.
        $fragment = CheckoutLink::fragment(substr($invoice->checkoutUrl, (int) strpos($invoice->checkoutUrl, '#')));
        self::assertSame('link', $fragment[0]);
        self::assertSame(World::RELAYS, $fragment[3]);
        $record = null;
        foreach ($this->world->relay(0)->events as $value) {
            $record ??= SealedRecord::open($value, 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
        }
        self::assertNotNull($record);
        self::assertSame(['tags' => [['d', Derive::linkAddress($fragment[2])], ['expiration', (string) ($invoice->expiresAt + 604800)]]], ['tags' => $record->event->tags]);
        $read = CheckoutLink::read($record->value);
        self::assertNotNull($read);
        self::assertSame('ok', $read[0]);
        self::assertSame(['sats' => '500', 'paymentHash' => $invoice->paymentHash, 'onchainAddress' => null, 'expiresAt' => $invoice->expiresAt, 'redirect' => 'shop.example', 'rest' => null, 'bitcoinNode' => null], $read[1]);

        // The link before the declaration, on each relay.
        $sale = Sale::of($this->world->integration(), 'ORD-14');
        $first = array_search($sale->author, $order, true);
        self::assertSame($fragment[1], $order[0]);
        self::assertGreaterThan(0, $first);
    }

    public function testTheAppVectorsCheckoutLinkEndToEnd(): void
    {
        // PORTING.md, definition of done, point 3: the order of vectors/app/checkout-link.json,
        // with its channel's origin and business's name, its pay request, price, invoice and verify
        // URL: the link createInvoice publishes holds exactly the vector's value.
        $vector = V::object('vectors/app/checkout-link.json');
        $this->world->channel(['profile' => ['businessName' => V::s($vector['businessName']), 'lightningAddress' => 'sattleme02c6047f94@breez.tips', 'currency' => 'CHF'], 'checkout' => ['origin' => V::s($vector['origin'])]], null, $this->world->now - 500);
        $payRequest = V::o($vector['payRequest']);
        $asked = [];
        $this->world->http->on('breez.tips', static function (HttpRequest $request) use ($payRequest, $vector, &$asked): HttpResponseSpec {
            if ($request->path() === '/.well-known/lnurlp/sattleme02c6047f94') {
                return HttpResponseSpec::json($payRequest);
            }
            if ($request->path() === '/lnurlp/sattleme02c6047f94/invoice') {
                $asked[] = $request->query();

                return HttpResponseSpec::json(['pr' => $vector['invoice'], 'routes' => [], 'verify' => $vector['verifyUrl']]);
            }

            return new HttpResponseSpec(404, 'Not found');
        });
        $this->world->prices['CHF'] = V::s($vector['price']);
        $order = V::o($vector['order']);
        $invoice = $this->world->client()->createInvoice($order);
        self::assertSame([['amount' => '21000', 'expiry' => '600', 'comment' => 'Order 3001']], $asked);
        self::assertSame([$vector['invoice'], $vector['paymentHash'], $vector['sats'], $vector['expiresAt']], [$invoice->bolt11, $invoice->paymentHash, $invoice->sats, $invoice->expiresAt]);

        // The link's record, as the web app opens it from the URL.
        $url = (string) $invoice->checkoutUrl;
        self::assertStringStartsWith(V::s($vector['origin']) . '/pay#c.', $url);
        $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
        self::assertSame('link', $fragment[0]);
        $record = null;
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->events as $value) {
                $record ??= SealedRecord::open($value, 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
            }
        }
        self::assertNotNull($record);
        self::assertSame(Json::encode($vector['value']), Json::encode($record->value), 'exactly the vector\'s value, in its order');
        self::assertSame(V::o($vector['event'])['created_at'], $record->event->createdAt);
        self::assertSame(0, \strlen(Nip44::decrypt($record->event->content, Derive::linkEncryptionKey($fragment[2]))) % 256);
    }

    public function testNothingIsDeclaredWhenTheLinkIsNotOnAMajority(): void
    {
        $saleAuthor = Sale::of($this->world->integration(), 'ORD-15')->author;
        foreach ([0, 1] as $index) {
            $this->world->relay($index)->refuseEvent = static fn(Event $event): ?string => $event->pubkey !== $saleAuthor ? 'blocked: no links' : null;
        }
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-15', 'sats' => '500', 'checkout' => true]);
            self::fail('The link on one relay of three');
        } catch (NetworkException) {
            // Expected.
        }
        foreach ($this->world->relays->relays as $relay) {
            self::assertSame([], array_values(array_filter($relay->received, static fn(\stdClass $e): bool => $e->pubkey === $saleAuthor)), 'nothing declared');
        }
    }

    public function testARelayThatForgesCopiesCostsOneSignatureCheck(): void
    {
        // Copies of the channel in a newer format (no MAC to check): its author and address, a
        // right id, a random signature. Each would cost a signature check.
        $address = Derive::channelAddress($this->world->integration()->key->reveal());
        $forged = [];
        for ($i = 0; $i < 600; ++$i) {
            $fields = [0, $this->world->team, $this->world->now - $i, 30078, [['d', $address]], '#'];
            $forged[] = Json::encode(['id' => hash('sha256', Json::encode($fields)), 'pubkey' => $this->world->team, 'created_at' => $this->world->now - $i, 'kind' => 30078, 'tags' => [['d', $address]], 'content' => '#', 'sig' => bin2hex(random_bytes(64))]);
        }
        $this->world->relay(0)->extraEvents = static fn(): array => $forged;
        $started = Loop::now();
        self::assertSame('Sunrise Bakery', $this->world->client()->merchant()->businessName);
        self::assertLessThan(TestTime::seconds(1.5), Loop::now() - $started, 'about 6 s when each forged copy is checked');
    }

    public function testCopiesOfARecordUnderIdsOfTheirOwnAreNotKept(): void
    {
        // A relay sends, before the channel, 40 copies of it under ids of their own: another date
        // and a large tag each, its content and signature as they are. Each decrypts, none checks
        // out: none is kept (SPEC.md §13, Limits). That relay answers first: the read hears it.
        $address = Derive::channelAddress($this->world->integration()->key->reveal());
        $channel = null;
        foreach ($this->world->relay(0)->events as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->tag('d') === $address) {
                $channel = $event;
            }
        }
        self::assertNotNull($channel);
        $copies = [];
        for ($i = 1; $i <= 40; ++$i) {
            $value = $channel->toJson();
            $value->created_at = $channel->createdAt + $i;
            $value->tags = [...$channel->tags, ['x', str_repeat('a', 600000)]];
            $value->id = hash('sha256', Json::encode([0, $value->pubkey, $value->created_at, $value->kind, $value->tags, $value->content]));
            $copies[] = Json::encode($value);
        }
        $this->world->relay(0)->extraEvents = static fn(): array => $copies;
        $this->world->relay(1)->queryDelayMs = $this->world->relay(2)->queryDelayMs = 300;
        $client = $this->world->client();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        self::assertSame('Sunrise Bakery', $client->merchant()->businessName);
        self::assertLessThan(12 * 1048576, memory_get_peak_usage() - $before, '24 MB when each copy is kept');
    }

    public function testACopyIsOfANewerFormatOnlyOnceItsEventChecksOut(): void
    {
        // A relay that sends, besides the channel, a copy in a newer format, newer than the
        // channel, with a random signature: it is ignored, of a newer format or not (SPEC.md §12).
        // That relay answers first: the read always hears it.
        $address = Derive::channelAddress($this->world->integration()->key->reveal());
        $createdAt = $this->world->now - 10;
        $fields = [0, $this->world->team, $createdAt, 30078, [['d', $address]], '#'];
        $forged = Json::encode(['id' => hash('sha256', Json::encode($fields)), 'pubkey' => $this->world->team, 'created_at' => $createdAt, 'kind' => 30078, 'tags' => [['d', $address]], 'content' => '#', 'sig' => bin2hex(random_bytes(64))]);
        $this->world->relay(0)->extraEvents = static fn(): array => [$forged];
        $this->world->relay(1)->queryDelayMs = $this->world->relay(2)->queryDelayMs = 300;
        $this->world->warnings = [];
        self::assertSame('Sunrise Bakery', $this->world->client()->merchant()->businessName);
        self::assertSame([], $this->world->warned(''), 'no newer format seen');
        // The same copy signed by the team, from every relay: of a newer format.
        $newer = Json::encode($this->world->newerChannel($createdAt)->toJson());
        foreach ($this->world->relays->relays as $relay) {
            $relay->queryDelayMs = 0;
            $relay->extraEvents = static fn(): array => [$newer];
        }
        self::assertSame('Sunrise Bakery', $this->world->client()->merchant()->businessName);
        self::assertSame(["The integration's channel is of a newer format: update sattleme/checkout"], $this->world->warned(''));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function forgedCopies(): iterable
    {
        yield 'the forged copy sent before the valid one' => [true, 'Old Bakery'];
        yield 'the forged copy sent after the valid one' => [false, 'New Bakery'];
    }

    #[DataProvider('forgedCopies')]
    public function testEachEventIsCheckedOnItsOwnWhateverElseItsRelaySent(bool $forgedFirst, string $channel): void
    {
        // The first relay holds the newest channel, the two others an older one; the third does
        // not answer: the read goes on with the first two. The first also sends a copy of the
        // newest under its id but another created_at, its signature left as it is: that copy does
        // not check out and is ignored (SPEC.md §11), and nothing else of its relay with it: the
        // newest valid copy of the relays that answered is the channel (SPEC.md §14). Of one
        // relay's events of one id, the first is the one read, a later one only counted (SPEC.md
        // §13, Limits): sent first, the forgery is that relay's event of that id, and it hides
        // the newest channel as a relay that does not send it would.
        $now = $this->world->now;
        $profile = ['lightningAddress' => 'shop@breez.tips', 'currency' => 'CHF'];
        $this->world->channel(['profile' => ['businessName' => 'Old Bakery'] + $profile], [World::RELAYS[1], World::RELAYS[2]], $now - 500);
        $newest = $this->world->channel(['profile' => ['businessName' => 'New Bakery'] + $profile], [World::RELAYS[0]], $now - 400);
        $this->world->relay(2)->mute = true;
        $forged = $newest->toJson();
        $forged->created_at = $forgedFirst ? $now - 450 : $now - 3000;
        if ($forgedFirst) {
            $this->world->relay(0)->extraEvents = static fn(): array => [Json::encode($forged)];
        } else {
            $this->world->relay(0)->store($forged); // older: sent after the newest
        }
        self::assertSame($channel, $this->world->client()->merchant()->businessName);
    }

    public function testAnInvoiceTooLongToRecordIsInvalid(): void
    {
        // A verify URL the checks take (its host is the callback's) but no record holds.
        $this->world->invoiceAnswer = static function (array $answer, HttpRequest $request): array {
            $answer['verify'] = (\is_string($answer['verify'] ?? null) ? $answer['verify'] : '') . '/' . str_repeat('a', 70000);

            /** @var array<string, mixed> $answer */
            return $answer;
        };
        $saleAuthor = Sale::of($this->world->integration(), 'ORD-17')->author;
        $client = $this->world->client();
        // Its checkout link: the first record of an order paid by Lightning only.
        try {
            $client->createInvoice(['ref' => 'ORD-17', 'sats' => '500', 'checkout' => true]);
            self::fail('No record holds it');
        } catch (InvalidInvoiceException) {
        }
        // Its lease: the first record of an order with an address.
        $this->world->lot([World::address()]);
        try {
            $client->createInvoice(['ref' => 'ORD-17', 'sats' => '49000']);
            self::fail('No record holds it');
        } catch (InvalidInvoiceException) {
        }
        foreach ($this->world->relays->relays as $relay) {
            self::assertSame([], array_values(array_filter($relay->received, static fn(\stdClass $e): bool => $e->pubkey === $saleAuthor)), 'nothing published');
        }
    }

    public function testAChannelWithoutACheckoutOrigin(): void
    {
        $this->world->channel(['checkout' => 'none'], null, $this->world->now - 10);
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD-16', 'sats' => '500', 'checkout' => true]);
            self::fail('No web app for checkout links');
        } catch (CheckoutUnavailableException $e) {
            self::assertFalse($e->isRetryable());
        }
        self::assertSame([], $this->world->invoices, 'no invoice asked');
        // Without checkout, the same channel is fine.
        self::assertNotNull($this->world->client()->createInvoice(['ref' => 'ORD-16', 'sats' => '500'])->bolt11);
    }

    public function testANewLinkToThePendingInvoice(): void
    {
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-17', 'sats' => '500', 'checkout' => true]);
        $this->world->now += 60;
        try {
            $client->createInvoice(['ref' => 'ORD-17', 'sats' => '500', 'checkout' => true, 'redirectUrl' => 'myshop://orders/17']);
            self::fail('The first invoice may still be paid');
        } catch (InvoicePendingException $e) {
            self::assertSame($first->bolt11, $e->getInvoice()->bolt11);
            $url = $e->getCheckoutUrl();
            self::assertNotNull($url);
            self::assertNotSame($first->checkoutUrl, $url);
            $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
            self::assertSame('link', $fragment[0]);
            $record = null;
            foreach ($this->world->relay(1)->events as $value) {
                $record ??= SealedRecord::open($value, 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
            }
            self::assertNotNull($record);
            $read = CheckoutLink::read($record->value);
            self::assertSame('myshop://', $read[1]['redirect'] ?? null, 'this order\'s redirect URL');
            self::assertSame($first->paymentHash, $read[1]['paymentHash'] ?? null);
        }
        // Without checkout: no link.
        try {
            $client->createInvoice(['ref' => 'ORD-17', 'sats' => '500']);
            self::fail('Still pending');
        } catch (InvoicePendingException $e) {
            self::assertNull($e->getCheckoutUrl());
        }
    }

    public function testAnOrderIsCheckedBeforeAnyRequest(): void
    {
        try {
            $this->world->client()->createInvoice(['ref' => 'ORD 18', 'sats' => '500']);
            self::fail('A ref with a space');
        } catch (InvalidOrderException $e) {
            self::assertSame('ref', $e->getParam());
        }
        self::assertSame([], $this->world->connector->asked, 'nothing asked');
    }

    /** @return list<string> the rate sources' hosts asked, in order */
    private function rateHosts(): array
    {
        $hosts = [];
        foreach ($this->world->http->requests as $request) {
            if (\in_array($request->host(), ['api.coingecko.com', 'api.coinbase.com', 'api.kraken.com', 'api.opennode.com'], true)
                || ($request->host() === 'mempool.space' && $request->path() === '/api/v1/prices')) {
                $hosts[] = $request->host();
            }
        }

        return $hosts;
    }

    /**
     * The declarations of $ref's sale every relay holds, opened by the merchant.
     *
     * @return list<Declaration>
     */
    private function declarations(string $ref): array
    {
        $sale = Sale::of($this->world->integration(), $ref);
        $declarations = [];
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->events as $event) {
                $opened = Declaration::open($event, $sale, $this->world->registry);
                if ($opened !== null && $opened[0] === 'ok') {
                    $declarations[] = $opened[1];
                }
            }
        }

        return $declarations;
    }
}
