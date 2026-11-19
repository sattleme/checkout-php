<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Parallel;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Nostr\Stamp;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;

/**
 * Leasing an address of the lot for an order (SPEC.md §31, "Leasing an address"): step 7 of
 * SPEC.md §17. The lease is published with `confirmed` false on a majority, confirmed by a
 * complete read of the address's leases on every relay (no other order holds it, it is the newest
 * record of its sale there), then marked: published again with `confirmed` true. Only then is the
 * address the order's. Two servers that lease one address at once cannot both keep it: each reads
 * after its own lease is on a majority, so the later of the two reads sees both, and withdraws its
 * own. A lease that was not confirmed is withdrawn; a confirmed one never is, whatever fails after
 * it (a withdrawal some relays only took would leave them disagreeing on whether an address that
 * may be the order's is held).
 *
 * @internal
 */
final class Leasing
{
    /**
     * @param list<string> $relays the merchant's
     */
    public function __construct(
        private readonly RelayPool $pool,
        private readonly Context $context,
        private readonly IntegrationKey $key,
        private readonly Registry $registry,
        private readonly array $relays,
        private readonly Explorer $explorer,
    ) {}

    /**
     * Leases an address for $sale, its lease first published at $createdAt: [Methods::LEASED,
     * address, its mark], [Methods::NONE] (the lot empty, its addresses leased, paid, named by a
     * witness or tried), or [Methods::EXPLORER_FAILED].
     *
     * @param \Closure(string, bool): array<string, mixed> $inside the lease's JSON for an address,
     *                                                       `confirmed` as given
     *
     * @return array{0: 'leased', 1: string, 2: Event}|array{0: 'none'}|array{0: 'explorer_failed'}
     *
     * @throws NetworkException        when fewer than a majority of the relays answered, or took the lease or its mark
     * @throws InvalidInvoiceException when the lease would not fit a record
     */
    public function lease(Sale $sale, \Closure $inside, int $createdAt): array
    {
        // 1. The lot.
        $lot = Lot::read($this->pool, $this->context, $this->key, $this->relays);
        if ($lot === []) {
            return [Methods::NONE];
        }
        // 2. The leases of its addresses (a complete read, complete or not: the confirmation
        // decides) and the addresses a witness names, at once.
        [$read, $named] = Parallel::all([
            fn(): array => $this->leases($lot, $this->context->now(), false),
            fn(): array => $this->namedAddresses($lot),
        ]);
        \assert(\is_array($read) && \is_array($named));
        /** @var array{records: array<string, list<LeaseRecord>>, answered: bool, complete: bool} $read */
        if (!$read['answered']) {
            throw new NetworkException('Fewer than a majority of the merchant\'s relays answered');
        }
        if (self::anyNewer($read['records'])) {
            // It holds its address, never written over (SPEC.md §12): the SDK to be updated.
            $this->context->warn('A lease of the integration\'s addresses has a newer format than this version of sattleme/checkout: update it');
        }
        // 3. The free ones: not leased now, named by no witness, and where the read shows no record
        // of the sale whose signature checks out (an earlier call of the order tried it: a new
        // lease could be of the second of its withdrawal, and either replace the other).
        $now = $this->context->now();
        $free = [];
        foreach ($lot as $address) {
            $records = $read['records'][$address] ?? [];
            if (!isset($named[$address]) && !Lease::held($records, $now) && !self::bySale($records, $sale, null)) {
                $free[] = $address;
            }
        }
        foreach (\array_slice($this->shuffled($free), 0, Protocol::MAX_ADDRESS_TRIES) as $address) {
            // 3.1 Paid on-chain already: its witnesses `none`, for the merchant's app; the next one.
            // The explorer read with its fallback (SPEC.md §31): the merchant's Bitcoin node with
            // "none" and down, no address.
            try {
                $transactions = $this->explorer->read(static fn(Esplora $explorer): array => $explorer->transactions($address));
            } catch (NetworkException $e) {
                $this->context->warn('The explorer did not answer while leasing a deposit address: ' . $e->getMessage());

                return [Methods::EXPLORER_FAILED];
            }
            $paid = false;
            foreach ($transactions as $transaction) {
                $sats = $transaction->pays($address);
                if (BigInt::cmp($sats, '0') > 0) {
                    $paid = true;
                    $this->witnessNone($address, $transaction, $sats);
                }
            }
            if ($paid) {
                continue;
            }
            // 3.2 The lease, not confirmed, on a majority: it holds the address, never gives the
            // invoice one. Else withdrawn where it was taken.
            $lease = $this->event($sale, $address, $inside($address, false), $createdAt);
            if (!$this->pool->publish($this->relays, $lease, static fn(): string => $sale->secret->reveal())) {
                $this->withdraw($sale, $address, $lease);

                throw new NetworkException('Fewer than a majority of the relays took the lease of a deposit address: try again');
            }
            // 3.3 Confirmed by the address's leases read again, on every relay: a relay that hides
            // a lease cannot hide it while one that holds it answers in time (SPEC.md §13, §27).
            $again = $this->leases([$address], $this->context->now(), true);
            $records = $again['records'][$address] ?? [];
            if (!$again['answered'] || !Lease::isConfirmedBy($records, Stamp::of($lease), $this->context->now(), $again['complete'])) {
                // Another server leased it at once (the later reader sees both), the read could not
                // be complete, or fewer than a majority answered: withdrawn (safe on some relays
                // only: the address was never the order's), unless the read shows another record of
                // the sale there whose signature checks out, even from fewer than a majority:
                // another call of the order made it at once (its lock failed, SPEC.md §17), and it
                // is not this call's to end.
                if (!self::bySale($records, $sale, $lease)) {
                    $this->withdraw($sale, $address, $lease);
                }
                if (!$again['answered']) {
                    throw new NetworkException('Fewer than a majority of the merchant\'s relays answered');
                }
                continue;
            }
            // 3.4 Marked confirmed: the same lease again, `confirmed` true, which replaces it. From
            // now on it is never withdrawn, and the address is the order's.
            $mark = $this->event($sale, $address, $inside($address, true), max($this->context->now(), $lease->createdAt + 1));
            if (!$this->pool->publish($this->relays, $mark, static fn(): string => $sale->secret->reveal())) {
                throw new NetworkException('Fewer than a majority of the relays took the mark of the lease of a deposit address: try again');
            }

            // 3.5 The order's.
            return [Methods::LEASED, $address, $mark];
        }

        return [Methods::NONE];
    }

