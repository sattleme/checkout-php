<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\RateException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Currencies;
use Sattleme\Checkout\Internal\Decimal;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\NeverCancelled;
use Sattleme\Checkout\Internal\Pricing;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Rates;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/** currencies.json, fiat.json, prices.json, conversions.json, rate-sources.json (SPEC.md §25, §26). */
final class PricesVectorsTest extends TestCase
{
    public function testTheCurrencyTableIsTheSpecsOne(): void
    {
        self::assertSame(V::a(V::object('currencies.json')['currencies']), Currencies::DIGITS);
        self::assertSame(V::object('currencies.json')['published'], Currencies::PUBLISHED);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function fiats(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/fiat.json')['cases']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('fiats')]
    public function testFiat(array $case): void
    {
        try {
            $result = Pricing::fiat($case['input'])->toArray();
        } catch (InvalidOrderException $e) {
            $result = ['error' => $e->getErrorCode(), 'param' => $e->getParam()];
        }
        $expected = V::a($case['expected']);
        if (!isset($expected['error'])) {
            $expected = ['amount' => $expected['amount'], 'currency' => $expected['currency'], 'minor' => $expected['minor'], 'digits' => $expected['digits']];
        }
        self::assertSame($expected, $result);
    }

    /** @return array<string, array{0: string, 1: int|null}> */
    public static function digits(): array
    {
        $cases = [];
        foreach (V::l(V::object('vectors/fiat.json')['digits']) as $case) {
            $case = V::a($case);
            $code = V::s($case['code']);
            \assert($case['expected'] === null || \is_int($case['expected']));
            $cases['currencyDigits(' . json_encode($code, \JSON_THROW_ON_ERROR) . ')'] = [$code, $case['expected']];
        }

        return $cases;
    }

    /** SPEC.md §4, Functions: what the public Client::currencyDigits() gives for a text. */
    #[DataProvider('digits')]
    public function testCurrencyDigits(string $code, ?int $expected): void
    {
        self::assertSame($expected, Client::currencyDigits($code));
    }

    public function testPricesWrittenAsText(): void
    {
        $cases = V::a(V::object('vectors/prices.json')['text']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            self::assertSame($case['expected'], Decimal::fromText($case['price'])?->plain(), V::s($case['price']));
        }
    }

    public function testPricesWrittenAsJsonNumbers(): void
    {
        $cases = V::a(V::object('vectors/prices.json')['numbers']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            $number = Json::decode(V::s($case['json']));
            \assert(\is_int($number) || \is_float($number));
            self::assertSame($case['expected'], Decimal::fromNumber($number)?->plain(), V::s($case['json']));
        }
    }

    /** The shortest decimal of a double, against PHP's own with serialize_precision = -1. */
    public function testShortestDecimalsOfRandomDoubles(): void
    {
        $previous = (string) ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            for ($i = 0; $i < 2000; ++$i) {
                $bits = random_bytes(8);
                $double = unpack('E', $bits);
                \assert(\is_array($double) && \is_float($double[1]));
                $value = abs($double[1]);
                if (!is_finite($value) || $value === 0.0) {
                    continue;
                }
                [$digits, $exponent] = Decimal::shortest($value);
                $reference = Json::encode($value);
                // Never inside assert(): a production php.ini (zend.assertions = -1) skips it.
                self::assertSame(1, preg_match('/^([0-9]+)(?:\.([0-9]+))?(?:e([+-]?[0-9]+))?$/D', $reference, $m), $reference);
                $refDigits = $m[1] . ($m[2] ?? '');
                $refExponent = (int) ($m[3] ?? '0') + \strlen($m[1]) - 1;
                $leading = \strlen($refDigits) - \strlen(ltrim($refDigits, '0'));
                $refDigits = rtrim(ltrim($refDigits, '0'), '0');
                self::assertSame([$refDigits, $refExponent - $leading], [$digits, $exponent], $reference);
            }
        } finally {
            ini_set('serialize_precision', $previous);
        }
        // The bounds of SPEC.md's string rule: an exponent of 64 either way.
        self::assertNotNull(Decimal::fromNumber(1.2345e-64));
        self::assertNull(Decimal::fromNumber(1.2345e-65));
        self::assertNotNull(Decimal::fromNumber(9.99e64));
        self::assertNull(Decimal::fromNumber(1e65));
    }

    public function testConversions(): void
    {
        $cases = V::a(V::object('vectors/conversions.json')['cases']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            $fiat = Pricing::fiat($case['fiat']);
            $price = Decimal::fromText($case['price']);
            self::assertNotNull($price);
            self::assertSame($case['sats'], $price->sats($fiat->minor, $fiat->digits));
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function statuses(): iterable
    {
        foreach (V::a(V::object('vectors/rate-sources.json')['statuses']) as $case) {
            $case = V::o($case);
            yield 'HTTP ' . V::i($case['status']) => [$case];
        }
    }

    /**
     * A source that answers with a status other than 2xx, whichever source (an HttpClient of the
     * integrator's may give a status above 599).
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('statuses')]
    public function testStatuses(array $case): void
    {
        $http = new class (V::i($case['status'])) implements HttpClient {
            public function __construct(private readonly int $status) {}

            public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
            {
                return new HttpResponse($this->status, '{}');
            }
        };
        $context = TestContext::create(new TestConnector(), 1791378000, $http);
        foreach (Protocol::DEFAULT_RATE_SOURCES as $source) {
            try {
                Rates::ask($context, $source, 'CHF', null, new NeverCancelled());
                $result = ['ok' => true];
            } catch (SattlemeException $e) {
                $result = ['error' => $e->getErrorCode()];
            }
            self::assertSame($case['expected'], $result, $source);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function sourceValues(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/rate-sources.json')['values']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('sourceValues')]
    public function testRateSourceValues(array $case): void
    {
        $value = \array_key_exists('rateSource', $case) ? self::phpValue($case['rateSource']) : Protocol::DEFAULT_RATE_SOURCES;
        $sources = Rates::sources($value);
        $expected = V::a($case['expected']);
        if (isset($expected['error'])) {
            self::assertNull($sources);
        } else {
            self::assertSame($expected['sources'], $sources);
        }
        self::assertSame(V::a(V::object('vectors/rate-sources.json')['default']), Protocol::DEFAULT_RATE_SOURCES);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function lists(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::a(V::object('vectors/rate-sources.json')['lists'])['cases']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('lists')]
    public function testListsOfSources(array $case): void
    {
        $lists = V::a(V::object('vectors/rate-sources.json')['lists']);
        $currency = V::s($lists['currency']);
        $now = V::i($lists['now']);
        $answers = V::a($case['answers']);
        $asked = [];
        $ask = static function (mixed $source, string $currency) use ($answers, $now, &$asked): array {
            \assert(\is_string($source));
            $asked[] = $source;
            $answer = $answers[$source];
            if (\is_string($answer)) {
                throw match ($answer) {
                    'network' => new NetworkException('down'),
                    'rate' => new RateException('bad'),
                    default => new UnsupportedCurrencyException($currency),
                };
            }
            $answer = V::a($answer);

            return Rates::checked($source, $currency, Decimal::fromText($answer['price']), $answer['time'], $now);
        };
        /** @var list<string> $sources */
        $sources = V::a($case['sources']);
        $context = TestContext::create(new TestConnector(), $now);
        try {
            [, $info] = Rates::first($context, $sources, $currency, null, new NeverCancelled(), $ask);
            $result = ['source' => $info->source, 'price' => $info->price, 'asked' => $asked];
        } catch (SattlemeException $e) {
            $result = ['error' => $e->getErrorCode(), 'asked' => $asked];
        }
        self::assertSame($case['expected'], $result);
        // Each source a later one replaces is logged.
        self::assertCount(max(0, \count($asked) - 1), TestContext::$warnings);
    }

    /** A vector's JSON value as PHP gives it: an object stays one. */
    private static function phpValue(mixed $value): mixed
    {
        return \is_array($value) && !array_is_list($value) ? (object) $value : $value;
    }
}
