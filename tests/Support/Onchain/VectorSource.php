<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Onchain;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\Explorer;
use Sattleme\Checkout\Internal\Onchain\LiveSource;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptance;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptanceKeys;
use Sattleme\Checkout\Internal\Onchain\OnchainSource;
use Sattleme\Checkout\Internal\Onchain\Witness;
use Sattleme\Checkout\Internal\Onchain\WitnessKeys;
use Sattleme\Checkout\Internal\Onchain\WitnessSet;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\Nostr\Relays;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The on-chain source of a case of vectors/onchain-payments.json: the SDK's own (LiveSource) for
 * the explorer and BlockCypher, asked over HTTP and answered as the case says (VectorHttp); the
 * witnesses the relays hold, made as a relay sends them and read as the client reads them
 * (WitnessSet::of()), and those published; the merchant's acceptances the merchant's 3 relays hold
 * (`merchantAccepted`), made as the merchant's app makes them and counted as the client counts
 * what the relays sent (MerchantAcceptance::of()), or, end to end, read on 3 local relays as the
 * client reads them (MerchantAcceptance::read()).
 */
final class VectorSource implements OnchainSource
{
    /** Another order's deposit address: where the witnesses of `address` "other" are. */
    public const OTHER_ADDRESS = 'bc1p4gmh64rd4m5y5nn8hwfnvtz2mm6lngpmwys5a257klyg03ju0pyqu65xe8';

    /** The merchant's relays of the cases: 3, of which 2 are a majority. */
    public const MERCHANT_RELAYS = ['wss://merchant-1.relay.example', 'wss://merchant-2.relay.example', 'wss://merchant-3.relay.example'];

    /** @var list<array{txid: string, sale: string|null, replacement: bool}> */
    public array $published = [];

    /** Whether the check read the merchant's acceptance. */
    public bool $merchantAcceptanceRead = false;

    /** What opens the merchant's acceptances of the cases: those of merchant-acceptance.json's team. */
    public readonly MerchantAcceptanceKeys $merchantKeys;

    /** @var array<string, list<Event>> the merchant's acceptances each of the merchant's relays holds, by URL */
    public readonly array $merchantAcceptances;

    /** @var list<Event> the witnesses it published, as the relays then hold them */
    private array $written = [];

    private readonly VectorHttp $http;

    private readonly LiveSource $live;

    private readonly WitnessKeys $keys;

    private readonly Context $context;

    /** Another sale than the order's: of the witnesses of `sale` "other". */
    private readonly string $otherSale;

    /**
     * @param array<string, mixed> $case
     * @param Relays|null          $merchantRelays end to end: the merchant's 3 relays, local, each
     *                                             holding what merchantAcceptances says
     */
    public function __construct(
        private readonly array $case,
        int $index,
        private readonly string $address,
        private readonly string $sale,
        IntegrationKey $key,
        private readonly int $expiresAt,
        private readonly ?Relays $merchantRelays = null,
    ) {
        $this->http = new VectorHttp($case, $index, $address);
        $context = new Context(new TestConnector(), $this->http, static fn(): int => 0, static fn(int $n): string => random_bytes(max(1, $n)), static function (string $message, array $context): void {}, TestTime::ms(1000), TestTime::ms(1000));
        $this->keys = WitnessKeys::of($key);
        $this->live = new LiveSource($context, new RelayPool($context), $key, [], Explorer::mempool($context));
        $this->context = $context;
        $this->otherSale = Sale::of($key, 'ANOTHER-ORDER')->author;
        // The team of merchant-acceptance.json's `made`, whose secret the vector gives, with the
        // same integration key: its records check out, at the same addresses.
        $made = V::o(V::object('vectors/app/merchant-acceptance.json')['made']);
        $team = IntegrationKey::parse(V::s($made['secretKey']));
        TestCase::assertSame(bin2hex($key->key->reveal()), bin2hex($team->key->reveal()));
        $this->merchantKeys = MerchantAcceptanceKeys::of($team, $sale, $address, $expiresAt);
        $records = \is_array($case['merchantAccepted'] ?? null) ? V::l($case['merchantAccepted']) : [];
        $events = $this->merchantAcceptanceEvents($records, V::bytes($made['teamSecret']));
        $held = [];
        foreach (self::MERCHANT_RELAYS as $relay => $url) {
            $held[$url] = [];
            foreach ($records as $record => $value) {
                // Each on the first `sentBy` of the 3 (absent: all 3).
                if ($relay < (V::a($value)['sentBy'] ?? \count(self::MERCHANT_RELAYS))) {
                    $held[$url][] = $events[$record];
                }
            }
        }
        $this->merchantAcceptances = $held;
    }

