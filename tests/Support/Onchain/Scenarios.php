<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Onchain;

use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Onchain\Lease;
use Sattleme\Checkout\Internal\Onchain\LeaseRecord;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\LocalRelay;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\World;

/**
 * What the scenarios of on-chain payments write and read on the World's relays and explorers:
 * other orders' leases, records anybody writes at a registry address, payments.
 *
 * @property World $world
 */
trait Scenarios
{
    protected function sale(string $ref): Sale
    {
        return Sale::of($this->world->integration(), $ref);
    }

    /** The registry address of $address (its leases' d tag). */
    protected function registryAddress(string $address): string
    {
        return Derive::depositRegistryAddress($this->world->registry->key->reveal(), $address);
    }

    /**
     * The lease of $address by the sale of $ref, of an order paid on-chain only from $createdAt for
     * $seconds; published at $publishedAt ($createdAt by default; a mark a second later).
     */
    protected function leaseBy(string $ref, string $address, ?int $createdAt = null, int $seconds = 900, ?bool $confirmed = null, ?int $publishedAt = null, int $sats = 1000): Event
    {
        $createdAt ??= $this->world->now;
        $sale = $this->sale($ref);
        $inside = Lease::inside($address, $sats, null, null, $createdAt, $createdAt + $seconds, $sale->keyHex(), ['minor' => $sats, 'digits' => 0, 'currency' => 'SAT'], $ref, $sale->takerTag, 0, $confirmed);

        return Lease::event($sale, $this->world->registry, $address, $inside, $publishedAt ?? $createdAt, TestContext::create(new TestConnector()));
    }

    /**
     * A record anybody writes at $d (the registry address is public): an event of a key of its
     * own, its id its hash; its signature 64 bytes of nothing, which no read needs to check (a
     * real one would only take the test longer).
     */
    protected function junk(string $d, int $createdAt, string $content = 'x'): \stdClass
    {
        $pubkey = bin2hex(random_bytes(32));
        $tags = [['d', $d]];

        return (object) [
            'id' => hash('sha256', Json::encode([0, $pubkey, $createdAt, 30078, $tags, $content])),
            'pubkey' => $pubkey,
            'created_at' => $createdAt,
            'kind' => 30078,
            'tags' => $tags,
            'content' => $content,
            'sig' => str_repeat('00', 64),
        ];
    }

    /** Every relay takes $event (the newest of its address kept). */
    protected function everywhere(Event|\stdClass $event): void
    {
        foreach ($this->world->relays->relays as $relay) {
            $relay->take($event);
        }
    }

    /** @return list<LocalRelay> the key's relays, in order */
    protected function relays(): array
    {
        return array_values($this->world->relays->relays);
    }

    /**
     * What $relay holds of $author at $d: each record as it opens ('lease', 'withdrawn', 'newer',
     * or 'unopened').
     *
     * @return list<string>
     */
    protected function leaseKinds(LocalRelay $relay, string $d, string $author): array
    {
        $kinds = [];
        foreach ($relay->events as $value) {
            $event = Event::fromJson($value);
            if ($event !== null && $event->pubkey === $author && $event->tag('d') === $d) {
                $kinds[] = Lease::outside($value, $this->world->registry, $d)->kind ?? 'unopened';
            }
        }

        return $kinds;
    }

    /**
     * The withdrawals by $author at $d any relay received (taken or not).
     *
     * @return list<Event>
     */
    protected function withdrawals(string $d, string $author): array
    {
        $found = [];
        foreach ($this->world->relays->relays as $relay) {
            foreach ($relay->received as $value) {
                $event = Event::fromJson($value);
                if ($event !== null && $event->pubkey === $author && Lease::outside($value, $this->world->registry, $d)?->kind === LeaseRecord::WITHDRAWN) {
                    $found[] = $event;
                }
            }
        }

        return $found;
    }

    /**
     * A transaction paying $address $sats (and change elsewhere): its txid, the coins it spends,
     * and its JSON as mempool.space writes it.
     *
     * @return array{txid: string, inputs: list<string>, json: array<string, mixed>}
     */
    protected static function payment(string $address, int $sats): array
    {
        $txid = bin2hex(random_bytes(32));
        $inputs = [bin2hex(random_bytes(32)) . ':0'];

        return ['txid' => $txid, 'inputs' => $inputs, 'json' => World::transaction($txid, $inputs, [[$sats, $address], [5000, World::address()]])];
    }

    /**
     * $address's transactions, as the explorer lists them, now include $payment.
     *
     * @param array{txid: string, inputs: list<string>, json: array<string, mixed>} $payment
     */
    protected function broadcast(string $address, array $payment): void
    {
        $this->world->transactions[$address][] = $payment['json'];
    }

    /** The id of a record as a relay holds it. */
    protected static function idOf(\stdClass $record): string
    {
        $id = Json::get($record, 'id');
        if (!\is_string($id)) {
            throw new \LogicException('A record without an id');
        }

        return $id;
    }

    /** The explorer no longer lists $txid (evicted from the mempools). */
    protected function evict(string $address, string $txid): void
    {
        $this->world->transactions[$address] = array_values(array_filter($this->world->transactions[$address] ?? [], static fn(array $tx): bool => $tx['txid'] !== $txid));
    }

    /** $txid of $address mined at $height. */
    protected function mine(string $address, string $txid, int $height): void
    {
        foreach ($this->world->transactions[$address] ?? [] as $index => $transaction) {
            if ($transaction['txid'] === $txid) {
                $transaction['status'] = ['confirmed' => true, 'block_height' => $height];
                $this->world->transactions[$address][$index] = $transaction;
            }
        }
    }

    /**
     * What $call returns, or the exception of this package it throws.
     *
     * @phpstan-impure
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T|SattlemeException
     */
    protected static function outcome(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (SattlemeException $e) {
            return $e;
        }
    }
}
