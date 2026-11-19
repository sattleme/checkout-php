<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Secret;

/**
 * A witness (SPEC.md §31, "Witnesses"): the integration saw transaction `txid` paying `address`,
 * during the lease of an invoice of the sale `sale` (its author; `expiresAt` the invoice's), or at
 * no lease's time (`sale` null: none). A sealed record of the seen author, under the channel
 * encryption key, at the witness address of its address, transaction and sale, with two `o` tags:
 * the order tag of its address and sale, then the address tag of its address.
 *
 * @internal
 */
final class Witness
{
    /**
     * @param numeric-string $sats
     * @param list<string>   $inputs
     */
    private function __construct(
        public readonly Event $event,
        public readonly string $address,
        public readonly string $txid,
        public readonly ?string $sale,
        public readonly string $sats,
        public readonly array $inputs,
        public readonly bool $replacement,
        public readonly ?int $expiresAt,
    ) {}

    /** Whether it is of the invoice of the sale $sale whose end is $expiresAt (its `expiresAt`). */
    public function isOf(string $sale, int $expiresAt): bool
    {
        return $this->sale === $sale && $this->expiresAt === $expiresAt;
    }

    /**
     * A witness's value, in this order: `expiresAt` the invoice's, null for none.
     *
     * @param list<string> $inputs its first 100
     *
     * @return array<string, mixed>
     */
    public static function value(string $address, string $txid, ?string $sale, int $sats, array $inputs, bool $replacement, ?int $expiresAt): array
    {
        return [
            'version' => Protocol::WITNESS_VERSION,
            'address' => $address,
            'txid' => $txid,
            'sale' => $sale,
            'sats' => $sats,
            'inputs' => \array_slice($inputs, 0, Protocol::MAX_WITNESS_INPUTS),
            'replacement' => $replacement,
            'expiresAt' => $sale === null ? null : $expiresAt,
        ];
    }

    /**
     * The witness's event, signed by the seen secret, `created_at` when it was seen, with its order
     * tag and its address tag. $expiresAt: of a sale, the invoice's whose check saw it.
     *
     * @param list<string> $inputs
     */
    public static function event(WitnessKeys $keys, string $address, string $txid, ?string $sale, int $sats, array $inputs, bool $replacement, ?int $expiresAt, int $seenAt, Context $context): Event
    {
        $seenAddressKey = $keys->seenAddressKey->reveal();

        return SealedRecord::seal(
            Protocol::WITNESS_TYPE,
            self::value($address, $txid, $sale, $sats, $inputs, $replacement, $expiresAt),
            $keys->seenSecret->reveal(),
            $keys->encryptionKey->reveal(),
            Derive::witnessAddress($seenAddressKey, $address, $txid, $sale),
            $seenAt,
            $context->random(...),
            [['o', Derive::orderTag($seenAddressKey, $address, $sale)], ['o', Derive::addressTag($seenAddressKey, $address)]],
        );
    }