    /**
     * The events of the case's merchant's acceptances, as the merchant's app makes them: sealed
     * with the channel's key at the invoice's merchant's acceptance address, signed by $teamSecret,
     * of `{"version": 1, "received": <received>}` (a JSON number, whatever it is), or of version 2
     * for one of a newer format, dated `createdAt`. Where the case gives two of one date ids, made
     * again with other random bytes until their ids sort as the case's do (the lowest counts).
     *
     * @param list<mixed> $records
     *
     * @return list<Event>
     */
    private function merchantAcceptanceEvents(array $records, string $teamSecret): array
    {
        while (true) {
            $events = array_map(fn(mixed $record): Event => SealedRecord::seal(
                Protocol::MERCHANT_ACCEPTANCE_TYPE,
                isset(V::a($record)['newer']) ? ['version' => Protocol::MERCHANT_ACCEPTANCE_VERSION + 1] : ['version' => Protocol::MERCHANT_ACCEPTANCE_VERSION, 'received' => (int) V::s(V::a($record)['received'])],
                $teamSecret,
                $this->merchantKeys->encryptionKey->reveal(),
                $this->merchantKeys->address,
                V::i(V::a($record)['createdAt']),
                $this->context->random(...),
            ), $records);
            $sorted = true;
            foreach ($records as $i => $a) {
                foreach ($records as $j => $b) {
                    $idA = V::a($a)['id'] ?? null;
                    $idB = V::a($b)['id'] ?? null;
                    if ($idA !== null && $idB !== null && (strcmp(V::s($idA), V::s($idB)) < 0) !== (strcmp($events[$i]->id, $events[$j]->id) < 0)) {
                        $sorted = false;
                    }
                }
            }
            if ($sorted) {
                return $events;
            }
        }
    }

    /**
     * The transactions BlockCypher was asked about, in order.
     *
     * @return list<string>
     */
    public function asked(): array
    {
        return $this->http->asked;
    }

    public function explorer(string $address): array
    {
        return $this->live->explorer($address);
    }

    public function witnesses(string $address, string $sale, int $expiresAt, bool $again = false): WitnessSet
    {
        TestCase::assertSame($this->expiresAt, $expiresAt);
        // Read again after publishing: `witnessesAgain`, else those of `witnesses` and the ones it
        // published.
        $held = $again && \array_key_exists('witnessesAgain', $this->case) ? $this->case['witnessesAgain'] : $this->case['witnesses'];
        if ($held === 'unreadable') {
            throw new NetworkException('fewer than a majority answer');
        }
        $events = array_map(fn(mixed $witness): Event => $this->eventOf(V::a($witness)), V::l($held));
        if ($again && !\array_key_exists('witnessesAgain', $this->case)) {
            $events = [...$events, ...$this->written];
        }
        // What a majority of the relays sent to the read, each event as the pool keeps it (its
        // NIP-01 members, when it opens as a witness), read as the client reads it.
        $kept = array_values(array_filter(array_map(static fn(Event $event): \stdClass => $event->toJson(), $events), $this->keys->opens(...)));

        return WitnessSet::of(['wss://one.relay.example' => $kept, 'wss://two.relay.example' => $kept], $this->keys, $address, $sale, $expiresAt);
    }

