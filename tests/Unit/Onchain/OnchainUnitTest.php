<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit\Onchain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Loop\Parallel;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\BlockCypher;
use Sattleme\Checkout\Internal\Onchain\Esplora;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptance;
use Sattleme\Checkout\Internal\Onchain\OnchainPart;
use Sattleme\Checkout\Internal\Onchain\OnchainResult;
use Sattleme\Checkout\Internal\Onchain\OnchainSource;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\RestRecord;
use Sattleme\Checkout\Internal\Onchain\Transaction;
use Sattleme\Checkout\Internal\Onchain\WitnessSet;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\Vectors as V;
use Sattleme\Checkout\Tests\Support\World;

/** The on-chain parts' rules no vector reaches: boundaries, dates, shapes, locks. */
final class OnchainUnitTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: int|null}>
     */
    public static function dates(): iterable
    {
        yield 'UTC, milliseconds' => ['2026-10-10T13:05:12.345Z', 1791637512345];
        yield 'no fraction' => ['2026-10-10T13:05:12Z', 1791637512000];
        // As BlockCypher writes it, nothing else (SPEC.md §31, Explorers): another spelling
        // of RFC 3339 is a failure; the fraction past milliseconds dropped, never rounded.
        yield 'lower-case t and z' => ['2026-10-10t13:05:12.345z', null];
        yield 'an offset' => ['2026-10-10T15:05:12.345+02:00', null];
        yield 'a negative offset' => ['2026-10-10T08:05:12.345-05:00', null];
        yield 'microseconds, dropped' => ['2026-10-10T13:05:12.345999Z', 1791637512345];
        yield 'nanoseconds, dropped' => ['2026-10-10T13:05:12.345999999Z', 1791637512345];
        yield 'a fraction of 10 digits' => ['2026-10-10T13:05:12.3450000000Z', null];
        yield 'microseconds of zeros' => ['2026-10-10T13:05:12.345000Z', 1791637512345];
        yield 'a tenth' => ['2026-10-10T13:05:12.3Z', 1791637512300];
        yield 'a leap day' => ['2028-02-29T00:00:00Z', 1835395200000];
        yield 'no time zone' => ['2026-10-10T13:05:12.345', null];
        yield 'a space' => ['2026-10-10 13:05:12Z', null];
        yield 'February 30' => ['2026-02-30T00:00:00Z', null];
        yield 'a leap second' => ['2026-12-31T23:59:60Z', null];
        yield 'hour 24' => ['2026-10-10T24:00:00Z', null];
        yield 'an offset of 24 hours' => ['2026-10-10T13:05:12+24:00', null];
        yield 'a fraction without digits' => ['2026-10-10T13:05:12.Z', null];
        yield 'a trailing newline' => ["2026-10-10T13:05:12Z\n", null];
    }

    #[DataProvider('dates')]
    public function testBlockCyphersDates(string $text, ?int $expected): void
    {
        self::assertSame($expected, BlockCypher::milliseconds($text));
    }

    public function testOurDatesAreTheTestsOnes(): void
    {
        self::assertSame(1791637512345, BlockCypher::milliseconds(World::rfc3339(1791637512345)));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function transactions(): iterable
    {
        $valid = World::transaction(str_repeat('ab', 32), [str_repeat('cd', 32) . ':1'], [[1000, 'bc1pexample']], 5);
        yield 'a txid in upper case' => [['txid' => str_repeat('AB', 32)] + $valid];
        yield 'a vout above 4 294 967 295' => [['vin' => [['txid' => str_repeat('cd', 32), 'vout' => 4294967296]]] + $valid];
        yield 'an input without a txid' => [['vin' => [['vout' => 1]]] + $valid];
        yield 'a negative value' => [['vout' => [['value' => -1]]] + $valid];
        yield 'a value of a fraction' => [['vout' => [['value' => 1.5]]] + $valid];
        yield 'an address not a string' => [['vout' => [['value' => 1, 'scriptpubkey_address' => 7]]] + $valid];
        yield 'an address of null' => [['vout' => [['value' => 1, 'scriptpubkey_address' => null]]] + $valid];
        yield 'confirmed without a height' => [['status' => ['confirmed' => true]] + $valid];
        yield 'confirmed not a boolean' => [['status' => ['confirmed' => 'yes']] + $valid];
        yield 'vin not a list' => [['vin' => ['a' => 1]] + $valid];
        // SPEC.md §31: outputs that together hold more than every bitcoin (each a safe integer).
        yield 'outputs above every bitcoin' => [['vout' => [['value' => 2100000000000000, 'scriptpubkey_address' => 'bc1pa'], ['value' => 1, 'scriptpubkey_address' => 'bc1pb']]] + $valid];
        yield 'outputs of 2^53 - 1 each' => [['vout' => array_fill(0, 2000, ['value' => 9007199254740991, 'scriptpubkey_address' => 'bc1pa'])] + $valid];
    }

    public function testATransactionMayHoldEveryBitcoin(): void
    {
        $value = World::transaction(str_repeat('ab', 32), [str_repeat('cd', 32) . ':1'], [[2099999999999999, 'bc1pa'], [1, 'bc1pb']]);
        $transaction = Transaction::fromJson(Json::decode(Json::encode($value)));
        self::assertNotNull($transaction);
        self::assertSame('2099999999999999', $transaction->pays('bc1pa'));
    }

    #[DataProvider('transactions')]
    public function testATransactionThatIsNotOne(mixed $value): void
    {
        self::assertNull(Transaction::fromJson(Json::decode(Json::encode($value))));
    }

    public function testATransaction(): void
    {
        $value = World::transaction(str_repeat('ab', 32), [str_repeat('cd', 32) . ':1'], [[1000, 'bc1pa'], [2000, 'bc1pb'], [500, 'bc1pa']], 969998);
        $value['vin'] = [['txid' => str_repeat('cd', 32), 'vout' => 1, 'is_coinbase' => false], ['is_coinbase' => true]];
        $value['vout'] = [['value' => 1000, 'scriptpubkey_address' => 'bc1pa'], ['value' => 2000, 'scriptpubkey_address' => 'bc1pb'], ['value' => 500, 'scriptpubkey_address' => 'bc1pa'], ['value' => 7]];
        $transaction = Transaction::fromJson(Json::decode(Json::encode($value)));
        self::assertNotNull($transaction);
        self::assertSame([str_repeat('cd', 32) . ':1'], $transaction->coins, 'a coinbase input spends no coin');
        self::assertSame('1500', $transaction->pays('bc1pa'));
        self::assertSame('0', $transaction->pays('bc1pc'));
        self::assertSame(3, $transaction->confirmations(970000));
        self::assertSame(1, $transaction->confirmations(969990), 'at least 1 once confirmed');
    }

    public function testAPaymentBroadcastAtTheLeasesEndIsInTime(): void
    {
        // now = end exactly: in time, its witness of the sale published, no BlockCypher.
        $source = new BoundarySource();
        $part = self::observed($source, TestContext::create(new TestConnector(), 1000));
        self::assertSame(['in time'], array_values($part->verdicts));
        self::assertSame([[str_repeat('ab', 32), str_repeat('ee', 32), false]], $source->published);
        self::assertSame([], $source->asked);
        // A second later: BlockCypher is asked.
        $source = new BoundarySource();
        self::observed($source, TestContext::create(new TestConnector(), 1001));
        self::assertSame([str_repeat('ab', 32)], $source->asked);
    }

    public function testTheClockIsReadOnceTheExplorerAnswered(): void
    {
        // The check starts 5 s before the lease's end; the explorer answers 10 s after it, listing
        // a transaction BlockCypher does not know yet. Listed then, it was broadcast by then, not
        // by the check's start (SPEC.md §31): not in time, no witness of the sale; late, a
        // witness none. Its witness of the sale would say in time for good.
        $clock = 1000 - 5;
        $source = new BoundarySource(1000, static function () use (&$clock): void {
            $clock = 1000 + 10;
        });
        $context = TestContext::create(new TestConnector(), static function () use (&$clock): int {
            return $clock;
        });
        $part = self::observed($source, $context);
        self::assertSame(1010, $part->now);
        self::assertSame(['late'], array_values($part->verdicts));
        self::assertSame([[str_repeat('ab', 32), null, false]], $source->published);
        self::assertSame([str_repeat('ab', 32)], $source->asked);
        // Judged by that clock too: past the end, underpaid for good.
        $judged = $part->judge('1000', 0, '0', 1000 - 120);
        self::assertSame([OnchainResult::UNDERPAID, '0', true], [$judged->state, $judged->received, $judged->final]);
    }

    public function testNoWitnessNoneIsPublishedOverOneOfANewerFormat(): void
    {
        // Late (past the lease's end, BlockCypher does not know it), a witness of a newer format
        // at the witness address of A, T and none (SPEC.md §31, step 6): none published over it
        // (SPEC.md §12), the transaction late all the same; warned of.
        $txid = str_repeat('ab', 32);
        $source = new BoundarySource(1000, null, [BoundarySource::witnessAddress($txid, null)]);
        $part = self::observed($source, TestContext::create(new TestConnector(), 2000));
        self::assertSame([$txid => OnchainPart::LATE], $part->verdicts);
        self::assertSame([], $source->published);
        self::assertSame([$txid], $source->asked);
        self::assertSame(['A witness of this address has a newer format than this version of sattleme/checkout: update it'], array_column(TestContext::$warnings, 0));
        // At the address of the sale and T: undecided, nothing published either.
        $source = new BoundarySource(1000, null, [BoundarySource::witnessAddress($txid, str_repeat('ee', 32))]);
        $part = self::observed($source, TestContext::create(new TestConnector(), 2000));
        self::assertSame([$txid => OnchainPart::UNDECIDED], $part->verdicts);
        self::assertSame([], $source->published);
        // Without one: a witness none.
        $source = new BoundarySource();
        self::observed($source, TestContext::create(new TestConnector(), 2000));
        self::assertSame([[$txid, null, false]], $source->published);
        self::assertSame([], TestContext::$warnings);
    }

    public function testARestRecordOfANewerFormatIsToldByItsAddress(): void
    {
        $key = IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']);
        $keys = RestKeys::of($key, 'ORD-1', World::address(), 1791382900);
        $context = TestContext::create(new TestConnector());
        $d = $keys->recordAddress(str_repeat('ab', 32));
        $expiration = [['expiration', '1791987700']];
        // A content of a newer NIP-44 version, and a value of a newer version: at their address.
        $content = Event::sign($keys->secret->reveal(), 30078, 1791382000, [['d', $d], ...$expiration], '#', random_bytes(32));
        self::assertSame($d, RestRecord::newerAt($content->toJson(), $keys));
        $value = SealedRecord::seal(Protocol::REST_TYPE, ['version' => 2], $keys->secret->reveal(), $keys->encryptionKey->reveal(), $d, 1791382000, $context->random(...), $expiration);
        self::assertSame($d, RestRecord::newerAt($value->toJson(), $keys));
        // Not one: a record of this version, an event that does not check out, another author's,
        // the acceptance record of a newer format.
        $ours = RestRecord::event($keys, V::s(V::a(V::object('vectors/app/app.json')['realInvoice'])['invoice']), 'https://breez.tips/v', str_repeat('ab', 32), 1791382900, 1791382000, $context);
        self::assertNull(RestRecord::newerAt($ours->toJson(), $keys));
        $forged = $content->toJson();
        $forged->sig = str_repeat('0', 128);
        self::assertNull(RestRecord::newerAt($forged, $keys));
        do {
            $other = random_bytes(32);
        } while (!Secp256k1::isSecretKey($other));
        self::assertNull(RestRecord::newerAt(Event::sign($other, 30078, 1791382000, [['d', $d]], '#', random_bytes(32))->toJson(), $keys));
        self::assertNull(RestRecord::newerAt(Event::sign($keys->secret->reveal(), 30078, 1791382000, [['d', $keys->acceptanceAddress()]], '#', random_bytes(32))->toJson(), $keys));
    }

    public function testAnExplorerGivingMoreThanEveryBitcoinIsNoAnswer(): void
    {
        // SPEC.md §31, "Explorers": a transaction whose outputs together hold more than every
        // bitcoin (2 100 000 000 000 000 sats), each output a safe integer, is none: the explorer's
        // answer is a failure (network), never a witness of such sats nor an error of PHP's.
        $answer = Json::encode([World::transaction(str_repeat('ab', 32), [str_repeat('cd', 32) . ':0'], [[2100000000000000, 'bc1paddress'], [1, 'bc1paddress']])]);
        $http = new class ($answer) implements HttpClient {
            public function __construct(private readonly string $answer) {}

            public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
            {
                return new HttpResponse(200, $this->answer);
            }
        };
        $explorer = new Esplora(TestContext::create(new TestConnector(), 1000, $http), Protocol::MEMPOOL);
        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('gave a transaction that is not one');
        Loop::get()->run(static fn(): array => $explorer->transactions('bc1paddress'));
    }

    public function testLocksRunOneAfterTheOther(): void
    {
        $locks = new Locks();
        $order = [];
        Loop::get()->run(static function () use ($locks, &$order): void {
            Parallel::all([
                static function () use ($locks, &$order): void {
                    $locks->with('a', static function () use (&$order): void {
                        $order[] = 'first in';
                        Loop::get()->sleepUntil(TestTime::deadline(0.05));
                        $order[] = 'first out';
                    });
                },
                static function () use ($locks, &$order): void {
                    $locks->with('a', static function () use (&$order): void {
                        $order[] = 'second in';
                    });
                },
                static function () use ($locks, &$order): void {
                    $locks->with('b', static function () use (&$order): void {
                        $order[] = 'other';
                    });
                },
            ]);
        });
        self::assertSame(['first in', 'other', 'first out', 'second in'], $order);
    }

    public function testParallelKeepsTheOrderAndTheLimit(): void
    {
        $running = 0;
        $most = 0;
        $outcomes = Loop::get()->run(static function () use (&$running, &$most): array {
            $tasks = [];
            for ($i = 0; $i < 10; ++$i) {
                $tasks[] = static function () use ($i, &$running, &$most): int {
                    ++$running;
                    $most = max($most, $running);
                    Loop::get()->sleepUntil(TestTime::deadline(0.01 * (10 - $i)));
                    --$running;
                    if ($i === 3) {
                        throw new NetworkException('three');
                    }

                    return $i;
                };
            }

            return Parallel::settle($tasks, 4);
        });
        self::assertSame(4, $most, 'four at a time'); // @phpstan-ignore staticMethod.impossibleType (set by the tasks)
        self::assertSame([0, 1, 2, null, 4, 5, 6, 7, 8, 9], array_column($outcomes, 0));
        self::assertInstanceOf(NetworkException::class, $outcomes[3][1]);
    }

    /**
     * Steps 1 to 3 of the boundaries' invoice (its lease ends at 1000), as a payment check runs
     * them: in a call of the loop (the witnesses and the merchant's acceptance are read at once).
     */
    private static function observed(BoundarySource $source, Context $context): OnchainPart
    {
        return Loop::get()->run(static fn(): OnchainPart => OnchainPart::observe($source, $context, 'bc1paddress', 1000 - 120, str_repeat('ee', 32), '1000'));
    }
}