    /**
     * What a relay sent, as a witness of the integration (SPEC.md §31, "Opening one"): ['ok',
     * witness], ['newer', event] (its `d` tag can be told, not what it names), or null.
     *
     * @return array{0: 'ok', 1: self}|array{0: 'newer', 1: Event}|null
     */
    public static function open(mixed $value, WitnessKeys $keys): ?array
    {
        $d = SealedRecord::dTag($value);
        if ($d === null) {
            return null;
        }
        $record = SealedRecord::open($value, Protocol::WITNESS_TYPE, $d, $keys->seenAuthor, $keys->encryptionKey->reveal());
        if ($record === null) {
            return null;
        }
        if ($record->status === SealedRecord::NEWER) {
            return ['newer', $record->event];
        }
        $witness = $record->value;
        if (!$witness instanceof \stdClass) {
            return null;
        }
        $version = Json::get($witness, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::WITNESS_VERSION) {
            return ['newer', $record->event];
        }
        $address = Json::get($witness, 'address');
        $txid = Json::get($witness, 'txid');
        $sale = Json::get($witness, 'sale');
        $sats = Json::intIn(Json::get($witness, 'sats'), 1, Protocol::MAX_SATS);
        $inputs = Json::get($witness, 'inputs');
        $replacement = Json::get($witness, 'replacement');
        // `sale` null is none; absent, it is no witness (SPEC.md §2: absent is not null).
        if (Json::safeInteger($version) !== Protocol::WITNESS_VERSION || !DepositAddress::isValid($address) || !self::isTxid($txid)
            || !Json::has($witness, 'sale') || ($sale !== null && !self::isTxid($sale)) || $sats === null || !self::areCoins($inputs)
            || !\is_bool($replacement) || ($sale === null && $replacement)) {
            return null;
        }
        // Null for none, a safe integer for a sale; a witness without it does not open.
        $expiresAt = Json::get($witness, 'expiresAt');
        if (!Json::has($witness, 'expiresAt') || ($sale === null ? $expiresAt !== null : Json::safeInteger($expiresAt) === null)) {
            return null;
        }
        \assert(\is_string($address) && \is_string($txid) && ($sale === null || \is_string($sale)) && \is_array($inputs));
        $seenAddressKey = $keys->seenAddressKey->reveal();
        if ($d !== Derive::witnessAddress($seenAddressKey, $address, $txid, $sale)
            || !$record->event->hasTag('o', Derive::orderTag($seenAddressKey, $address, $sale))
            || !$record->event->hasTag('o', Derive::addressTag($seenAddressKey, $address))) {
            return null;
        }

        /** @var list<string> $inputs */
        return ['ok', new self($record->event, $address, $txid, $sale, (string) $sats, $inputs, $replacement, Json::safeInteger($expiresAt))];
    }

    /**
     * The addresses of $byTag (address tag => address) that what a relay sent names (SPEC.md §31,
     * "Leasing an address", step 3): when it is an event of the seen author that checks out
     * (SPEC.md §11), each address whose address tag it holds as an `o` tag, whatever its content:
     * one that does not open as a witness, or of a newer format, names it all the same. The cheap
     * checks first: the signature only of an event that would name an address (Event::signs()
     * remembers it, for the other relays' copies).
     *
     * @param array<string, string> $byTag
     *
     * @return list<string>
     */
    public static function addressesNamed(mixed $value, string $seenAuthor, array $byTag): array
    {
        $tags = Json::get($value, 'tags');
        if (Json::get($value, 'pubkey') !== $seenAuthor || !\is_array($tags)) {
            return [];
        }
        $named = [];
        foreach ($tags as $tag) {
            $address = \is_array($tag) && ($tag[0] ?? null) === 'o' && \is_string($tag[1] ?? null) ? $byTag[$tag[1]] ?? null : null;
            if ($address !== null && !\in_array($address, $named, true)) {
                $named[] = $address;
            }
        }
        if ($named === []) {
            return [];
        }
        $event = Event::fromJson($value);
        if ($event === null) {
            return [];
        }
        Loop::get()->checkpoint();

        return $event->verify() ? $named : [];
    }

    /** 64 lowercase hex characters: a txid, or a sale author. */
    public static function isTxid(mixed $value): bool
    {
        return \is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /** A coin: `<txid>:<vout>`, the vout in decimal without leading zeros, from 0 to 4 294 967 295. */
    public static function isCoin(mixed $value): bool
    {
        if (!\is_string($value) || preg_match('/^[0-9a-f]{64}:(0|[1-9][0-9]{0,9})$/D', $value, $match) !== 1) {
            return false;
        }

        return (int) $match[1] <= 4294967295;
    }

    /** An array of at most 100 distinct coins. */
    private static function areCoins(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) > Protocol::MAX_WITNESS_INPUTS) {
            return false;
        }
        $seen = [];
        foreach ($value as $coin) {
            if (!self::isCoin($coin)) {
                return false;
            }
            \assert(\is_string($coin));
            if (isset($seen[$coin])) {
                return false;
            }
            $seen[$coin] = true;
        }

        return true;
    }
}