    /**
     * The event of a witness of the case, as a relay sends it, signed by the seen secret: a witness
     * (Witness::event()) at the invoice's address, or at another order's (`address` "other"), of
     * the order's sale ("this", with the `expiresAt` of the invoice it is of), of another sale
     * (with the invoice's: nothing here depends on it) or of none, dated `seenAt`. One of a newer
     * format ("newer", "newer none"): a record of a newer version at the witness address of its
     * txid and the order's sale or none, with that address's `o` tags, dated a second before now
     * (the case gives no date of it).
     *
     * @param array<mixed> $witness
     */
    private function eventOf(array $witness): Event
    {
        $at = ($witness['address'] ?? null) === 'other' ? self::OTHER_ADDRESS : $this->address;
        $txid = V::s($witness['txid']);
        $seenAddressKey = $this->keys->seenAddressKey->reveal();
        if ($witness['sale'] === 'newer' || $witness['sale'] === 'newer none') {
            $of = $witness['sale'] === 'newer' ? $this->sale : null;

            return SealedRecord::seal(
                Protocol::WITNESS_TYPE,
                ['version' => Protocol::WITNESS_VERSION + 1],
                $this->keys->seenSecret->reveal(),
                $this->keys->encryptionKey->reveal(),
                Derive::witnessAddress($seenAddressKey, $at, $txid, $of),
                V::i($this->case['now']) - 1,
                $this->context->random(...),
                [['o', Derive::orderTag($seenAddressKey, $at, $of)], ['o', Derive::addressTag($seenAddressKey, $at)]],
            );
        }
        $of = match ($witness['sale']) {
            'this' => $this->sale,
            'other' => $this->otherSale,
            null => null,
            default => throw new \RuntimeException('A sale expected'),
        };
        /** @var list<string> $inputs */
        $inputs = V::a($witness['inputs']);

        return Witness::event(
            $this->keys,
            $at,
            $txid,
            $of,
            V::i($witness['sats']),
            $inputs,
            $witness['replacement'] === true,
            $of === null ? null : (isset($witness['expiresAt']) ? V::i($witness['expiresAt']) : $this->expiresAt),
            V::i($witness['seenAt']),
            $this->context,
        );
    }

    /**
     * Without relays, through the client's count of what the relays sent (MerchantAcceptance::of()),
     * each of the 3 answering with the events it holds as the pool keeps them (those that open);
     * "unreadable": fewer than a majority answer. End to end, read on the 3 local relays as the
     * client reads it (MerchantAcceptance::read()).
     */
    public function merchantAcceptance(string $address, string $sale, string $sats, int $expiresAt): MerchantAcceptance
    {
        TestCase::assertSame([$this->address, $this->sale, $this->expiresAt], [$address, $sale, $expiresAt]);
        $this->merchantAcceptanceRead = true;
        if ($this->merchantRelays !== null) {
            $context = new Context($this->merchantRelays->connector, $this->http, fn(): int => V::i($this->case['now']), static fn(int $n): string => random_bytes(max(1, $n)), static function (string $message, array $context): void {}, TestTime::ms(500), TestTime::ms(1000));

            return MerchantAcceptance::read(new RelayPool($context), $context, $this->merchantKeys, self::MERCHANT_RELAYS, $sats, $expiresAt);
        }
        if (($this->case['merchantAccepted'] ?? null) === 'unreadable') {
            throw new NetworkException('Fewer than a majority of the relays answered');
        }
        $answers = [];
        foreach ($this->merchantAcceptances as $url => $events) {
            $answers[$url] = array_values(array_filter(array_map(static fn(Event $event): \stdClass => $event->toJson(), $events), $this->merchantKeys->opens(...)));
        }

        return MerchantAcceptance::of($answers, $this->merchantKeys, $sats, $expiresAt, \count(self::MERCHANT_RELAYS));
    }

    public function publish(string $address, string $txid, ?string $sale, string $sats, array $inputs, bool $replacement, ?int $expiresAt): bool
    {
        \assert($address === $this->address && ($sale === null || $sale === $this->sale));
        // Of this invoice: its expiresAt; of none, null.
        TestCase::assertSame($sale === null ? null : $this->expiresAt, $expiresAt);
        if ($this->case['publishFails'] === true) {
            return false;
        }
        $this->published[] = ['txid' => $txid, 'sale' => $sale === null ? null : 'this', 'replacement' => $replacement];
        $this->written[] = Witness::event($this->keys, $address, $txid, $sale, (int) $sats, $inputs, $replacement, $expiresAt, V::i($this->case['now']), $this->context);

        return true;
    }

    public function received(string $txid): ?int
    {
        return $this->live->received($txid);
    }
}
