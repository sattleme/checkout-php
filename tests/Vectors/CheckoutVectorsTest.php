<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Channel;
use Sattleme\Checkout\Internal\Checkout\CheckoutLink;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Onchain\DepositAddress;
use Sattleme\Checkout\Internal\RedirectUrl;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/** redirect-urls.json, checkout-links.json (SPEC.md §30) and onchain-addresses.json's addresses, URIs, amounts (§31). */
final class CheckoutVectorsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function redirectUrls(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/redirect-urls.json')['cases']);

        return V::named($cases, 'url');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('redirectUrls')]
    public function testRedirectUrl(array $case): void
    {
        $expected = V::a($case['expected']);
        $shown = RedirectUrl::shown($case['url']);
        if (isset($expected['error'])) {
            self::assertSame(['error' => 'invalid_order', 'param' => 'redirectUrl'], $expected);
            self::assertNull($shown);
        } else {
            self::assertSame($expected['shown'], $shown);
        }
    }

    public function testOrigins(): void
    {
        $cases = V::a(V::object('vectors/checkout-links.json')['origins']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            // The channel's `checkout` field, as JSON gives it.
            $checkout = Json::decode(Json::encode($case['checkout']));
            $origin = $checkout instanceof \stdClass ? Channel::checkoutOrigin(Json::get($checkout, 'origin')) : null;
            self::assertSame($case['expected'], $origin, Json::encode($case['checkout']));
        }
    }

    public function testUrls(): void
    {
        $cases = V::a(V::object('vectors/checkout-links.json')['urls']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            /** @var list<string> $relays */
            $relays = V::a($case['relays']);
            $url = CheckoutLink::url(V::s($case['origin']), V::s($case['author']), V::bytes($case['linkKey']), $relays);
            self::assertSame($case['url'], $url);
            $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
            self::assertSame(['link', $case['author'], V::bytes($case['linkKey']), $relays], $fragment);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function fragments(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/checkout-links.json')['fragments']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('fragments')]
    public function testFragment(array $case): void
    {
        $result = CheckoutLink::fragment(V::s($case['fragment']));
        if (\is_array($case['expected'])) {
            $expected = $case['expected'];
            self::assertSame(['link', $expected['author'], V::bytes($expected['linkKey']), $expected['relays']], $result);
        } else {
            self::assertSame([$case['expected']], $result);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function records(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/checkout-links.json')['records']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('records')]
    public function testRecord(array $case): void
    {
        $text = file_get_contents(V::path('vectors/checkout-links.json'));
        \assert(\is_string($text));
        // The value as JSON gives it (objects as objects), from the file itself.
        $file = Json::decode($text);
        $value = null;
        foreach (V::a(Json::get($file, 'records')) as $record) {
            if (Json::get($record, 'name') === $case['name']) {
                $value = Json::get($record, 'value');
            }
        }
        $result = CheckoutLink::read($value);
        $expected = $case['expected'];
        if ($expected === 'invalid') {
            self::assertNull($result);
        } elseif ($expected === 'newer') {
            self::assertSame(['newer'], $result);
        } else {
            self::assertNotNull($result);
            self::assertSame('ok', $result[0]);
            // These records name no node: mempool.space (bitcoin-nodes.json's `records` have one).
            self::assertNull($result[1]['bitcoinNode']);
            unset($result[1]['bitcoinNode']);
            self::assertSame($expected, $result[1]);
        }
    }

    public function testDepositAddressesUrisAndAmounts(): void
    {
        $vectors = V::object('vectors/onchain-addresses.json');
        foreach (V::a($vectors['addresses']) as $case) {
            $case = V::a($case);
            self::assertSame($case['valid'], DepositAddress::isValid($case['address']), V::s($case['name']));
        }
        foreach (V::a($vectors['uris']) as $case) {
            $case = V::a($case);
            $sats = V::s($case['sats']);
            \assert(is_numeric($sats));
            self::assertSame($case['uri'], DepositAddress::paymentUri(V::ns($case['address']), $sats, V::ns($case['bolt11'])), V::s($case['name']));
        }
        foreach (V::a($vectors['amounts']) as $case) {
            $case = V::a($case);
            $sats = V::s($case['sats']);
            \assert(is_numeric($sats));
            self::assertSame($case['btc'], DepositAddress::btc($sats));
        }
    }
}
