<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;

/**
 * The merchant's acceptance of an invoice (SPEC.md §31, "The merchant's acceptance"), as a check
 * counts it: the merchant may accept by hand, in its app, what an order underpaid on-chain received
 * as paying it in full. The app publishes a record of the team (the integration key opens it but
 * cannot make one) holding what the order had received then; a check judges the payment against
 * that amount when it is less than the least the order's tolerance accepts (Tolerance::due()).
 * The majority of the merchant's relays holds the truth: a check reads it on every relay and counts
 * a record only when a majority of the relays sent it. A check never publishes one, nor publishes
 * one again: the pages of the order's checkout links learn of it only through an acceptance record
 * (Acceptance).
 *
 * @internal
 */
final class MerchantAcceptance
{
    /** Valid: what the order had received when the merchant accepted it. */
    public const OK = 'ok';
    /** Of a newer format: logged; the newest a majority sent, it leaves the order without one. */
    public const NEWER = 'newer';
    /** Of a newer format, dated after the lease's end: set aside as any record dated so, logged. */
    public const NEWER_SET_ASIDE = 'newer, set aside';

    /**
     * @param numeric-string|null $received the `received` of the merchant's acceptance that counts; null without one
     * @param bool                $newer    whether a relay sent one of a newer format, whatever its date and however many relays sent it
     */
    public function __construct(
        public readonly ?string $received,
        public readonly bool $newer,
    ) {}

    /** Without one: none read (no transaction pays the address), or none counts. */
    public static function none(): self
    {
        return new self(null, false);
    }

    /**
     * What a relay sent, read as the merchant's acceptance of an invoice of $sats whose end is
     * $expiresAt (SPEC.md §31, "Reading it"): a record of type `integration-onchain-accepted`,
     * author the team, sealed with the channel's key, at the address of $keys, that checks out (its
     * id, its signature); valid when its value is an object whose `version` is 1 and whose
     * `received` is a safe integer from 1, below $sats (more fields ignored); of a newer format when
     * its `version` is above 1 or its content of a newer NIP-44 version. Null for anything else,
     * and for a record dated after the lease's end (expiresAt + 120), set aside whatever its format:
     * one of a newer format is still told, for the warning (NEWER_SET_ASIDE). Its `created_at` is
     * never compared with now.
     *
     * @param numeric-string $sats
     *
     * @return array{0: self::OK, 1: Event, 2: numeric-string}|array{0: self::NEWER|self::NEWER_SET_ASIDE, 1: Event}|null
     */
    public static function open(mixed $value, MerchantAcceptanceKeys $keys, string $sats, int $expiresAt): ?array
    {
        $record = SealedRecord::open($value, Protocol::MERCHANT_ACCEPTANCE_TYPE, $keys->address, $keys->team, $keys->encryptionKey->reveal());
        if ($record === null) {
            return null;
        }
        // After the lease's end: set aside, of a newer format or not (SPEC.md §31, "Reading it").
        $late = $record->event->createdAt > $expiresAt + Protocol::GRACE_SECONDS;
        $accepted = $record->value;
        $version = Json::get($accepted, 'version');
        if ($record->status === SealedRecord::NEWER || ((\is_int($version) || \is_float($version)) && $version > Protocol::MERCHANT_ACCEPTANCE_VERSION)) {
            return [$late ? self::NEWER_SET_ASIDE : self::NEWER, $record->event];
        }
        $received = Json::intIn(Json::get($accepted, 'received'), 1, Protocol::MAX_SAFE_INTEGER);
        if (!$accepted instanceof \stdClass || Json::safeInteger($version) !== Protocol::MERCHANT_ACCEPTANCE_VERSION || $received === null
            || BigInt::cmp((string) $received, $sats) >= 0 || $late) {
            return null;
        }

        return [self::OK, $record->event, (string) $received];
    }

    /**
     * The merchant's acceptance of an invoice of $sats whose end is $expiresAt (SPEC.md §31,
     * "Counting it"), from what each relay of the merchant's list ($relays relays) sent to its read
     * on every relay ($answers, by relay): every copy opened (open()), once per id and signature; a
     * relay sent a record when it sent a copy of it that opens, and counts once for it, whatever
     * copies of it it sent. Of the records valid or of a newer format that a majority of the list
     * sent, the newest (the highest `created_at`, then the lowest id, as relays keep them) decides:
     * a valid one is the merchant's acceptance, one of a newer format leaves the order without one.
     * One that fewer than a majority sent never counts, whichever relays sent it.
     *
     * @param array<string, list<mixed>> $answers by relay
     * @param numeric-string             $sats
     */
    public static function of(array $answers, MerchantAcceptanceKeys $keys, string $sats, int $expiresAt, int $relays): self
    {
        /** @var array<string, array{0: self::OK, 1: Event, 2: numeric-string}|array{0: self::NEWER|self::NEWER_SET_ASIDE, 1: Event}|null> $copies each copy opened once, by id and signature */
        $copies = [];
        /** @var array<string, array{0: array{0: self::OK, 1: Event, 2: numeric-string}|array{0: self::NEWER, 1: Event}, 1: int}> $records by id, and how many relays sent each */
        $records = [];
        $newer = false;
        foreach ($answers as $events) {
            $sent = [];
            foreach ($events as $value) {
                $id = Json::get($value, 'id');
                $sig = Json::get($value, 'sig');
                if (!\is_string($id) || !\is_string($sig)) {
                    continue; // No event: none opens.
                }
                $copy = $id . '/' . $sig;
                if (!\array_key_exists($copy, $copies)) {
                    $copies[$copy] = self::open($value, $keys, $sats, $expiresAt);
                }
                $opened = $copies[$copy];
                if ($opened === null) {
                    continue;
                }
                if ($opened[0] !== self::OK) {
                    $newer = true;
                }
                if ($opened[0] === self::NEWER_SET_ASIDE) {
                    continue;
                }
                // One relay counts once for a record, whatever copies of it it sent.
                $id = $opened[1]->id;
                if (isset($sent[$id])) {
                    continue;
                }
                $sent[$id] = true;
                if (isset($records[$id])) {
                    ++$records[$id][1];
                } else {
                    $records[$id] = [$opened, 1];
                }
            }
        }
        $majority = RelayPool::majority($relays);
        $newest = null;
        foreach ($records as [$opened, $sentBy]) {
            if ($sentBy >= $majority && ($newest === null || $opened[1]->replaces($newest[1]))) {
                $newest = $opened;
            }
        }

        return new self($newest !== null && $newest[0] === self::OK ? $newest[2] : null, $newer);
    }

    /**
     * The merchant's acceptance of an invoice of $sats whose end is $expiresAt (of()), read on
     * every relay of the merchant's $relays (SPEC.md §13: once every relay answered or failed, or
     * one relayTimeoutMs after a majority answered), not paged, with a key of this read alone.
     * Publishes nothing.
     *
     * @param list<string>   $relays the merchant's
     * @param numeric-string $sats
     *
     * @throws NetworkException when fewer than a majority answered
     */
    public static function read(RelayPool $pool, Context $context, MerchantAcceptanceKeys $keys, array $relays, string $sats, int $expiresAt): self
    {
        $answers = $pool->read($relays, ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$keys->team], '#d' => [$keys->address]], false, $context->randomSecret(...), $keys->opens(...), true);

        return self::of($answers, $keys, $sats, $expiresAt, \count($relays));
    }
}
