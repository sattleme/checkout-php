<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Onchain\Methods;
use Sattleme\Checkout\Internal\Onchain\Parts;
use Sattleme\Checkout\Internal\Order;
use Sattleme\Checkout\Internal\Tolerance;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The on-chain rules (SPEC.md §31): parts of replacements, the merchant's tolerance, the methods
 * of an order by its amount.
 */
final class OnchainRulesVectorsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function replacements(): iterable
    {
        yield from V::named(V::l(V::object('vectors/onchain-replacements.json')['cases']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('replacements')]
    public function testTheParts(array $case): void
    {
        $transactions = [];
        foreach (V::a($case['txs']) as $tx) {
            $tx = V::a($tx);
            $sats = V::s($tx['sats']);
            \assert(is_numeric($sats));
            /** @var list<string> $inputs */
            $inputs = V::a($tx['inputs']);
            $transactions[] = ['txid' => V::s($tx['txid']), 'sats' => $sats, 'inputs' => $inputs, 'order' => $tx['order'] === null ? null : V::i($tx['order']), 'listed' => $tx['listed'] === true];
        }
        $expected = [];
        foreach (V::a($case['parts']) as $txid => $parts) {
            $expected[$txid] = array_map(static fn(mixed $part): array => [V::a($part)['sats'], V::a($part)['root']], V::a($parts));
        }
        $actual = Parts::of($transactions);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function toleranceValues(): iterable
    {
        yield from V::named(V::l(V::object('vectors/onchain-tolerances.json')['values']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('toleranceValues')]
    public function testATolerance(array $case): void
    {
        // Read from JSON, as a channel or a lease holds it (a fraction stays a float).
        $value = \array_key_exists('value', $case) ? json_decode((string) json_encode($case['value'], \JSON_PRESERVE_ZERO_FRACTION)) : null;
        self::assertSame($case['expected'], Tolerance::read($value));
    }

    public function testTheLeastAcceptedAndTheDue(): void
    {
        $vector = V::object('vectors/onchain-tolerances.json');
        foreach (V::a($vector['accepted']) as $case) {
            $case = V::a($case);
            $sats = V::s($case['sats']);
            \assert(is_numeric($sats));
            self::assertSame($case['expected'], Tolerance::leastAccepted($sats, V::i($case['tolerance'])), (string) json_encode($case));
        }
        foreach (V::a($vector['due']) as $case) {
            $case = V::a($case);
            $sats = V::s($case['sats']);
            $paid = V::s($case['paidByRests']);
            // The `received` of the merchant's acceptance that counts (absent: none).
            $accepted = V::ns($case['merchantAccepted'] ?? null);
            \assert(is_numeric($sats) && is_numeric($paid) && ($accepted === null || is_numeric($accepted)));
            self::assertSame($case['expected'], Tolerance::due($sats, V::i($case['tolerance']), $paid, $accepted), (string) json_encode($case));
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function methods(): iterable
    {
        yield from V::named(V::l(V::object('vectors/payment-methods.json')['cases']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('methods')]
    public function testTheMethodsOfAnOrder(array $case): void
    {
        $sendable = V::a($case['sendable']);
        $min = V::s($sendable['min']);
        $max = V::s($sendable['max']);
        \assert(is_numeric($min) && is_numeric($max));
        $outcome = V::s($case['address']);
        \assert(\in_array($outcome, [Methods::LEASED, Methods::NONE, Methods::EXPLORER_FAILED], true));
        $expected = V::a($case['expected']);
        try {
            $order = Order::check(['ref' => 'ORD-1', 'sats' => V::s($case['sats'])], []);
            \assert($order->sats !== null);
            $methods = Methods::afterLease(Methods::byAmount($order->sats, $min, $max, 'sats'), $outcome);
        } catch (SattlemeException $e) {
            self::assertArrayHasKey('error', $expected, $e->getMessage());
            self::assertSame($expected['error'], $e->getErrorCode());
            if (isset($expected['param'])) {
                self::assertSame($expected['param'], method_exists($e, 'getParam') ? $e->getParam() : null);
            }

            return;
        }
        self::assertSame($expected, ['methods' => $methods]);
    }
}
