# sattle.me checkout for PHP

Take Lightning and on-chain bitcoin payments for a sattle.me merchant from an online
shop's **server**: an integration key in, invoices out, and the check of their payment. For
custom shops, Magento, WooCommerce and PrestaShop.

- Package: `sattleme/checkout` (Packagist), namespace `Sattleme\Checkout`.
- Version **1.0.0**. It implements [checkout-spec](https://github.com/sattleme/checkout-spec)
  **1.0.0** (protocol 1), its first public version, pinned in [`spec/`](spec) (imported from a
  clone of it by [`tools/spec/import.php`](tools/spec/import.php)) and checked file by file by its
  tests.
- No runtime dependency: PHP and its standard extensions only.

## Requirements

- PHP **8.2** or later, **64-bit**. Tested on Linux (Ubuntu, and the PHP and OpenSSL of AlmaLinux
  8, 9 and 10, as cPanel and CloudLinux hosts run them); CI runs the tests on Windows too.
- Extensions `bcmath`, `openssl`, `json`, `hash` and `pcre` (Composer checks them); OpenSSL with
  the **secp256k1** curve and the **ChaCha20** cipher (tested with OpenSSL 1.1.1, 3.0 and 3.5).
  `gmp` is not needed, and changes nothing when present.
- Outbound HTTPS and WebSocket (`wss://`) connections: to the merchant's Nostr relays, its
  Lightning address, the rate sources, the explorer (mempool.space, or the
  [merchant's Bitcoin node](#the-merchants-bitcoin-node), over `http://` and `ws://` too when it is
  on a local network) and BlockCypher. Every HTTP request and WebSocket handshake carries
  `User-Agent: sattleme-Checkout/1`, the same in every SDK and version.

Some of these hosts are chosen by others: the merchant (its relays, its Lightning address, its
Bitcoin node) and its Lightning address's provider (the URLs it answers with). The SDK checks
their URLs (`https://` or `wss://`, no IP address, no `localhost` nor a name under it; the
merchant's Bitcoin node by a rule of its own, which allows `http` to a local network and an IPv4
address), follows no redirect, and verifies TLS before any request (but to a node asked over
`http`). But a name may still resolve to an address of your own network: a server that holds
several merchants' keys, or sits among services of its own, should only reach public addresses (a
firewall rule, or a proxy through the `httpClient` and `connector` options; a `connector` that
does not implement `Net\PlainConnector` makes no connection without TLS).

A `Client` checks all of this when it is made, and throws `UnsupportedEnvironmentException` with
what is missing: a shop that embeds the SDK (a WordPress plugin) gets no check from Composer.

## Installing

```sh
composer require sattleme/checkout
```

A Magento module or a custom shop declares it as a dependency, unprefixed. A WordPress plugin
(WooCommerce) or a PrestaShop module embeds it in its ZIP with its namespace **prefixed**, so that
two plugins carrying two versions never collide: with [Strauss](https://github.com/BrianHenryIE/strauss)
or [PHP-Scoper](https://github.com/humbug/php-scoper). Both are tested by this repository's CI,
which prefixes the SDK as such a plugin does and runs its whole test suite again on the prefixed
copy ([`tools/prefix/build.sh`](tools/prefix/build.sh)). The prefixed SDK takes about 200 KB in a
plugin's ZIP, and depends on nothing WordPress ships.

## The integration key is a secret

The merchant creates an integration in the sattle.me app and gives you its key,
`sattleme_ck_…`. Whoever holds it can create invoices in the merchant's name and read its on-chain
addresses: keep it in the server's settings or environment, never in the browser, a log, a
repository or a support ticket.

The SDK never prints it: not in an exception, a log, a stack trace's arguments
(`#[\SensitiveParameter]`), nor a serialization (refused). It and the keys derived from it are
held outside the objects that use them, in no property nor closure: a dump of a client
(`var_dump()`, `print_r()`, `var_export()`, an array cast) shows none of them.

If it leaks, the merchant removes the integration in the app ("Remove the integration") and
creates another one: the old key then makes no invoice, finds none and reads no merchant
(`RevokedException`). A check of an invoice it made still finds its Lightning payment: an invoice
without an address is checked as before, and one with an address is `RevokedException` unless its
Lightning invoice was paid (SPEC.md §14). The merchant settles by hand the orders still being paid
on-chain.

**One key, one shop.** The merchant creates one integration for each shop (each system with its
own orders' references), and two shops never share a key: the sale of an order is derived from the
key and the order's reference, so two shops with one key would share their references, and an
order of one would be found paid, or pending, with an invoice of the other. The servers of one
shop share its key.

## Quick start

```php
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\PaymentStatus;

$client = new Client(['key' => getenv('SATTLEME_INTEGRATION_KEY')]);

// Lock the order in your database around createInvoice (one order, one invoice at a time).
try {
    $invoice = $client->createInvoice([
        'ref' => 'ORD-1042',         // your order's reference, never reused for another order
        'sats' => '49000',           // or 'fiat' => ['amount' => '24.50', 'currency' => 'CHF']
        'note' => 'Order 1042',      // shown to the merchant and the customer (else the reference)
        'durationSeconds' => 900,    // 60 to 3600
    ]);
} catch (InvoicePendingException $e) {
    $invoice = $e->getInvoice();     // an earlier invoice of this order, not paid
    // It may be being confirmed, or past its end: show it again only while it is open.
    if ($invoice->expiresAt <= time() || $client->getStatus($invoice)->state !== PaymentStatus::OPEN) {
        return;                      // check it as below, and mark the order as its status says
    }
} catch (OrderPaidException $e) {
    // The order was paid already: $e->getStatus(), $e->getPreimage()
    return;
}

// Store it with the order, as JSON: getStatus() takes it back.
$stored = json_encode($invoice);

// Show $invoice->paymentUri as a QR code (a bitcoin: URI, or lightning:), until $invoice->expiresAt.
```

Then check the payment, and mark the order paid only on a `paid` status from your server's check:

```php
use Sattleme\Checkout\PaymentStatus;

$status = $client->getStatus(json_decode($stored, true));
if ($status->state === PaymentStatus::PAID) {
    // Paid: by Lightning (the preimage is the proof), on-chain or both ("mixed").
}
```

or wait for it, in a worker rather than a web request (an on-chain payment takes about 30 minutes
to be `paid` once `unconfirmed`):

```php
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\TimeoutCancellation;

$status = $client->waitForPayment($invoice, [
    'intervalMs' => 2000,
    'onStatus' => function (PaymentStatus $status): void {
        // The status of each check: open, unconfirmed, paid or expired.
    },
], new TimeoutCancellation(3600));
```

**Stored invoices are your shop's.** Check only the invoices your shop stored itself with their
orders, where only it writes (its database), never one that comes from the customer (a form field,
a URL, a cookie): `getStatus` checks that a stored invoice is well formed (SPEC.md §22), never that
this integration made it. An invoice someone else wrote, with a Lightning invoice of their own that
they paid, reads as `paid` (SPEC.md §27).

`waitForPayment` returns once the invoice is paid or expired; a network failure is retried (and
logged once while each check meets it again, not at each check), any other error ends it. With an
address, keep checking the payment (`waitForPayment`, or `getStatus`
now and then) while the customer pays, until `expiresAt` and 120 s more: a transaction no check saw
by then is judged by a third party's time (below). Past that, a server that stopped waiting checks
again later with `getStatus`.

Every method blocks until it is done and takes an optional `Cancellation`: `TimeoutCancellation`
gives a deadline; a cancelled call stops its requests and throws `CancelledException`. A callback
of a call (`onStatus`, a custom rate source, the logger) must not call the client: such a call
throws `LogicException` at once (it would run inside the other, which waits for it).

A call runs its requests at once, as tasks of its own on the process's one thread (PHP fibers):
what you give the client (`httpClient`, `connector`, `logger`) is called from several of them
within a call, one at a time, never at once, and while it blocks (a client of your own that waits
for its answer, a name lookup) the call's other requests wait too. Once a call returned, nothing
it started runs on: its tasks are ended and its connections closed.

### Options of the client

| Option            | Default                                               | Rule                                                                                         |
| ----------------- | ----------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `key`             | required                                              | The integration key; else `invalid_key`.                                                     |
| `rateSource`      | `coingecko`, `mempool`, `coinbase`, `kraken`, `opennode` | A rate source, or a list of them (below).                                                 |
| `coingeckoApiKey` | none                                                  | CoinGecko's Demo key (visible ASCII characters), sent to CoinGecko only.                     |
| `relayTimeoutMs`  | 10 000                                                | Per relay request, connection included: 100 to 60 000.                                       |
| `httpTimeoutMs`   | 10 000                                                | Per HTTP request, body included: 100 to 60 000.                                              |
| `httpClient`      | the SDK's own                                         | An `Http\HttpClient`: a proxy, tests. It keeps the SDK's rules: no redirect, its body and its head bounded. |
| `connector`       | `Net\TlsConnector`                                    | A `Net\Connector` (TCP and TLS, for HTTP and WebSocket), and a `Net\PlainConnector` (TCP alone) for a merchant's Bitcoin node on a local network; `new TlsConnector($caFile)` for a CA bundle of your own. |
| `logger`          | `error_log()`                                         | Warnings only, never a secret: a `Closure(string $message, array $context)`, or a PSR-3 logger. |

Any other option is refused (`invalid_option`, its name in `getParam()`): a typo is never ignored.
TLS certificates are always verified.

## Prices in a currency

```php
$invoice = $client->createInvoice([
    'ref' => 'ORD-1043',
    'fiat' => ['amount' => '24.50', 'currency' => 'CHF'],   // a string, never a float
]);
// $invoice->sats: the price converted at $invoice->rate (rounded up to the sat).
```

The currency is an ISO 4217 code in upper case, of the table of
[`currencies.json`](spec/currencies.json) (never your platform's locale data), and the amount has at
most its decimals: `Client::currencyDigits('CHF')` gives them (`2`; `null` for a code that is not
one of the table). It needs no client and makes no request: ask it whether your shop may offer a
currency, and how many decimals its prices may have there. The rate comes from the first source
that answers usably, in order: by default CoinGecko, mempool.space, Coinbase, Kraken, OpenNode. A
price older than an hour, or not a positive exact decimal, is refused. An order or the client may
name its own sources, or a custom one:

```php
use Sattleme\Checkout\Cancellation;

$client = new Client([
    'key' => getenv('SATTLEME_INTEGRATION_KEY'),
    'rateSource' => ['kraken', static fn(string $currency, Cancellation $cancellation): string => '69012.5'],
]);
```

A custom source (a `RateSource`, or a `Closure` of the same signature) gives the price of one
bitcoin as a decimal **string**, or `['price' => '69012.5', 'time' => <Unix seconds>]`; it throws
`UnsupportedCurrencyException` when it has no price in that currency.

## On-chain payments

From 1 000 sats, an invoice may also be paid on-chain: the merchant's app gives the integration a
lot of deposit addresses of its wallet, and each order **leases** one for the time of its invoice.
The invoice then has an `onchainAddress`, and its `paymentUri` is one QR code for both
(`bitcoin:<address>?amount=<BTC>&lightning=<invoice>`). An order below 1 000 sats is Lightning only;
one outside the Lightning address's sendable amounts is on-chain only (`bolt11` null). When no
address can be leased, the order is Lightning only, or fails with `onchain_unavailable`
(retryable): the lot is empty (it is filled again when the merchant opens the app), or its
addresses are leased, paid already, or named by a witness (below).

The lease is published on a majority of the merchant's relays, read again on every relay (it is
confirmed when that read is complete and no other order holds the address), then marked
confirmed: only then is the address the order's. A lease that was not confirmed is withdrawn, and
the next address is tried (three at most). A confirmed lease is never withdrawn, whatever fails
after it (its mark, the checkout link, the declaration): it holds its address until its end, and
the order's next `createInvoice` may hand its invoice out (`InvoicePendingException`). Records
anybody writes at an address's lease (they are public) never give it to another order: at worst
they keep it from orders while they last.

The statuses (`json_encode($status)`, as `$status->toArray()`, is the JSON of
[SPEC.md §3](spec/SPEC.md#3-data-model), as for every type the SDK gives: `Invoice`,
`DeclaredInvoice`, `PaymentStatus`, `Rest`, `Fiat`, `RateInfo`, `Merchant`):

| State         | Meaning                                                                                                                                                                                       |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `open`        | Not paid yet, payable until `expiresAt`. With an underpayment, `received` and a `rest`: what is left, its own Lightning invoice and payment URI (show them: the customer pays the rest). |
| `unconfirmed` | Enough came on-chain in time, not 3 confirmations deep yet (`confirmations`, `txids`): not paid yet, it may still fail.                                                                    |
| `paid`        | `method` `lightning` (`preimage`), `onchain` (`txids`: 3 confirmations on the explorer) or `mixed` (on-chain and invoices of the rest, `preimages`).                                         |
| `expired`     | Never paid, for good. `received` above 0: an underpayment the merchant received, to settle with the customer.                                                                             |

A payment counts when it came while the lease ran: the SDK records each transaction it sees
(witnesses), so that the merchant's app tells the same order paid. A payment no check saw in time
is told by BlockCypher's time, asked only then; a replacement (RBF) keeps the time of the payment
it replaces. A transaction counts for one invoice at most: one that a witness of another invoice
names counts for nothing here. An underpayment stays `open` until the invoice's end, then
`expired` with `received` above 0, unless the merchant's tolerance or acceptance (below) accepted
it.

The merchant may accept a payment a little short of the amount (its tolerance, set in its app):
the status is then `unconfirmed`, then `paid`, with `received` below `sats`. The tolerance is
never written where the customer reads it.

The merchant may also accept by hand, in its app, what an order underpaid on-chain received (the
merchant's acceptance): one short by more than its tolerance, which the merchant would rather
settle at what came. Nothing to configure, nothing to call: every check (`getStatus`,
`waitForPayment`, and `createInvoice`'s check of an order's earlier invoices) reads it on every
relay of the merchant with the order's witnesses, and counts it only when a majority of those
relays hold it. The order is then `unconfirmed`, then `paid` (`onchain`, or `mixed` with invoices
of the rest), with `received` below `sats`, as with the tolerance: ship it as for any `paid`
status. `waitForPayment` sees it at its next check of the on-chain part (within 60 seconds), the
`rest` still given until then. Only the merchant's team can make one: neither your server's key
nor a customer can. The page of a checkout link learns of either only through an acceptance
record (that this payment counts), and sends the customer back to your shop.

The explorer (mempool.space, or the merchant's Bitcoin node, below, and mempool.space too when the
node fails and its fallback allows it) sees your server's IP address and the addresses it asks
about or watches, and a payment counts on what that one explorer shows, 3 confirmations deep;
BlockCypher sees your server's IP address and one transaction, only for a payment no check saw in
time. Never a key. `waitForPayment` watches the address on the explorer's WebSocket, and checks
the on-chain part every 60 seconds at least.

### The merchant's Bitcoin node

The merchant may have the blockchain read from a node of its own that serves mempool.space's API
(an Umbrel, a server of its own) rather than from mempool.space. It sets it in its app, which
writes it in the integration's channel: nothing to configure on your server. Every call reads it
with the channel; every read of an address and of the chain's height (when an address is leased,
at each check), and `waitForPayment`'s WebSocket, then go to the node: `http://192.168.1.10:3006`
is read at `http://192.168.1.10:3006/api` and `ws://192.168.1.10:3006/api/v1/ws` (`wss://` for an
`https://` node). BlockCypher and the `mempool` rate source stay BlockCypher's and mempool.space's.

- **Its URL** follows a rule of its own (SPEC.md §31): `https`, or `http` for a host of a local
  network only (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, a name under `.local`);
  an IPv4 address or a name, never `localhost`, 127.0.0.0/8 nor 0.0.0.0/8; no user name, query nor
  fragment; 200 characters at most. A node the channel names in any other way, or with an invalid
  `fallback` or `payers`, is none: mempool.space is read, and the channel stays valid.
- **Its `fallback`**, when the node does not answer a read (no answer, an error status, a redirect,
  an answer out of format):
  - `mempool`: the same read of mempool.space, `network` when both fail. A node that failed less
    than 60 s ago in the same call (`createInvoice`, `getStatus`, one `waitForPayment`) is asked
    after mempool.space, so that a node that is down does not make each read wait for its timeout.
    The WebSocket goes to mempool.space's when the node's fails, or drops before any message.
  - `none`: mempool.space is never asked. While the node does not answer, no address is leased
    (`createInvoice` gives a Lightning invoice only, or `NetworkException` for an order paid
    on-chain only) and every check of an order with an address is `NetworkException`: never
    `expired` nor final underpaid for want of an answer. The order waits until the node answers
    again.

  A check's two reads (the address's transactions and the chain's height) always come from one
  explorer: when the node fails either, both are asked of mempool.space.

- **A node on the merchant's network** (`http://192.168.1.10:3006`, `http://umbrel.local`) is
  reachable only by a server on that network: a shop the merchant hosts itself, or one that
  joined it (by Tailscale, for one). Any other server fails every read of it, and its fallback
  applies: with `none`, no on-chain payment at all. The SDK asks it in clear HTTP through its
  connector's `Net\PlainConnector` (the default `Net\TlsConnector` is one); a `connector` or an
  `httpClient` of yours that makes no such connection leaves it unreachable, its fallback then
  applying.
- **`payers`**: with `true` (only for a node of the Internet: `https` and a name, not under
  `.local`), the checkout links' records name the node (its `url` and `fallback`, never `payers`),
  and their page reads the chain from it too, with the same fallback; the node then sees the
  customer's IP address and the address the page follows, and whoever holds a link reads the
  node's URL. With `false`, only your server reads the node, and the page reads mempool.space.
- **Security**: a node's URL is the merchant's choice, and the SDK follows a valid one: GET
  requests at the paths of mempool.space's API under `/api`, and the WebSocket's two messages, in
  clear HTTP too to a host of a local network, and to an IPv4 address. Nothing is sent but deposit
  addresses, no redirect is followed, and every answer is bounded and checked. But if your server
  holds the keys of several merchants (a shared host), one merchant could so have it ask machines
  of your own network: restrict what your server can reach to public addresses (a firewall rule, or
  a `connector` and an `httpClient` that only reach them); such a merchant's node is then
  unreachable, and its fallback applies. The node is trusted as mempool.space is: what it shows
  decides whether an order is paid. Over `http`, whoever is on the network between your server and
  the node can change its answers: it is as safe as that network.

## Checkout links

With `'checkout' => true`, the invoice gets a `checkoutUrl`: the merchant's own payment page,
which shows the invoice and checks its payment from the customer's browser. Send the customer
there instead of showing the QR code yourself.

```php
$invoice = $client->createInvoice([
    'ref' => 'ORD-1044',
    'sats' => '21000',
    'checkout' => true,
    'redirectUrl' => 'https://shop.example/orders/1044/thanks',   // or your app's link, myshop://…
]);
header('Location: ' . $invoice->checkoutUrl);
```

Once it is paid, the page sends the customer to `redirectUrl`. Being sent there proves nothing
(anyone can open it): mark the order paid only after your server's check. A channel whose merchant
has no payment page gives `checkout_unavailable`. A pending invoice of the order
(`InvoicePendingException`) that may still be paid comes with a new link to it when the order asks
for one (`getCheckoutUrl()`); one past its end, being confirmed, with none (the link the shop got
goes on following its payment).

## One order, one invoice at a time

Before a new invoice, `createInvoice` reads the order's earlier invoices in the merchant's
registry, so that it holds even when your shop lost what it stored:

- `OrderPaidException` once one was paid: `getInvoice()`, `getStatus()`, `getPreimage()`. The
  payments of the invoices that may still be paid are checked first, so one already paid is never
  handed out again (a customer back at the order a minute after paying);
- `InvoicePendingException` while one may still be paid (until its end and 120 s more), or is
  being confirmed: `getInvoice()` (a `DeclaredInvoice`), `getCheckoutUrl()`. It may be
  `unconfirmed` (an on-chain payment being confirmed), or past its end: show it again only while
  its status (`getStatus`) is `open` and before its `expiresAt`, never one `unconfirmed` (the
  customer would pay twice). One underpaid on-chain has its rest invoice asked first: its status's
  `rest` and its checkout links' pages offer it.

A check of these payments that fails is the error (that invoice may have been paid). Of several
invoices that may still be paid (a call that failed after its lease was confirmed made one), the
one shown (whose last record a majority of the relays holds) is handed out.

One order, one `ref`: never reused for another order by anything that holds the key (a test copy
of the shop, a restored database included), and never taken from what the customer sends (make it
on your server). `findInvoices($ref)` gives the order's invoices, oldest first. Two processes (two
PHP-FPM workers) may still create an invoice for one order at once: lock the order in your
database around `createInvoice` (should two calls run at once anyway, the one that loses never
ends the other's lease). In a store whose reads may lag behind its writes (an eventually
consistent one), read the lock and what it protects with consistent reads (SPEC.md §17).

## Errors

Every failure but a cancellation is a `SattlemeException` with `getErrorCode()` and
`isRetryable()` (whether the same call may succeed later, unchanged). A message never holds a
secret.

| Code                   | Exception                      | Retryable    | When                                                                                     |
| ---------------------- | ------------------------------ | ------------ | ---------------------------------------------------------------------------------------- |
| `invalid_key`          | `InvalidKeyException`          | no           | The key is not an integration key.                                                       |
| `invalid_option`       | `InvalidOptionException`       | no           | An option of the client or of a call is invalid (`getParam()`).                          |
| `invalid_order`        | `InvalidOrderException`        | no           | A parameter of the order, or a stored invoice, is invalid (`getParam()`).                |
| `revoked`              | `RevokedException`             | no           | The merchant removed the integration: every call that reads its channel (a check of an invoice without an address goes on; one with an address paid by its Lightning invoice is found paid). |
| `channel_not_found`    | `ChannelNotFoundException`     | unless newer | The relays answered without the integration's channel (`isNewerFormat()`: update the SDK). |
| `network`              | `NetworkException`             | yes          | A relay, the Lightning address, a rate source, the explorer or BlockCypher did not answer usably, or fewer than a majority of relays answered or took a record. |
| `invalid_invoice`      | `InvalidInvoiceException`      | no           | An invoice, a pay request or a payment answer failed the checks.                         |
| `invoice_pending`      | `InvoicePendingException`      | no           | An earlier invoice of the order may still be paid.                                       |
| `order_paid`           | `OrderPaidException`           | no           | An earlier invoice of the order was paid.                                                |
| `unsupported_currency` | `UnsupportedCurrencyException` | no           | No rate source has a price in the currency (`getCurrency()`).                            |
| `rate`                 | `RateException`                | yes          | No usable price.                                                                         |
| `checkout_unavailable` | `CheckoutUnavailableException` | no           | A checkout link was asked, and the merchant has no payment page.                         |
| `onchain_unavailable`  | `OnchainUnavailableException`  | yes          | The order can only be paid on-chain, and no address could be leased.                     |

The exceptions are in `Sattleme\Checkout\Exception`. Two more, outside the protocol:
`CancelledException` (a cancelled call) and `UnsupportedEnvironmentException` (PHP lacks what the
SDK needs, when a `Client` is made). An argument of the wrong PHP type (the stored JSON text where
`getStatus()` takes its decoded array) is PHP's `TypeError`, as the methods' signatures say: a
mistake in the code, which static analysis finds. `null` is taken as absent for the client's and
`waitForPayment`'s options (but `rateSource`) and for an order's `note`, `durationSeconds` and
`redirectUrl`; it is refused for `rateSource`, `sats`, `fiat` and `checkout`.

## Performance

Measured with [`tools/bench/create-invoice.php`](tools/bench/create-invoice.php) against the
tests' local relays and fakes (so without network latency), with PHP's official Docker images
(OpenSSL 3.5.7) on a cloud VM's Intel Xeon at 2.1 GHz; medians of 20 calls, of 200 for the
signatures:

| PHP              | BIP-340 signature | verification | `createInvoice` (Lightning) | `createInvoice` (with an address) |
| ---------------- | ----------------- | ------------ | --------------------------- | --------------------------------- |
| 8.2, with gmp    | 6.4 ms            | 4.8 ms       | 82 ms                       | 253 ms                            |
| 8.2, without gmp | 6.4 ms            | 4.7 ms       | 87 ms                       | 263 ms                            |
| 8.5, with gmp    | 4.4 ms            | 3.4 ms       | 71 ms                       | 234 ms                            |
| 8.5, without gmp | 4.4 ms            | 3.2 ms       | 72 ms                       | 234 ms                            |

`gmp` changes nothing (the SDK uses `bcmath` and OpenSSL). The CPU time of a `createInvoice` is
about 30 to 45 ms, and 60 to 95 ms with an address, the local fakes' included. On the network,
each relay and HTTP round trip adds its latency: not measured here, since the tests never reach
the network.

## Dependencies

At runtime, none: PHP and its standard extensions. What the protocol needs, and where it comes
from:

| Need                                  | From                                                                                              |
| ------------------------------------- | ------------------------------------------------------------------------------------------------- |
| SHA-256, HMAC, HKDF                   | `ext-hash` (HKDF written on `hash_hmac`, as RFC 5869).                                            |
| ChaCha20 (NIP-44 v2)                  | OpenSSL (`chacha20`), checked against NIP-44's official vectors.                                  |
| secp256k1: ECDH, BIP-340              | OpenSSL for the curve's operations; the BIP-340 steps written in the SDK (below).                 |
| Arbitrary-precision integers          | `ext-bcmath`.                                                                                     |
| HTTP client, WebSocket client         | The SDK's own, on PHP's streams: TLS always verified (but to a merchant's Bitcoin node on a local network, over `http`), no redirect, 1 MiB at most (and an HTTP head of 64 KiB, 10 MiB with a chunked body's size lines and trailers), bounded times. |
| Base64, bech32, JSON, UTF-8 rules     | The SDK's own, strict, as SPEC.md requires.                                                       |

**BIP-340 on OpenSSL.** PORTING.md asks for an audited secp256k1 library and never the curve
written by hand. PHP has none in its standard extensions: libsecp256k1's bindings are a C
extension a shared host cannot install, and the pure-PHP libraries rely on `gmp`, which hosts
often lack. By the maintainers' choice, the SDK uses OpenSSL's own secp256k1 for every scalar
multiplication (public keys, nonce points, ECDH) and point decompression, and writes only BIP-340's
steps on top: tagged hashes, the nonce, the signature's arithmetic modulo n, and the verification's
check that R = s·G − e·P, done as field equations (`bcmath`) on public points OpenSSL computed.
It multiplies no point itself. Every signature is verified before it is used. CI checks it against
libsecp256k1 on every run ([`tests/Differential`](tests/Differential), cases from
[`tools/differential`](tools/differential)): every signature byte for byte, every verification's
verdict, every shared secret and public key, on Ubuntu's OpenSSL and on that of AlmaLinux 8, 9
and 10.
It is **not constant-time**, nor is its ECDH ([Security](#security)): OpenSSL multiplies by a
secret with a ladder written against timing attacks, on arithmetic it does not claim constant-time,
and the SDK's own arithmetic on secrets (`bcmath`: the nonce, the signature) is not, so a
signature's time may depend on its secret. The secrets it signs with are per order or per link,
derived from the integration key; an attacker would have to time many signatures on your server
itself.

Development only (exact versions, locked in `composer.lock`, MIT or BSD-3-Clause, none shipped):

| Package                                   | Why                                    | Alternative set aside                              | Licence      |
| ----------------------------------------- | -------------------------------------- | -------------------------------------------------- | ------------ |
| `phpunit/phpunit` 11.5.57                 | Tests (11.5: the last to run on 8.2).  | Pest: a layer over PHPUnit, one more dependency.   | BSD-3-Clause |
| `phpstan/phpstan` 2.3.5                   | Static analysis, level max.            | Psalm: one analyser is enough.                     | MIT          |
| `phpstan/phpstan-strict-rules` 2.1.1      | Stricter checks.                       | —                                                  | MIT          |
| `phpstan/phpstan-phpunit` 2.1.2           | PHPStan understands PHPUnit's asserts. | —                                                  | MIT          |
| `friendsofphp/php-cs-fixer` 3.95.30       | Formatting, PER Coding Style.          | PHP_CodeSniffer: php-cs-fixer has `@PER-CS` built in. | MIT       |

CI only: [coincurve](https://github.com/ofek/coincurve) 21.0.0 (libsecp256k1's Python binding,
MIT or Apache-2.0, its wheel's hash pinned) for the differential test; Strauss 0.31.1 and
PHP-Scoper 0.18.20 (MIT, their PHARs' SHA-256 checked) for the prefixed copies.

## Known limits

- PHP looks a host's name up before each connection (`getaddrinfo`: PHP has no asynchronous
  resolver), and the lookup blocks the process: the call's other requests wait meanwhile, and
  neither `relayTimeoutMs`, `httpTimeoutMs` nor a `Cancellation` can stop it. The system's resolver
  bounds it (glibc: 5 s a try, 2 tries, by default; resolv.conf(5)). Once it returns, a request
  whose time ran out fails, and a call cancelled meanwhile fails with `CancelledException` at once,
  starting no other lookup. To keep lookups short: `options timeout:1 attempts:2` in
  `/etc/resolv.conf`, or a local caching resolver (systemd-resolved, nscd, dnsmasq, unbound), which
  answers the names of the merchant's relays and of the services from its cache. As the SDK
  connects without blocking, PHP tries the host's first address only (the next one only when the
  first fails at once, as on a server without an IPv6 route).
- In FIPS mode, OpenSSL may withhold secp256k1, which is not a FIPS-approved curve: the client then
  refuses to start (`UnsupportedEnvironmentException`), as its known-answer test of the curve fails.
  Not tested in FIPS mode.
- `waitForPayment` blocks its process: run it in a worker or a scheduled job, not in a web request
  (`max_execution_time`).
- Of what a relay sends, a read keeps only what it needs, as it comes (SPEC.md §13): each event's
  id and date, and the records that open once their signature checks out, which only the holders
  of their keys sign (a relay's copy of a record under an id of its own is dropped), as their
  NIP-01 members alone (a relay may add members of its own); of a lease, which opens without its
  signature, only its id, author, date, signature and end. Of one relay's events of one id in a
  query, the first is read, the others only counted. Anything else is dropped at once: a relay
  that sends many large events, or many copies of a record, makes nothing pile up, and no answer
  is cut at a size. A page of 1000 records takes about 2.5 MB. The
  WebSocket client offers no compression: a few compressed bytes would unpack into many large
  messages.
- The leases of the lot are read 100 a page, 20 pages at most (SPEC.md §13): past about 1 980
  records at an address's lease in the last two hours (anybody may write there), that address's
  read is not complete, and it is not leased while they last.
- Telling that a relay sent all the leases it holds assumes that every relay of the merchant's
  list sends 100 events a page at least when it holds as many (strfry sends 500; nostr-rs-relay
  and khatru, as many as they are asked for).
- Most reads go on as soon as a majority of the relays answered: a slow or mute relay slows
  nothing. Three wait for every relay, up to `relayTimeoutMs` after a majority answered (SPEC.md
  §13): a lease's confirmation, the witnesses read again after one is published, and the choice
  among several invoices of an order that may still be paid. A relay that failed earlier in the
  call is not waited for again.

## Versions

The SDK follows semantic versioning, its own, from 1.0.0 on, its first public and stable version:
no change that breaks your code before 2.0.0. Each version says which version of checkout-spec it
implements (`Client::SPEC_VERSION`); see [CHANGELOG.md](CHANGELOG.md).

## Security

Report a vulnerability privately, never in a public issue: see [SECURITY.md](SECURITY.md).

**Signing and ECDH are not constant-time.** The SDK's secp256k1 is OpenSSL's (`ext-openssl`; see
[Dependencies](#dependencies)): it multiplies every point by a secret, for a public key (the secret
× G, computed as OpenSSL reads the key: `ossl_ec_key_simple_generate_public_key`,
`crypto/ec/ec_key.c`), a signature's nonce point, and the ECDH of NIP-44 (`openssl_pkey_derive`:
`ossl_ecdh_simple_compute_key`, `crypto/ec/ecdh_ossl.c`). OpenSSL multiplies by a secret with a
Montgomery ladder whose points are swapped in constant time (`ossl_ec_scalar_mul_ladder`,
`crypto/ec/ec_mult.c`, "with various timing attack defenses"); but secp256k1 has no implementation
of its own in OpenSSL, it runs on OpenSSL's generic prime-field arithmetic (`EC_GFp_mont_method`),
and the ladder's source says that it guarantees nothing of the constant-timeness of that
arithmetic. Around it, the SDK's own BIP-340 arithmetic on secrets (the nonce and the signature,
modulo n, on `bcmath`) takes a time that depends on them, as does its handling of a secret key on
its way to OpenSSL (its range check on `bcmath`, its PEM encoding). So the time of a signature or
of an ECDH may depend on the secret it uses: each order's sale secret (its ECDH with the merchant's
reading key too), each invoice's rest secret, the integration's seen secret, which signs its
witnesses, a checkout link's author secret, all derived from the integration key but the last. A
process that shares the server's CPU (another tenant of a shared host or of the same physical
machine) could in principle time many of them and learn about those keys. Run the SDK where no code
you do not trust shares the CPU, as you would for any server that holds a key.

## License

MIT, see [LICENSE](LICENSE).
