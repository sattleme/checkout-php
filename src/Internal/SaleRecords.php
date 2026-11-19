<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\RestInvoice;
use Sattleme\Checkout\PaymentStatus;

/**
 * What the merchant's registry holds of a sale (SPEC.md §21, "The sale's declarations"): its
 * invoices, oldest first; the rest invoices of each invoice with an address; its leases still
 * held, by address; and how many relays sent each of its records.
 *
 * @internal
 */
final class SaleRecords
{
    /**
     * @param list<SaleInvoice>                     $invoices by createdAt, then payment hash, then address
     * @param array<string, list<RestInvoice>>      $rests    by restKey(address, expiresAt), sorted
     * @param array<string, Lease>                  $leases   the sale's leases not withdrawn, by address
     * @param array<string, int>                    $sentBy   how many relays sent a copy of each record whose id is its hash, by id
     */
    private function __construct(
        public readonly array $invoices,
        public readonly array $rests,
        public readonly array $leases,
        public readonly array $sentBy,
    ) {}

    /**
     * Reads them: the sale's records (paged, the sale secret for NIP-42) on the merchant's
     * relays, as soon as a majority answered; with $everyRelay, on every relay (SPEC.md §13: the
     * choice among several invoices of an order, which relays hold a record).
     *
     * @param list<string> $relays the merchant's
     *
     * @throws \Sattleme\Checkout\Exception\NetworkException when fewer than a majority answered
     */
    public static function read(RelayPool $pool, Context $context, Sale $sale, Registry $registry, array $relays, bool $everyRelay = false): self
    {
        $answers = $pool->read(
            $relays,
            ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$sale->author]],
            true,
            static fn(): string => $sale->secret->reveal(),
            static fn(\stdClass $value): bool => SealedRecord::opens($value, [Protocol::DECLARATION_TYPE, Protocol::LEASE_TYPE], $sale->author, static fn(string $address): string => Derive::outerKey($registry->key->reveal(), $address, $sale->author)),
            $everyRelay,
        );

        return self::fromAnswers($answers, $context, $sale, $registry, \count($relays));
    }

    /**
     * Each event opened once per id, as a declaration, else as the sale's lease. One declaration
     * per payment hash, and the newest record of the sale at each registry address of an address
     * (a lease, a withdrawal, or a record of a newer format: the latter two leave no lease there).
     * A declaration with `rest` is a rest invoice of the invoice it names, no invoice of the
     * order (invoicesOf()).
     *
     * @param array<string, list<mixed>> $answers by relay
     */
    public static function fromAnswers(array $answers, Context $context, Sale $sale, Registry $registry, int $relayCount): self
    {
        /** @var array<string, int> $sentBy relays that sent a copy of each event whose id is its hash */
        $sentBy = [];
        foreach ($answers as $events) {
            $ids = [];
            foreach ($events as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->idMatches()) {
                    $ids[$event->id] = true;
                }
            }
            foreach (array_keys($ids) as $id) {
                $sentBy[$id] = ($sentBy[$id] ?? 0) + 1;
            }
        }
        /** @var array<string, Declaration> $declarations by payment hash */
        $declarations = [];
        /** @var list<array{0: 'ok', 1: Lease}|array{0: 'withdrawn', 1: Event}|array{0: 'newer', 1: Event}> $leaseRecords */
        $leaseRecords = [];
        $checked = [];
        $newer = false;
        foreach ($answers as $events) {
            foreach ($events as $value) {
                $id = Json::get($value, 'id');
                if (\is_string($id) && isset($checked[$id])) {
                    continue;
                }
                $declaration = Declaration::open($value, $sale, $registry);
                if ($declaration !== null && $declaration[0] === 'ok') {
                    $opened = $declaration[1];
                    $checked[$opened->event->id] = true;
                    $hash = $opened->decoded->paymentHash;
                    if (!isset($declarations[$hash]) || $opened->event->replaces($declarations[$hash]->event)) {
                        $declarations[$hash] = $opened;
                    }
                    continue;
                }
                $lease = Lease::open($value, $sale, $registry);
                if ($lease === null) {
                    if ($declaration !== null) {
                        $newer = true;
                        $checked[$declaration[1]->id] = true;
                    }
                    continue;
                }
                $event = $lease[1] instanceof Lease ? $lease[1]->event : $lease[1];
                $checked[$event->id] = true;
                if ($lease[0] === 'newer') {
                    $newer = true;
                }
                $leaseRecords[] = $lease;
            }
        }
        if ($newer) {
            $context->warn('A declaration or a lease of this order is of a newer format: update sattleme/checkout');
        }

        // The newest record of the sale at each registry address: a lease there, or none.
        $newest = self::newest(array_map(static function (array $record): array {
            $event = $record[1] instanceof Lease ? $record[1]->event : $record[1];

            return ['d' => (string) $event->tag('d'), 'event' => $event];
        }, $leaseRecords));
        /** @var list<Lease> $held */
        $held = [];
        foreach ($newest as $index) {
            $record = $leaseRecords[$index];
            if ($record[0] === 'ok') {
                $held[] = $record[1];
            }
        }
        $majority = RelayPool::majority($relayCount);
        $rests = [];
        $ofOrder = [];
        foreach ($declarations as $declaration) {
            if ($declaration->rest !== null) {
                $rest = new RestInvoice($declaration->invoice, $declaration->decoded->paymentHash, $declaration->verifyUrl, $declaration->sats(), $declaration->decoded->timestamp, $declaration->decoded->expiresAt());
                $rest->declaration = $declaration->event;
                $rest->declaredOnMajority = ($sentBy[$declaration->event->id] ?? 0) >= $majority;
                $rests[self::restKey($declaration->rest['address'], $declaration->rest['expiresAt'])][] = $rest;
                continue;
            }
            $ofOrder[] = $declaration;
        }
        $invoices = [];
        foreach (self::invoicesOf(
            array_map(static fn(Declaration $d): array => [
                'event' => $d->event,
                'invoice' => $d->invoice,
                'paymentHash' => $d->decoded->paymentHash,
                'createdAt' => $d->decoded->timestamp,
            ], $ofOrder),
            array_map(static fn(#[\SensitiveParameter] Lease $l): array => [
                'event' => $l->event,
                'address' => $l->address,
                'invoice' => $l->invoice,
                'createdAt' => $l->createdAt,
                'confirmed' => $l->isConfirmed(),
            ], $held),
        ) as [$d, $l]) {
            $declaration = $d === null ? null : $ofOrder[$d];
            $lease = $l === null ? null : $held[$l];
            if ($declaration !== null) {
                $sats = $declaration->sats();
                $address = $lease?->address;
                $invoices[] = new SaleInvoice(
                    new DeclaredInvoice(
                        $sale->ref,
                        $declaration->invoice,
                        $declaration->decoded->paymentHash,
                        $declaration->verifyUrl,
                        $address,
                        DepositAddress::paymentUri($address, $sats, $declaration->invoice),
                        $sats,
                        Pricing::declared($declaration->fiat),
                        $declaration->note,
                        $declaration->decoded->timestamp,
                        $declaration->decoded->expiresAt(),
                        $declaration->event->createdAt,
                    ),
                    $declaration->event,
                    $lease,
                    $declaration,
                );
            } else {
                \assert($lease !== null);
                $invoices[] = new SaleInvoice(
                    new DeclaredInvoice(
                        $sale->ref,
                        null,
                        null,
                        null,
                        $lease->address,
                        DepositAddress::paymentUri($lease->address, $lease->sats, null),
                        $lease->sats,
                        Pricing::declared($lease->fiat),
                        $lease->note,
                        $lease->createdAt,
                        $lease->expiresAt,
                        $lease->event->createdAt, // its mark's
                    ),
                    null,
                    $lease,
                    null,
                );
            }
        }
        foreach ($rests as $key => $list) {
            $rests[$key] = RestInvoice::sorted($list);
        }
        $leases = [];
        foreach ($held as $lease) {
            $leases[$lease->address] = $lease;
        }

        return new self($invoices, $rests, $leases, $sentBy);
    }

    /**
     * Of records at registry addresses, the newest at each (the highest created_at, then the
     * lowest id, as relays keep them): their indices.
     *
     * @param list<array{d: string, event: Event}> $records
     *
     * @return list<int>
     */
    public static function newest(array $records): array
    {
        $newest = [];
        foreach ($records as $index => $record) {
            $known = $newest[$record['d']] ?? null;
            if ($known === null || $record['event']->replaces($records[$known]['event'])) {
                $newest[$record['d']] = $index;
            }
        }

        return array_values($newest);
    }

    /**
     * The order's invoices (SPEC.md §21), oldest first (by createdAt, then payment hash, none
     * first, then address): one per declaration of the order, with the address of the newest
     * confirmed lease naming its invoice (compared ignoring ASCII case); and one per confirmed
     * lease of an order paid on-chain only (its `invoice` null). A lease that is not confirmed
     * gives nothing (its createInvoice failed before its address was the order's); a lease naming
     * an invoice that has no declaration is left out (that invoice was never shown). Each as the
     * index of its declaration (null for one a lease gives) and of its lease (null without an
     * address).
     *
     * @param list<array{event: Event, invoice: string, paymentHash: string, createdAt: int}>                                $declarations the order's, one per payment hash
     * @param list<array{event: Event, address: string, invoice: string|null, createdAt: int, confirmed: bool}> $leases       the sale's newest record at each registry address, when a lease
     *
     * @return list<array{0: int|null, 1: int|null}>
     */
    public static function invoicesOf(array $declarations, array $leases): array
    {
        $found = [];
        foreach ($declarations as $d => $declaration) {
            $chosen = null;
            foreach ($leases as $l => $lease) {
                if ($lease['confirmed'] && $lease['invoice'] !== null && Text::asciiLower($lease['invoice']) === Text::asciiLower($declaration['invoice'])
                    && ($chosen === null || $lease['event']->replaces($leases[$chosen]['event']))) {
                    $chosen = $l;
                }
            }
            $found[] = [
                'key' => [$declaration['createdAt'], $declaration['paymentHash'], $chosen === null ? '' : $leases[$chosen]['address']],
                'value' => [$d, $chosen],
            ];
        }
        foreach ($leases as $l => $lease) {
            if ($lease['confirmed'] && $lease['invoice'] === null) {
                $found[] = ['key' => [$lease['createdAt'], '', $lease['address']], 'value' => [null, $l]];
            }
        }
        usort($found, static function (array $a, array $b): int {
            if ($a['key'][0] !== $b['key'][0]) {
                return $a['key'][0] <=> $b['key'][0];
            }
            $byHash = strcmp($a['key'][1], $b['key'][1]);

            return $byHash !== 0 ? $byHash : strcmp($a['key'][2], $b['key'][2]);
        });

        return array_map(static fn(array $entry): array => $entry['value'], $found);
    }

    /**
     * Step 1 of SPEC.md §21, "The check": the indices of the invoices (oldest first, their
     * `expiresAt`) that may still be paid at $now, until their end and its grace period.
     *
     * @param list<int> $expiresAt
     *
     * @return list<int>
     */
    public static function mayStillBePaid(array $expiresAt, int $now): array
    {
        $indices = [];
        foreach ($expiresAt as $index => $end) {
            if ($now < $end + Protocol::GRACE_SECONDS) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    /**
     * Of several invoices that may still be paid, whether a majority of the $relays relays sent
     * each one's last record ($lastRecords, their ids), from how many relays sent each record
     * ($sentBy, read again on every relay): the one shown is the one so held (SPEC.md §21).
     *
     * @param list<string>       $lastRecords
     * @param array<string, int> $sentBy
     *
     * @return list<bool>
     */
    public static function held(array $lastRecords, array $sentBy, int $relays): array
    {
        $majority = RelayPool::majority($relays);

        return array_map(static fn(string $id): bool => ($sentBy[$id] ?? 0) >= $majority, $lastRecords);
    }

    /**
     * Of the invoices that may still be paid (oldest first), from the payment check of each and,
     * of several, whether a majority of the relays sent its last record ($held, read again on
     * every relay; null when fewer than a majority answered that read; of one alone, not read):
     * ['paid' => the first paid] (a payment proves itself, whatever the read), else ['failed' =>
     * the first that failed] (that invoice may have been paid), else ['readFailed' => true], else
     * ['handOut' => the only one; of several, the only one held (the one shown), else the newest
     * that received something (unconfirmed, or open with received above 0), else the newest].
     *
     * @param list<PaymentStatus|SattlemeException> $statuses
     * @param list<bool>|null                               $held
     *
     * @return array{paid: int}|array{failed: int}|array{readFailed: true}|array{handOut: int}
     */
    public static function pendingOutcome(array $statuses, ?array $held): array
    {
        foreach ($statuses as $index => $status) {
            if ($status instanceof PaymentStatus && $status->state === PaymentStatus::PAID) {
                return ['paid' => $index];
            }
        }
        foreach ($statuses as $index => $status) {
            if ($status instanceof SattlemeException) {
                return ['failed' => $index];
            }
        }
        if ($held === null) {
            return ['readFailed' => true];
        }
        if (\count($statuses) === 1) {
            return ['handOut' => 0];
        }
        $shown = array_keys(array_filter($held));
        if (\count($shown) === 1) {
            return ['handOut' => $shown[0]];
        }
        $received = null;
        foreach ($statuses as $index => $status) {
            if ($status->state === PaymentStatus::UNCONFIRMED
                || ($status->state === PaymentStatus::OPEN && BigInt::cmp($status->received ?? '0', '0') > 0)) {
                $received = $index;
            }
        }

        return ['handOut' => $received ?? \count($statuses) - 1];
    }

    /** The key, in $rests, of the invoice with $address and the end $expiresAt. */
    public static function restKey(string $address, int $expiresAt): string
    {
        return $address . '/' . $expiresAt;
    }

    /**
     * The rest invoices of the invoice with $address and $expiresAt.
     *
     * @return list<RestInvoice>
     */
    public function restsOf(string $address, int $expiresAt): array
    {
        return $this->rests[self::restKey($address, $expiresAt)] ?? [];
    }

    /**
     * The order's terms its lease of $address gives, when they are the invoice's ($sats,
     * $expiresAt): its tolerance, its price and its note. Null otherwise: judged without
     * tolerance, no rest invoice asked.
     *
     * @param numeric-string $sats
     */
    public function leaseOf(string $address, string $sats, int $expiresAt): ?Lease
    {
        $lease = $this->leases[$address] ?? null;

        return $lease !== null && $lease->sats === $sats && $lease->expiresAt === $expiresAt ? $lease : null;
    }
}
