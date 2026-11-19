<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Secret;

/**
 * The witnesses of an invoice with an address A, for its order's sale S and its end E (SPEC.md
 * §31, "Reading the witnesses of an invoice"): of each witness address the newest event that
 * opens; those of this invoice (of S, with E or without `expiresAt`) by txid, those of other
 * invoices (another sale, or S with another `expiresAt`) by txid, those of none by txid, and the
 * witness addresses of those of a newer format.
 *
 * @internal
 */
final class WitnessSet
{
    /**
     * @param array<string, array{sats: numeric-string, inputs: list<string>, replacement: bool, seenAt: int}> $ofSale of this invoice, by txid
     * @param array<string, array{sats: numeric-string, inputs: list<string>, replacement: bool, seenAt: int}> $others of other invoices, by txid
     * @param array<string, true>                                                                          $none   by txid
     * @param array<string, true>                                                                          $newer  their witness addresses
     * @param \Closure(string, ?string): string                                                            $witnessAddress of A, a txid and a sale (null: none)
     */
    public function __construct(
        public readonly array $ofSale,
        public readonly array $others,
        public readonly array $none,
        public readonly array $newer,
        private readonly \Closure $witnessAddress,
        private readonly string $sale,
    ) {}

    /**
     * Reads them on the merchant's relays by their three tags (the order tags of S and of none at A,
     * the address tag of A), not paged, with a key of this read alone; with $everyRelay, on every
     * relay (SPEC.md §13: the witnesses read again after one is published).
     *
     * @param list<string> $relays
     *
     * @throws \Sattleme\Checkout\Exception\NetworkException when fewer than a majority answered
     */
    public static function read(RelayPool $pool, Context $context, WitnessKeys $keys, array $relays, string $address, string $sale, int $expiresAt, bool $everyRelay = false): self
    {
        $answers = $pool->read($relays, ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$keys->seenAuthor], '#o' => self::tags($keys, $address, $sale)], false, $context->randomSecret(...), $keys->opens(...), $everyRelay);

        return self::of($answers, $keys, $address, $sale, $expiresAt);
    }

    /**
     * The witnesses of the invoice from what the relays sent to its read (read()): each relay's
     * events, as they came. Of each witness address, the newest event that opens; an event of no
     * invoice of this read (without its `o` tags, or a witness of another address), which only a
     * relay that does not keep to the filter sends, is left out.
     *
     * @param array<string, list<mixed>> $answers by relay
     */
    public static function of(array $answers, WitnessKeys $keys, string $address, string $sale, int $expiresAt): self
    {
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $tags = self::tags($keys, $address, $sale);
        $newest = [];
        $checked = [];
        foreach ($answers as $events) {
            foreach ($events as $value) {
                $id = Json::get($value, 'id');
                if (\is_string($id) && isset($checked[$id])) {
                    continue;
                }
                $opened = Witness::open($value, $keys);
                if ($opened === null) {
                    continue;
                }
                $event = $opened[1] instanceof Witness ? $opened[1]->event : $opened[1];
                $checked[$event->id] = true;
                // Of no invoice of this read (only a relay that does not keep to the filter sends
                // one): ignored, of a newer format or not, before the newest at each witness address.
                $ofThisRead = $event->hasTag('o', $tags[0]) || $event->hasTag('o', $tags[1]) || $event->hasTag('o', $tags[2]);
                if (!$ofThisRead || ($opened[1] instanceof Witness && $opened[1]->address !== $address)) {
                    continue;
                }
                $d = (string) $event->tag('d');
                $kept = $newest[$d] ?? null;
                if ($kept === null || $event->replaces($kept[1] instanceof Witness ? $kept[1]->event : $kept[1])) {
                    $newest[$d] = $opened;
                }
            }
        }
        $ofSale = [];
        $others = [];
        $none = [];
        $newer = [];
        foreach ($newest as $d => $opened) {
            if ($opened[0] === 'newer') {
                $newer[$d] = true;
                continue;
            }
            $witness = $opened[1];
            $of = ['sats' => $witness->sats, 'inputs' => $witness->inputs, 'replacement' => $witness->replacement, 'seenAt' => $witness->event->createdAt];
            if ($witness->sale === null) {
                $none[$witness->txid] = true;
            } elseif ($witness->isOf($sale, $expiresAt)) {
                $ofSale[$witness->txid] = $of;
            } else {
                $others[$witness->txid] = $of;
            }
        }

        $key = new Secret($seenAddressKey); // A Secret: a dump of the closure shows nothing of it.

        return new self($ofSale, $others, $none, $newer, static fn(string $txid, ?string $of): string => Derive::witnessAddress($key->reveal(), $address, $txid, $of), $sale);
    }

    /**
     * The three tags the witnesses of an invoice at A are read by: the order tags of its sale and
     * of none at A, the address tag of A.
     *
     * @return list<string>
     */
    private static function tags(WitnessKeys $keys, string $address, string $sale): array
    {
        $seenAddressKey = $keys->seenAddressKey->reveal();

        return [Derive::orderTag($seenAddressKey, $address, $sale), Derive::orderTag($seenAddressKey, $address, null), Derive::addressTag($seenAddressKey, $address)];
    }

    /**
     * Whether a witness of a newer format is at the witness address of A, $txid and $of (the
     * sale, or null: none): it is never written over (SPEC.md §12).
     */
    public function newerAt(string $txid, ?string $of): bool
    {
        return $this->newer !== [] && isset($this->newer[($this->witnessAddress)($txid, $of)]);
    }

    /**
     * Whether a witness of a newer format names no transaction of $listed (it may be of a payment
     * they replace): its address is none of A, a listed txid, and the sale or none.
     *
     * @param list<string> $listed
     */
    public function newerNamesNoneOf(array $listed): bool
    {
        if ($this->newer === []) {
            return false;
        }
        $known = [];
        foreach ($listed as $txid) {
            $known[($this->witnessAddress)($txid, $this->sale)] = true;
            $known[($this->witnessAddress)($txid, null)] = true;
        }
        foreach (array_keys($this->newer) as $d) {
            if (!isset($known[$d])) {
                return true;
            }
        }

        return false;
    }
}
