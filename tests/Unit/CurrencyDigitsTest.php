<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * SPEC.md §4, Functions: Client::currencyDigits(), public, static, of no client: the decimals of
 * the currency whose code is exactly the text given in currencies.json, else null.
 */
final class CurrencyDigitsTest extends TestCase
{
    public function testItIsAPublicFunctionOfNoClient(): void
    {
        $method = new \ReflectionMethod(Client::class, 'currencyDigits');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
        self::assertSame('?int', (string) $method->getReturnType());
    }

    public function testItGivesTheDecimalsOfEachCurrencyOfTheTable(): void
    {
        $currencies = V::a(V::object('currencies.json')['currencies']);
        self::assertCount(155, $currencies);
        foreach ($currencies as $code => $digits) {
            self::assertSame($digits, Client::currencyDigits((string) $code), (string) $code);
        }
        self::assertSame([2, 0, 3, 3, 0], array_map(Client::currencyDigits(...), ['CHF', 'JPY', 'KWD', 'BHD', 'RSD']));
    }

    /** @return iterable<string, array{string}> */
    public static function others(): iterable
    {
        foreach (['chf', 'Chf', ' CHF', 'CHF ', "CHF\0", 'CHFF', 'CH', 'CHE', 'CHW', 'XAU', 'XAG', 'XTS', 'XXX', 'XDR', 'BTC', 'SAT', '', '756', '036', '0', '__proto__', 'constructor', 'toString', 'hasOwnProperty', 'valueOf'] as $code) {
            yield json_encode($code, \JSON_THROW_ON_ERROR) => [$code];
        }
    }

    /**
     * A code in another case or around spaces, a fund, a metal, a test code, no currency, a
     * numeric code (PHP's arrays hold "756" as an integer key) and the names an object holds in
     * some languages: none.
     */
    #[DataProvider('others')]
    public function testItGivesNoneForAnyOtherText(string $code): void
    {
        self::assertNull(Client::currencyDigits($code));
    }
}
