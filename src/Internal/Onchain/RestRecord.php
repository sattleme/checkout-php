<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Text;
use Sattleme\Checkout\Internal\Url;

/**
 * A rest record (SPEC.md §31, "Rest records"): shows an invoice of the rest to the pages of the
 * order's checkout links, which cannot read the registry. A sealed record of type checkout-rest by
 * the rest author, under the rest encryption key, at the rest record address of its payment hash,
 * with the expiration of its order's checkout links.
 *
 * @internal
 */
final class RestRecord
{
    private function __construct() {}

    /** Its event, signed by the rest secret; $expiresAt is the order's invoice's. */
    public static function event(RestKeys $keys, string $invoice, string $verifyUrl, string $paymentHash, int $expiresAt, int $createdAt, Context $context): Event
    {
        return SealedRecord::seal(
            Protocol::REST_TYPE,
            ['version' => Protocol::REST_VERSION, 'invoice' => $invoice, 'verifyUrl' => $verifyUrl],
            $keys->secret->reveal(),
            $keys->encryptionKey->reveal(),
            $keys->recordAddress($paymentHash),
            $createdAt,
            $context->random(...),
            [['expiration', (string) ($expiresAt + Protocol::CHECKOUT_LIFE)]],
        );
    }

    /**
     * What a relay sent, as the rest record of the invoice $bolt11 (payment hash $paymentHash):
     * its event, or null when it is not.
     */
    public static function of(mixed $value, RestKeys $keys, string $bolt11, string $paymentHash): ?Event
    {
        $record = SealedRecord::open($value, Protocol::REST_TYPE, $keys->recordAddress($paymentHash), $keys->author, $keys->encryptionKey->reveal());
        if ($record === null || $record->status !== SealedRecord::OK) {
            return null;
        }
        $invoice = Json::get($record->value, 'invoice');
        $ours = Json::safeInteger(Json::get($record->value, 'version')) === Protocol::REST_VERSION
            && \is_string($invoice) && Text::asciiLower($invoice) === Text::asciiLower($bolt11);

        return $ours ? $record->event : null;
    }

    /**
     * The rest record address of what a relay sent, when it is a rest record of a newer format by
     * the rest author, its event checked out (SPEC.md §12): a newer version recorded the invoice of
     * that address, and it is never written over. Null for anything else.
     */
    public static function newerAt(mixed $value, RestKeys $keys): ?string
    {
        $opened = self::atItsAddress($value, $keys);

        return $opened !== null && self::isNewer($opened[1]) ? $opened[0] : null;
    }

    /**
     * A rest record as a checkout link's page reads it (SPEC.md §31, "Reading a rest record"),
     * for a link of $sats, $createdAt and $expiresAt: ['ok', invoice] (its payment hash, verify
     * URL and sats), ['newer'], or null when it is ignored.
     *
     * @param numeric-string $sats
     *
     * @return array{0: 'ok', 1: array{invoice: string, paymentHash: string, verifyUrl: string, sats: string}}|array{0: 'newer'}|null
     */
    public static function read(mixed $value, RestKeys $keys, string $sats, int $createdAt, int $expiresAt): ?array
    {
        $opened = self::atItsAddress($value, $keys);
        if ($opened === null) {
            return null;
        }
        [$d, $record] = $opened;
        if (self::isNewer($record)) {
            return ['newer'];
        }
        $rest = $record->value;
        $version = Json::get($rest, 'version');
        $invoice = Json::get($rest, 'invoice');
        $verifyUrl = Json::get($rest, 'verifyUrl');
        if (!$rest instanceof \stdClass || Json::safeInteger($version) !== Protocol::REST_VERSION || !Url::isHttps($verifyUrl)) {
            return null;
        }
        \assert(\is_string($verifyUrl));
        $decoded = Bolt11::decode($invoice);
        if ($decoded === null || $decoded->network !== 'bc' || $decoded->amountMsat === null || $decoded->amountMsat === '0'
            || !str_ends_with($decoded->amountMsat, '000')) {
            return null;
        }
        \assert(\is_string($invoice));
        $restSats = substr($decoded->amountMsat, 0, -3);
        \assert(is_numeric($restSats));
        if (BigInt::cmp($restSats, $sats) >= 0 || $decoded->timestamp < $createdAt - Protocol::CLOCK_TOLERANCE
            || $decoded->expiresAt() > $expiresAt + Protocol::CLOCK_TOLERANCE || $d !== $keys->recordAddress($decoded->paymentHash)) {
            return null;
        }

        return ['ok', ['invoice' => $invoice, 'paymentHash' => $decoded->paymentHash, 'verifyUrl' => $verifyUrl, 'sats' => $restSats]];
    }

    /**
     * What a relay sent, opened as a rest record of the rest author at its own `d` tag (SPEC.md
     * §12): that tag and the record, or null. The link's acceptance record, by the same author,
     * which the read of rest records also returns, is no rest record, of a newer format or not.
     *
     * @return array{0: string, 1: SealedRecord}|null
     */
    private static function atItsAddress(mixed $value, RestKeys $keys): ?array
    {
        $d = SealedRecord::dTag($value);
        if ($d === null || $d === $keys->acceptanceAddress()) {
            return null;
        }
        $record = SealedRecord::open($value, Protocol::REST_TYPE, $d, $keys->author, $keys->encryptionKey->reveal());

        return $record === null ? null : [$d, $record];
    }

    /** Whether a rest record is of a newer format: its content, or a `version` above the one known. */
    private static function isNewer(SealedRecord $record): bool
    {
        if ($record->status === SealedRecord::NEWER) {
            return true;
        }
        $version = Json::get($record->value, 'version');

        return (\is_int($version) || \is_float($version)) && $version > Protocol::REST_VERSION;
    }
}
