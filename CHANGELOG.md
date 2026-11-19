# Changelog

Every version of the SDK, and the version of
[checkout-spec](https://github.com/sattleme/checkout-spec) it implements. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - unreleased

The first public version, stable. Follows version 1.0.0 of the specification
([checkout-spec](https://github.com/sattleme/checkout-spec), protocol 1), its first public
version, pinned in `spec/`.

### Added

- `Client`, from an integration key: `merchant()`, `createInvoice()`, `getStatus()`,
  `waitForPayment()` and `findInvoices()`, each with an optional `Cancellation`
  (`TimeoutCancellation`).
- Lightning invoices from the merchant's Lightning address, checked before they are returned, and
  declared in the merchant's registry.
- Prices in a currency: CoinGecko, mempool.space, Coinbase, Kraken and OpenNode, or a custom
  `RateSource`. `Client::currencyDigits()` gives the decimals of each currency of the table, and
  null for any other code.
- Every HTTP request and WebSocket handshake carries `User-Agent: sattleme-Checkout/1`
  (CoinGecko refuses a request without one, and relays behind Cloudflare's bot protection, as
  nostr.wine, a handshake). The URLs others choose (a Lightning address's pay URL, callback and
  verify URL) are https URLs on a public name: no IP address, no `localhost` nor a name under it;
  no redirect is followed, a WebSocket handshake's included.
- On-chain payments: a deposit address leased from the integration's lot (the lease published,
  confirmed by a complete read on every relay, then marked confirmed), its transactions recorded
  (witnesses, which tell which invoice a transaction pays), replacements (RBF), the merchant's
  tolerance, and the rest of an underpayment payable by Lightning.
- One explorer: mempool.space, or the merchant's Bitcoin node when its app names one in the
  channel (`bitcoinNode`), with its fallback (`mempool`: mempool.space when the node does not
  answer, the node asked after it for 60 s once it failed; `none`: never mempool.space, no address
  leased and `network` while it is down); paid at 3 confirmations on the explorer read. Its URL
  follows its own rule: `http` (and `ws`) to a local network through a `Net\PlainConnector`, an
  IPv4 address. `waitForPayment` watches its WebSocket. With `payers`, checkout links' records name
  it, and their page reads it too.
- The merchant's acceptance of an order underpaid on-chain at what it received, made by the
  merchant in its app: read by every check on every relay of the merchant, and counted only when a
  majority of them hold it; the order is then paid with `received` below its amount.
- `waitForPayment` logs a failure that each check meets again (an explorer or a Lightning address
  that does not answer) once, and again only after it stopped.
- Checkout links to the merchant's payment page, with a redirect URL.
- One order, one invoice at a time: `InvoicePendingException` and `OrderPaidException`; the
  payments of the invoices that may still be paid checked first, even of one alone (one already
  paid is never handed out again; one underpaid has its rest invoice asked), then the one shown
  handed out.
- What a relay sends is kept only as far as a read needs it, as it comes (an event's id and date;
  the records that open, their signatures checked as they come; of a lease, a few bytes): nothing
  piles up, whatever a relay sends, copies of records included, and no answer is cut at a size. A
  record of a newer format counts only once its signature checks out. The WebSocket client offers
  no compression and follows no redirect.
- Errors as `SattlemeException`, with a stable code and whether a call may be retried.
- No runtime dependency: PHP 8.2 or later (64-bit) with `bcmath`, `openssl`, `json`, `hash` and
  `pcre`; checked when a `Client` is made (`UnsupportedEnvironmentException`).

[1.0.0]: https://github.com/sattleme/checkout-php/releases/tag/v1.0.0
