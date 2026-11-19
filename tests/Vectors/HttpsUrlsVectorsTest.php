<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\LightningAddress;
use Sattleme\Checkout\Internal\Url;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * https-urls.json (SPEC.md §15, An https URL; §16, A Lightning address): https URLs, their hosts
 * and ports, whether two are on the same host, and the pay URLs of Lightning addresses.
 */
final class HttpsUrlsVectorsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function urls(): array
    {
        return V::named(V::l(V::object('vectors/https-urls.json')['urls']), 'url');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('urls')]
    public function testAnHttpsUrl(array $case): void
    {
        $parts = Url::https($case['url']);
        self::assertSame($case['expected'], $parts === null ? null : ['host' => $parts['host'], 'port' => $parts['port']]);
        self::assertSame($parts !== null, Url::isHttps($case['url']));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function sameHosts(): array
    {
        return V::named(V::l(V::object('vectors/https-urls.json')['sameHost']), 'b');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('sameHosts')]
    public function testTwoUrlsOnTheSameHost(array $case): void
    {
        $a = Url::https($case['a']);
        $b = Url::https($case['b']);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame($case['expected'], Url::sameHost($a, $b));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function lightningAddresses(): array
    {
        return V::named(V::l(V::object('vectors/https-urls.json')['lightningAddresses']), 'address');
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('lightningAddresses')]
    public function testALightningAddressesPayUrl(array $case): void
    {
        self::assertSame($case['payUrl'], LightningAddress::parse($case['address'])?->payUrl());
    }
}