/** One transaction paying the address, no witness: the boundaries of step 3. */
final class BoundarySource implements OnchainSource
{
    /** @var list<array{0: string, 1: ?string, 2: bool}> */
    public array $published = [];

    /** @var list<string> */
    public array $asked = [];

    /**
     * @param (\Closure(): void)|null $answering runs as the explorer answers
     * @param list<string>            $newer     the witness addresses of witnesses of a newer format
     */
    public function __construct(private readonly int $sats = 1000, private readonly ?\Closure $answering = null, private readonly array $newer = []) {}

    /** The witness address of the boundaries' address, $txid and $of (null: none). */
    public static function witnessAddress(string $txid, ?string $of): string
    {
        return hash('sha256', $txid . $of);
    }

    public function explorer(string $address): array
    {
        if ($this->answering !== null) {
            ($this->answering)();
        }
        $transaction = Transaction::fromJson(Json::decode(Json::encode(World::transaction(str_repeat('ab', 32), [str_repeat('cd', 32) . ':0'], [[$this->sats, $address]]))));
        \assert($transaction !== null);

        return [[$transaction], 100];
    }

    public function witnesses(string $address, string $sale, int $expiresAt, bool $again = false): WitnessSet
    {
        return new WitnessSet([], [], [], array_fill_keys($this->newer, true), self::witnessAddress(...), $sale);
    }

    public function merchantAcceptance(string $address, string $sale, string $sats, int $expiresAt): MerchantAcceptance
    {
        return MerchantAcceptance::none();
    }

    public function publish(string $address, string $txid, ?string $sale, string $sats, array $inputs, bool $replacement, ?int $expiresAt): bool
    {
        $this->published[] = [$txid, $sale, $replacement];

        return true;
    }

    public function received(string $txid): ?int
    {
        $this->asked[] = $txid;

        return null;
    }
}
