<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Checkout;

use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Channel;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\BitcoinNode;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\RedirectUrl;
use Sattleme\Checkout\Internal\RelayList;
use Sattleme\Checkout\Internal\Text;
use Sattleme\Checkout\Internal\Url;

/**
 * Checkout links (SPEC.md §30): their URL, their fragment, and their record's value, as the SDK
 * writes it and as the merchant's web app reads it.
 *
 * @internal
 */
final class CheckoutLink
{
    private function __construct() {}

    /**
     * <origin>/pay#c.<author>.<key>.<relays>
     *
     * @param list<string> $relays
     */
    public static function url(string $origin, string $authorHex, #[\SensitiveParameter] string $linkKey, array $relays): string
    {
        return $origin . Protocol::CHECKOUT_PATH . '#' . Protocol::CHECKOUT_MARKER
            . '.' . Base64::encodeUrl((string) hex2bin($authorHex))
            . '.' . Base64::encodeUrl($linkKey)
            . '.' . RelayList::encode($relays);
    }

    /**
     * A link's record (SPEC.md §30, "The record"): sealed under the link encryption key at the
     * link address, signed by the author's secret, expiring a week after the invoice's end.
     *
     * @param array<string, mixed>   $value
     * @param \Closure(int): string $random the CSPRNG
     */
    public static function event(array $value, #[\SensitiveParameter] string $linkKey, #[\SensitiveParameter] string $authorSecret, int $createdAt, int $expiresAt, \Closure $random): Event
    {
        return SealedRecord::seal(
            Protocol::CHECKOUT_TYPE,
            $value,
            $authorSecret,
            Derive::linkEncryptionKey($linkKey),
            Derive::linkAddress($linkKey),
            $createdAt,
            $random,
            [['expiration', (string) ($expiresAt + Protocol::CHECKOUT_LIFE)]],
        );
    }

    /**
     * What a URL's fragment names (SPEC.md §30, "Reading the fragment"): a link ['link', author
     * hex, link key, relays], ['newer'], ['invalid'] or ['other'].
     *
     * @return array{0: 'link', 1: string, 2: string, 3: list<string>}|array{0: 'newer'}|array{0: 'invalid'}|array{0: 'other'}
     */
    public static function fragment(string $fragment): array
    {
        if (str_starts_with($fragment, '#')) {
            $fragment = substr($fragment, 1);
        }
        $parts = explode('.', $fragment);
        $first = $parts[0];
        if ($first !== Protocol::CHECKOUT_MARKER) {
            return preg_match('/^[a-z0-9]{1,8}$/D', $first) === 1 ? ['newer'] : ['other'];
        }
        if (\count($parts) !== 4) {
            return ['invalid'];
        }
        $author = Base64::decodeUrl($parts[1]);
        $key = Base64::decodeUrl($parts[2]);
        $relays = RelayList::decode($parts[3]);
        if ($author === null || $key === null || $relays === null || \strlen($author) !== 32 || \strlen($key) !== 32
            || !Secp256k1::isXOnlyPublicKey($author)) {
            return ['invalid'];
        }

        return ['link', bin2hex($author), $key, $relays];
    }

    /**
     * The value of a checkout record of an invoice without an address (version 1).
     *
     * @param array{minor: int, digits: int, currency: string}|null $fiat as the declaration has it; null for sats
     *
     * @return array<string, mixed>
     */
    public static function valueV1(string $businessName, string $invoice, string $verifyUrl, ?array $fiat, string $note, ?string $redirectUrl): array
    {
        return [
            'version' => Protocol::CHECKOUT_VERSION_LIGHTNING,
            'businessName' => $businessName,
            'invoice' => $invoice,
            'verifyUrl' => $verifyUrl,
            'fiat' => $fiat,
            'note' => $note,
            'redirectUrl' => $redirectUrl,
        ];
    }

    /**
     * The value of a checkout record of an invoice with an address (version 2): with the node the
     * page reads, BitcoinNode::forRecord() of the channel's (its `url` and `fallback` when its
     * `payers` is true), last.
     *
     * @param array{minor: int, digits: int, currency: string}|null $fiat
     * @param array{0: string, 1: string}|null                      $rest        the rest author and rest key (hex)
     * @param array{url: string, fallback: string}|null             $bitcoinNode the node the page reads (SPEC.md §30), null: absent
     *
     * @return array<string, mixed>
     */
    public static function valueV2(
        string $businessName,
        ?string $invoice,
        ?string $verifyUrl,
        string $address,
        int $sats,
        int $createdAt,
        int $expiresAt,
        ?array $fiat,
        string $note,
        ?string $redirectUrl,
        ?array $rest,
        ?array $bitcoinNode = null,
    ): array {
        $value = [
            'version' => Protocol::CHECKOUT_VERSION_ONCHAIN,
            'businessName' => $businessName,
            'invoice' => $invoice,
            'verifyUrl' => $verifyUrl,
            'onchainAddress' => $address,
            'sats' => $sats,
            'createdAt' => $createdAt,
            'expiresAt' => $expiresAt,
            'fiat' => $fiat,
            'note' => $note,
            'redirectUrl' => $redirectUrl,
        ];
        if ($rest !== null) {
            $value['restAuthor'] = $rest[0];
            $value['restKey'] = $rest[1];
        }
        // Absent, never null, without one: a page that does not know it ignores it.
        if ($bitcoinNode !== null) {
            $value['bitcoinNode'] = ['url' => $bitcoinNode['url'], 'fallback' => $bitcoinNode['fallback']];
        }

        return $value;
    }

    /**
     * A checkout record's value as the page reads it (SPEC.md §30, "Reading"): ['ok', what the
     * page shows], ['newer'], or null when it is invalid. `bitcoinNode`: the node the page reads
     * the chain from, of a record of version 2 whose `bitcoinNode` is valid
     * (BitcoinNode::ofRecord()); null for mempool.space (none, one set aside, which leaves the record valid, or a record of
     * version 1, which ignores it).
     *
     * @return array{0: 'ok', 1: array{sats: string, paymentHash: ?string, onchainAddress: ?string, expiresAt: int, redirect: ?string, rest: array{author: string, key: string}|null, bitcoinNode: array{url: string, fallback: string}|null}}|array{0: 'newer'}|null
     */
    public static function read(mixed $value): ?array
    {
        if (!$value instanceof \stdClass) {
            return null;
        }
        $version = Json::get($value, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::CHECKOUT_VERSION_ONCHAIN) {
            return ['newer'];
        }
        $version = Json::safeInteger($version);
        if (($version !== 1 && $version !== 2) || !Channel::isBusinessName(Json::get($value, 'businessName'))) {
            return null;
        }
        if (!self::isFiat($value) || !self::isNote(Json::get($value, 'note')) || !Json::has($value, 'redirectUrl')) {
            return null;
        }
        $redirectUrl = Json::get($value, 'redirectUrl');
        $redirect = null;
        if ($redirectUrl !== null) {
            $redirect = RedirectUrl::shown($redirectUrl);
            if ($redirect === null) {
                return null;
            }
        }
        $invoice = Json::get($value, 'invoice');
        $verifyUrl = Json::get($value, 'verifyUrl');
        if ($version === 1) {
            $decoded = self::invoiceOf($invoice, $verifyUrl);
            if ($decoded === null) {
                return null;
            }

            return ['ok', ['sats' => self::satsOf($decoded), 'paymentHash' => $decoded->paymentHash, 'onchainAddress' => null, 'expiresAt' => $decoded->expiresAt(), 'redirect' => $redirect, 'rest' => null, 'bitcoinNode' => null]];
        }
        $address = Json::get($value, 'onchainAddress');
        $sats = Json::intIn(Json::get($value, 'sats'), 1, Protocol::MAX_SATS);
        $createdAt = Json::safeInteger(Json::get($value, 'createdAt'));
        $expiresAt = Json::safeInteger(Json::get($value, 'expiresAt'));
        if (!DepositAddress::isValid($address) || $sats === null || $createdAt === null || $expiresAt === null || $createdAt >= $expiresAt) {
            return null;
        }
        \assert(\is_string($address));
        $paymentHash = null;
        if ($invoice !== null || $verifyUrl !== null) {
            $decoded = self::invoiceOf($invoice, $verifyUrl);
            if ($decoded === null || $decoded->amountMsat !== $sats . '000' || $decoded->timestamp !== $createdAt || $decoded->expiresAt() !== $expiresAt) {
                return null;
            }
            $paymentHash = $decoded->paymentHash;
        }
        $hasAuthor = Json::has($value, 'restAuthor');
        $hasKey = Json::has($value, 'restKey');
        $rest = null;
        if ($hasAuthor || $hasKey) {
            $restAuthor = Json::get($value, 'restAuthor');
            $restKey = Json::get($value, 'restKey');
            if (!$hasAuthor || !$hasKey || !Text::isHex32($restAuthor) || !Text::isHex32($restKey)) {
                return null;
            }
            \assert(\is_string($restAuthor) && \is_string($restKey));
            if (!Secp256k1::isXOnlyPublicKey((string) hex2bin($restAuthor))) {
                return null;
            }
            $rest = ['author' => $restAuthor, 'key' => $restKey];
        }

        return ['ok', ['sats' => (string) $sats, 'paymentHash' => $paymentHash, 'onchainAddress' => $address, 'expiresAt' => $expiresAt, 'redirect' => $redirect, 'rest' => $rest, 'bitcoinNode' => BitcoinNode::ofRecord(Json::get($value, 'bitcoinNode'))]];
    }

    /** A record's invoice and verify URL: decodes on bc, an amount above 0 of whole sats; an https verify URL. */
    private static function invoiceOf(mixed $invoice, mixed $verifyUrl): ?Bolt11
    {
        $decoded = Bolt11::decode($invoice);
        if ($decoded === null || $decoded->network !== 'bc' || $decoded->amountMsat === null || $decoded->amountMsat === '0'
            || !str_ends_with($decoded->amountMsat, '000') || !Url::isHttps($verifyUrl)) {
            return null;
        }

        return $decoded;
    }

    private static function satsOf(Bolt11 $invoice): string
    {
        return substr((string) $invoice->amountMsat, 0, -3);
    }

    /** fiat: null, or minor from 1 to 2^53 − 1, digits from 0 to 8, three letters other than SAT. */
    private static function isFiat(\stdClass $value): bool
    {
        if (!Json::has($value, 'fiat')) {
            return false;
        }
        $fiat = Json::get($value, 'fiat');
        if ($fiat === null) {
            return true;
        }
        $currency = Json::get($fiat, 'currency');

        return $fiat instanceof \stdClass
            && Json::intIn(Json::get($fiat, 'minor'), 1, Protocol::MAX_SAFE_INTEGER) !== null
            && Json::intIn(Json::get($fiat, 'digits'), 0, 8) !== null
            && \is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) === 1 && $currency !== Protocol::SATS_CURRENCY;
    }

    /** A note: 1 to 100 code points without control characters. */
    public static function isNote(mixed $note): bool
    {
        return \is_string($note) && $note !== '' && !Text::hasControl($note) && Text::codePoints($note) <= Protocol::MAX_NOTE;
    }
}
