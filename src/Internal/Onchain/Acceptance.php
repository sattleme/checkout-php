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
use Sattleme\Checkout\PaymentStatus;

/**
 * An acceptance record (SPEC.md §31, "Acceptance records"): tells the pages of an order's checkout
 * links, which can neither know the merchant's tolerance nor read the merchant's acceptance
 * (MerchantAcceptance), that its payment counts, by what was received when the server accepted
 * it, never the merchant's tolerance.
 *
 * @internal
 */
final class Acceptance
{
    private function __construct() {}

    /** Its event, signed by the rest secret; $expiresAt is the order's invoice's. */
    public static function event(RestKeys $keys, int $received, int $expiresAt, int $createdAt, Context $context): Event
    {
        return SealedRecord::seal(
            Protocol::ACCEPTANCE_TYPE,
            ['version' => Protocol::ACCEPTANCE_VERSION, 'received' => $received],
            $keys->secret->reveal(),
            $keys->encryptionKey->reveal(),
            $keys->acceptanceAddress(),
            $createdAt,
            $context->random(...),
            [['expiration', (string) ($expiresAt + Protocol::CHECKOUT_LIFE)]],
        );
    }

    /**
     * An acceptance record as read (SPEC.md §31, "Reading an acceptance record"), for an invoice
     * of $sats: ['ok', received], ['newer', event], or null when it is ignored.
     *
     * @param numeric-string $sats
     *
     * @return array{0: 'ok', 1: string, 2: Event}|array{0: 'newer', 1: Event}|null
     */
    public static function read(mixed $value, RestKeys $keys, string $sats): ?array
    {
        $record = SealedRecord::open($value, Protocol::ACCEPTANCE_TYPE, $keys->acceptanceAddress(), $keys->author, $keys->encryptionKey->reveal());
        if ($record === null) {
            return null;
        }
        if ($record->status === SealedRecord::NEWER) {
            return ['newer', $record->event];
        }
        $accepted = $record->value;
        $version = Json::get($accepted, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::ACCEPTANCE_VERSION) {
            return ['newer', $record->event];
        }
        $received = Json::intIn(Json::get($accepted, 'received'), 1, Protocol::MAX_SAFE_INTEGER);
        if (!$accepted instanceof \stdClass || Json::safeInteger($version) !== Protocol::ACCEPTANCE_VERSION || $received === null
            || BigInt::cmp((string) $received, $sats) >= 0) {
            return null;
        }

        return ['ok', (string) $received, $record->event];
    }

    /**
     * Publishes the acceptance record of an invoice of $sats ending at $expiresAt on $relays when
     * $status, the status a check gave of it at $now, calls for one (SPEC.md §31, "Publishing
     * it", isCalledFor()): reads its records there, with a key of that read alone, and publishes
     * one of the status's `received` unless the relays hold one (isHeld(): a majority of them, a
     * record of at most as much, or one of a newer format, which is logged and never written
     * over). The `received` it published, or null. A failure (fewer than a majority answered the
     * read, or took the record) is logged, never thrown: the next check reads and publishes again.
     *
     * @param list<string>         $relays
     * @param \Closure(): RestKeys $keys   the invoice's rest keys: derived only when a record is called for
     * @param numeric-string       $sats
     */
    public static function publish(RelayPool $pool, Context $context, array $relays, \Closure $keys, PaymentStatus $status, string $sats, int $expiresAt, int $now): ?string
    {
        if (!self::isCalledFor($status, $sats, $expiresAt, $now)) {
            return null;
        }
        $keys = $keys();
        try {
            $answers = $pool->read($relays, ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$keys->author], '#d' => [$keys->acceptanceAddress()]], false, $context->randomSecret(...), $keys->opensAcceptance(...));
        } catch (NetworkException) {
            $context->warn('The acceptance records of an invoice could not be read on a majority of the relays');

            return null;
        }
        $byRelay = self::byRelay($answers, $keys, $sats);
        if (\in_array('newer', $byRelay, true)) {
            // Never written over (SPEC.md §12): held, the SDK to be updated.
            $context->warn('An acceptance record of this order has a newer format than this version of sattleme/checkout: update it');
        }
        if (self::isHeld($byRelay, \count($relays), $status)) {
            return null;
        }
        $received = $status->received;
        \assert($received !== null);
        $event = self::event($keys, (int) $received, $expiresAt, $now, $context);
        if (!$pool->publish($relays, $event, static fn(): string => $keys->secret->reveal())) {
            $context->warn('The acceptance record of an invoice could not be published on a majority of the relays');

            return null;
        }

        return $received;
    }

    /**
     * Whether a check's status calls for an acceptance record (SPEC.md §31, "Publishing it"):
     * `unconfirmed`, or `paid` with txids, with `received` below `sats`, before the end of its
     * checkout links' life.
     *
     * @param numeric-string $sats
     */
    public static function isCalledFor(PaymentStatus $status, string $sats, int $expiresAt, int $now): bool
    {
        $txids = $status->txids ?? [];
        $called = $status->state === PaymentStatus::UNCONFIRMED || ($status->state === PaymentStatus::PAID && $txids !== []);

        return $called && $status->received !== null && BigInt::cmp($status->received, $sats) < 0 && $now < $expiresAt + Protocol::CHECKOUT_LIFE;
    }

    /**
     * Whether the relays hold it already: a majority of them each sent a valid one (their newest)
     * of at most the status's `received`, or one sent a record of a newer format.
     *
     * @param list<string|'newer'|null> $byRelay of each relay that answered: the `received` of its newest valid one, 'newer', or null
     */
    public static function isHeld(array $byRelay, int $relays, PaymentStatus $status): bool
    {
        $received = $status->received ?? '0';
        $held = 0;
        foreach ($byRelay as $answer) {
            if ($answer === 'newer') {
                return true;
            }
            if ($answer !== null && is_numeric($answer) && BigInt::cmp($answer, $received) <= 0) {
                ++$held;
            }
        }

        return $held >= RelayPool::majority($relays);
    }

    /**
     * What each relay holds of the invoice's acceptance records: of each relay that answered, the
     * `received` of its newest valid one, 'newer' when it sent one of a newer format, or null.
     *
     * @param array<string, list<mixed>> $answers by relay
     * @param numeric-string             $sats
     *
     * @return list<string|'newer'|null>
     */
    public static function byRelay(array $answers, RestKeys $keys, string $sats): array
    {
        $result = [];
        foreach ($answers as $events) {
            $newest = null;
            $newer = false;
            foreach ($events as $value) {
                $opened = self::read($value, $keys, $sats);
                if ($opened === null) {
                    continue;
                }
                if ($opened[0] === 'newer') {
                    $newer = true;
                    continue;
                }
                if ($newest === null || $opened[2]->replaces($newest[2])) {
                    $newest = $opened;
                }
            }
            $result[] = $newer ? 'newer' : ($newest === null ? null : $newest[1]);
        }

        return $result;
    }
}