    /**
     * Withdraws $lease, the lease of $address by $sale that was not confirmed: its withdrawal, a
     * second after it (so that it replaces the lease, and no later record of the sale), published
     * on the merchant's relays; a failure is logged (the lease then holds its address until its
     * end, and never gives its invoice one).
     */
    private function withdraw(Sale $sale, string $address, Event $lease): void
    {
        $withdrawal = Lease::withdrawal($sale, $this->registry, $address, $lease->createdAt + 1, $this->context);
        if (!$this->pool->publish($this->relays, $withdrawal, static fn(): string => $sale->secret->reveal())) {
            $this->context->warn('A lease of a deposit address that was not confirmed could not be withdrawn on a majority of the relays: it holds the address until its end');
        }
    }

    /**
     * The lease's event, at $createdAt.
     *
     * @param array<string, mixed> $inside
     *
     * @throws InvalidInvoiceException when it does not fit a record
     */
    private function event(Sale $sale, string $address, #[\SensitiveParameter] array $inside, int $createdAt): Event
    {
        try {
            return Lease::event($sale, $this->registry, $address, $inside, $createdAt, $this->context);
        } catch (\LengthException) {
            throw new InvalidInvoiceException('The invoice\'s lease does not fit a NIP-44 plaintext');
        }
    }

    /**
     * Whether a record of $records (by address) is of a newer format. Its signature is not checked:
     * only a holder of the registry key seals one (SPEC.md §31, "Opening a lease").
     *
     * @param array<string, list<LeaseRecord>> $records
     */
    private static function anyNewer(array $records): bool
    {
        foreach ($records as $list) {
            foreach ($list as $record) {
                if ($record->kind === LeaseRecord::NEWER) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether $records hold a record of $sale whose signature checks out, other than $except.
     *
     * @param list<LeaseRecord> $records
     */
    private static function bySale(array $records, Sale $sale, ?Event $except): bool
    {
        foreach ($records as $record) {
            if ($record->stamp->pubkey === $sale->author && $record->stamp->id !== $except?->id && $record->signed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The records at the registry addresses of $addresses that may still hold them (created in the
     * last 7200 s), by address: a complete read (SPEC.md §13) with a key of this read alone, on
     * every relay with $everyRelay. Of the relays whose events the read keeps (those whose read
     * ended; on every relay, those still reading too, with the pages they sent), each one's first
     * copy of an event is opened (SPEC.md §31, "Opening a lease": its id first; its signature left
     * to the record, tried with the other relays' copies); what does not open takes no memory, and
     * of what opens only its stamp, its outside's kind and end, and each relay's signature are
     * kept (SPEC.md §13, Limits): never its tags nor its content.
     * `answered`: whether a majority of the relays answered; `complete`: whether a majority read
     * completely.
     *
     * @param list<string> $addresses
     *
     * @return array{records: array<string, list<LeaseRecord>>, answered: bool, complete: bool}
     */
    private function leases(array $addresses, int $now, bool $everyRelay): array
    {
        $byRegistryAddress = [];
        foreach ($addresses as $address) {
            $byRegistryAddress[Derive::depositRegistryAddress($this->registry->key->reveal(), $address)] = $address;
        }
        /** @var array<string, array{0: string, 1: LeaseRecord}> $opened what each id opened as, from any relay: its address, its record */
        $opened = [];
        /** @var array<string, array<string, string>> $sent each relay's first copy of each id that opens: its signature, by id */
        $sent = [];
        $keep = function (\stdClass $value, string $url) use ($byRegistryAddress, &$opened, &$sent): bool {
            $id = Json::get($value, 'id');
            \assert(\is_string($id));
            if (isset($sent[$url][$id])) {
                return false; // Its first copy counts.
            }
            if (isset($opened[$id])) {
                // Another copy of an event that opened: the same one when its id is its hash.
                $copy = Event::fromJson($value);
                if ($copy !== null && $copy->idMatches()) {
                    $sent[$url][$id] = $copy->sig;
                }

                return false;
            }
            $d = SealedRecord::dTag($value);
            $address = $d === null ? null : ($byRegistryAddress[$d] ?? null);
            if ($address === null) {
                return false;
            }
            Loop::get()->checkpoint();
            $record = Lease::outside($value, $this->registry, $d);
            if ($record === null) {
                return false; // Never remembered: another copy of that id may open.
            }
            $opened[$id] = [$address, $record];
            $sent[$url][$id] = $record->stamp->sig;

            return false;
        };
        $read = $this->pool->readComplete(
            $this->relays,
            ['kinds' => [Protocol::RECORD_KIND], '#d' => array_map('strval', array_keys($byRegistryAddress)), 'since' => $now - Protocol::LEASE_LOOKBACK],
            $this->context->randomSecret(...),
            $keep,
            $everyRelay,
        );
        // The records of the relays the read keeps, once per id, each with those relays' copies.
        $records = [];
        /** @var array<string, LeaseRecord> $made */
        $made = [];
        foreach (array_keys($read['answers']) as $url) {
            foreach ($sent[$url] ?? [] as $id => $sig) {
                if (isset($made[$id])) {
                    $made[$id]->addCopy($sig);
                    continue;
                }
                [$address, $first] = $opened[$id];
                $made[$id] = $records[$address][] = new LeaseRecord($first->kind, $first->end, $first->stamp->withSig($sig));
            }
        }
        return ['records' => $records] + RelayPool::outcome($read['reads']);
    }

    /**
     * The addresses of $addresses a witness names (SPEC.md §31, "Leasing an address", step 2): read
     * by their address tags, not paged, with a key of this read alone. An event of the seen author
     * that checks out with an address's tag as an `o` tag names that address, whatever its content
     * (Witness::addressesNamed()): the read keeps only those.
     *
     * @param list<string> $addresses
     *
     * @return array<string, true>
     *
     * @throws NetworkException when fewer than a majority answered
     */
    private function namedAddresses(array $addresses): array
    {
        $keys = WitnessKeys::of($this->key);
        $seenAddressKey = $keys->seenAddressKey->reveal();
        $byTag = [];
        foreach ($addresses as $address) {
            $byTag[Derive::addressTag($seenAddressKey, $address)] = $address;
        }
        $names = static fn(\stdClass $value): array => Witness::addressesNamed($value, $keys->seenAuthor, $byTag);
        $answers = $this->pool->read($this->relays, ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$keys->seenAuthor], '#o' => array_map('strval', array_keys($byTag))], false, $this->context->randomSecret(...), static fn(\stdClass $value): bool => $names($value) !== []);
        $named = [];
        foreach ($answers as $events) {
            foreach ($events as $value) {
                foreach ($names($value) as $address) {
                    $named[$address] = true;
                }
            }
        }

        return $named;
    }

    /**
     * A witness `none` of a transaction paying an address of the lot (a failure is logged).
     *
     * @param numeric-string $sats
     */
    private function witnessNone(string $address, Transaction $transaction, string $sats): void
    {
        if (BigInt::cmp($sats, (string) Protocol::MAX_SATS) > 0) {
            return; // No witness of an amount no transaction pays.
        }
        $keys = WitnessKeys::of($this->key);
        $witness = Witness::event($keys, $address, $transaction->txid, null, (int) $sats, $transaction->coins, false, null, $this->context->now(), $this->context);
        if (!$this->pool->publish($this->relays, $witness, static fn(): string => $keys->seenSecret->reveal())) {
            $this->context->warn('A witness of a paid deposit address could not be published on a majority of the relays');
        }
    }

    /**
     * $items in a random order, from the CSPRNG (Fisher–Yates, uniform draws).
     *
     * @param list<string> $items
     *
     * @return list<string>
     */
    private function shuffled(array $items): array
    {
        for ($i = \count($items) - 1; $i > 0; --$i) {
            $j = $this->uniform($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    /** A uniform integer from 0 to $bound − 1 ($bound ≤ 2^16), by rejection sampling of 2 random bytes. */
    private function uniform(int $bound): int
    {
        $limit = intdiv(65536, $bound) * $bound;
        do {
            $value = unpack('n', $this->context->random(2));
            \assert(\is_array($value) && \is_int($value[1]));
            $draw = $value[1];
        } while ($draw >= $limit);

        return $draw % $bound;
    }
}
