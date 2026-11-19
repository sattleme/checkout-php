<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Crypto\Nip44Exception;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;

/**
 * An invoice's declaration in the merchant's registry (SPEC.md §20): so that its payment counts
 * for this order's sale and for no other.
 *
 * @internal
 */
final class Declaration
{
    /**
     * @param array{minor: int, digits: int, currency: string} $fiat
     * @param array{address: string, expiresAt: int}|null       $rest the invoice whose rest it is
     */
    private function __construct(
        public readonly Event $event,
        public readonly string $invoice,
        public readonly string $verifyUrl,
        public readonly Bolt11 $decoded,
        public readonly array $fiat,
        public readonly string $note,
        public readonly ?array $rest,
    ) {}

    /**
     * The declaration's JSON (inside), in this order.
     *
     * @param array{minor: int, digits: int, currency: string} $fiat
     * @param array{address: string, expiresAt: int}|null       $rest
     *
     * @return array<string, mixed>
     */
    public static function inside(string $invoice, string $verifyUrl, #[\SensitiveParameter] string $saleKeyHex, array $fiat, string $note, string $takerTag, ?array $rest): array
    {
        $inside = [
            'version' => Protocol::DECLARATION_VERSION,
            'invoice' => $invoice,
            'verifyUrl' => $verifyUrl,
            'sale' => ['key' => $saleKeyHex, 'fiat' => $fiat, 'note' => $note],
            'taker' => $takerTag,
        ];
        if ($rest !== null) {
            $inside['rest'] = $rest;
        }

        return $inside;
    }

    /**
     * The declaration's event: its JSON sealed with the inner key, inside a sealed record of
     * the registry with the outer key, signed by the sale secret.
     *
     * @param array<string, mixed> $inside
     *
     * @throws \LengthException when it does not fit a NIP-44 plaintext
     */
    public static function event(Sale $sale, Registry $registry, string $paymentHash, #[\SensitiveParameter] array $inside, int $createdAt, Context $context): Event
    {
        $text = Json::encode($inside);
        if (\strlen($text) > Nip44::MAX_PLAINTEXT) {
            throw new \LengthException('A declaration too large for NIP-44');
        }
        $sealed = Nip44::encrypt($text, $sale->innerKey($registry), $context->random(32));
        $address = Derive::registryAddress($registry->key->reveal(), $paymentHash);

        return SealedRecord::seal(
            Protocol::DECLARATION_TYPE,
            self::value($sealed),
            $sale->secret->reveal(),
            Derive::outerKey($registry->key->reveal(), $address, $sale->author),
            $address,
            $createdAt,
            $context->random(...),
        );
    }

    /**
     * Whether the declaration of $inside fits NIP-44 plaintexts (event() would make it): $inside,
     * then the record that seals it again. By its size alone: no random bytes are drawn, so that
     * it may be checked before anything is published (SPEC.md §17, step 6).
     *
     * @param array<string, mixed> $inside
     */
    public static function fits(#[\SensitiveParameter] array $inside): bool
    {
        $length = \strlen(Json::encode($inside));

        return $length <= Nip44::MAX_PLAINTEXT && SealedRecord::fits(Protocol::DECLARATION_TYPE, self::value(str_repeat('A', Nip44::payloadLength($length))));
    }

    /**
     * What a relay sent, as a declaration of $sale (SPEC.md §20, "Opening"): ['ok', declaration],
     * ['newer', event], or null when it is none.
     *
     * @return array{0: 'ok', 1: self}|array{0: 'newer', 1: Event}|null
     */
    public static function open(mixed $value, Sale $sale, Registry $registry): ?array
    {
        $address = SealedRecord::dTag($value);
        if ($address === null) {
            return null;
        }
        $record = SealedRecord::open($value, Protocol::DECLARATION_TYPE, $address, $sale->author, Derive::outerKey($registry->key->reveal(), $address, $sale->author));
        if ($record === null) {
            return null;
        }
        if ($record->status === SealedRecord::NEWER) {
            return ['newer', $record->event];
        }
        $sealed = Json::get($record->value, 'sealed');
        if (!\is_string($sealed)) {
            return null;
        }
        try {
            $inside = Json::decode(Nip44::decrypt($sealed, $sale->innerKey($registry)));
        } catch (Nip44Exception $e) {
            return $e->newerFormat ? ['newer', $record->event] : null;
        } catch (\JsonException) {
            return null;
        }
        if (!$inside instanceof \stdClass) {
            return null;
        }
        $version = Json::get($inside, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::DECLARATION_VERSION) {
            return ['newer', $record->event];
        }
        $invoice = Json::get($inside, 'invoice');
        $verifyUrl = Json::get($inside, 'verifyUrl');
        $terms = self::saleTerms(Json::get($inside, 'sale'), $sale);
        if (Json::safeInteger($version) !== Protocol::DECLARATION_VERSION || !\is_string($invoice) || !Url::isHttps($verifyUrl) || $terms === null) {
            return null;
        }
        \assert(\is_string($verifyUrl));
        $decoded = Bolt11::decode($invoice);
        if ($decoded === null || $decoded->network !== 'bc' || $decoded->amountMsat === null || $decoded->amountMsat === '0' || !str_ends_with($decoded->amountMsat, '000')) {
            return null;
        }
        $rest = null;
        if (Json::has($inside, 'rest')) {
            $restValue = Json::get($inside, 'rest');
            $restAddress = Json::get($restValue, 'address');
            $restEnd = Json::intIn(Json::get($restValue, 'expiresAt'), 0, Protocol::MAX_SAFE_INTEGER);
            if (!$restValue instanceof \stdClass || !DepositAddress::isValid($restAddress) || $restEnd === null) {
                return null;
            }
            \assert(\is_string($restAddress));
            $rest = ['address' => $restAddress, 'expiresAt' => $restEnd];
        }
        if ($address !== Derive::registryAddress($registry->key->reveal(), $decoded->paymentHash)) {
            return null;
        }

        return ['ok', new self($record->event, $invoice, $verifyUrl, $decoded, $terms['fiat'], $terms['note'], $rest)];
    }

    /**
     * A declaration's (or a lease's) `sale`: an object whose `key` is the sale key (hex), whose
     * `note` is a string, and whose `fiat` has `minor` a safe integer from 0, `digits` from 0 to
     * 8 and `currency` three letters A–Z; else null.
     *
     * @return array{fiat: array{minor: int, digits: int, currency: string}, note: string}|null
     */
    public static function saleTerms(mixed $value, Sale $sale): ?array
    {
        $note = Json::get($value, 'note');
        $fiat = Json::get($value, 'fiat');
        if (!$value instanceof \stdClass || Json::get($value, 'key') !== $sale->keyHex() || !\is_string($note)) {
            return null;
        }
        $minor = Json::intIn(Json::get($fiat, 'minor'), 0, Protocol::MAX_SAFE_INTEGER);
        $digits = Json::intIn(Json::get($fiat, 'digits'), 0, 8);
        $currency = Json::get($fiat, 'currency');
        if (!$fiat instanceof \stdClass || $minor === null || $digits === null || !\is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            return null;
        }

        return ['fiat' => ['minor' => $minor, 'digits' => $digits, 'currency' => $currency], 'note' => $note];
    }

    /**
     * The invoice's amount in sats.
     *
     * @return numeric-string
     */
    public function sats(): string
    {
        $sats = substr((string) $this->decoded->amountMsat, 0, -3);
        \assert(is_numeric($sats));

        return $sats;
    }

    /**
     * The record's value of an inside sealed as $sealed.
     *
     * @return array{sealed: string}
     */
    private static function value(string $sealed): array
    {
        return ['sealed' => $sealed];
    }
}
