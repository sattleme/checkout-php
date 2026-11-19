<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Internal\Order;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/** order-text.json's references and notes (SPEC.md §17), and the order's other rules. */
final class OrderVectorsTest extends TestCase
{
    public function testReferences(): void
    {
        $cases = V::a(V::object('vectors/order-text.json')['refs']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            self::assertSame($case['valid'], Order::isRef($case['ref']), V::s($case['ref']));
            try {
                Order::check(['ref' => $case['ref'], 'sats' => '1000'], ['coingecko']);
                self::assertTrue($case['valid']);
            } catch (InvalidOrderException $e) {
                self::assertFalse($case['valid']);
                self::assertSame('ref', $e->getParam());
            }
        }
    }

    public function testNotesAndLabels(): void
    {
        $cases = V::a(V::object('vectors/order-text.json')['notes']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            try {
                $order = Order::check(['ref' => $case['ref'], 'sats' => '1000', 'note' => $case['note']], ['coingecko']);
                $result = ['label' => $order->label];
            } catch (InvalidOrderException $e) {
                $result = ['error' => $e->getErrorCode(), 'param' => $e->getParam()];
            }
            self::assertSame($case['expected'], $result);
        }
    }

    public function testTheOrdersOtherRules(): void
    {
        $valid = ['ref' => 'ORD-1', 'sats' => '1000'];
        self::assertSame('1000', Order::check($valid, [])->sats);
        self::assertSame('1000', Order::check(['ref' => 'ORD-1', 'sats' => 1000], [])->sats);
        self::assertSame('7', Order::check(['ref' => 'ORD-1', 'sats' => '007'], [])->sats);
        self::assertSame('2100000000000000', Order::check(['ref' => 'ORD-1', 'sats' => '2100000000000000'], [])->sats);
        self::assertSame(900, Order::check($valid, [])->durationSeconds);
        $order = Order::check(['ref' => 'ORD-1', 'fiat' => ['amount' => '24.50', 'currency' => 'CHF']], ['kraken']);
        self::assertSame(['kraken'], $order->rateSources);
        self::assertSame('2450', $order->fiat?->minor);
        $refused = [
            'order' => ['not an array', ['ORD-1'], $valid + ['redirectURL' => 'https://shop.example']],
            'sats' => [['ref' => 'ORD-1'], $valid + ['fiat' => ['amount' => '1', 'currency' => 'CHF']], ['ref' => 'ORD-1', 'sats' => '0'],
                ['ref' => 'ORD-1', 'sats' => '2100000000000001'], ['ref' => 'ORD-1', 'sats' => 1.5], ['ref' => 'ORD-1', 'sats' => '1e3'],
                ['ref' => 'ORD-1', 'sats' => '1234567890123456789'], ['ref' => 'ORD-1', 'sats' => -1]],
            'rateSource' => [$valid + ['rateSource' => 'kraken'], ['ref' => 'ORD-1', 'fiat' => ['amount' => '1', 'currency' => 'CHF'], 'rateSource' => 'CoinGecko']],
            'durationSeconds' => [$valid + ['durationSeconds' => 59], $valid + ['durationSeconds' => 3601], $valid + ['durationSeconds' => '900'], $valid + ['durationSeconds' => 900.0]],
            'checkout' => [$valid + ['checkout' => 'yes'], $valid + ['checkout' => null]],
            'redirectUrl' => [$valid + ['redirectUrl' => 'https://shop.example'], $valid + ['checkout' => true, 'redirectUrl' => 'http://shop.example']],
        ];
        foreach ($refused as $param => $orders) {
            foreach ($orders as $order) {
                try {
                    Order::check($order, ['coingecko']);
                    self::fail('Taken: ' . json_encode($order));
                } catch (InvalidOrderException $e) {
                    self::assertSame($param, $e->getParam(), (string) json_encode($order));
                }
            }
        }
        // A redirect URL null is none, with or without checkout.
        self::assertNull(Order::check($valid + ['redirectUrl' => null], [])->redirectUrl);
        self::assertSame('myshop://paid', Order::check($valid + ['checkout' => true, 'redirectUrl' => 'myshop://paid'], [])->redirectUrl);
    }
}
