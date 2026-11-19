<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Cancellation;
use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\RateException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Checkout\RateInfo;
use Sattleme\Checkout\RateSource;

/**
 * Rate sources (SPEC.md §26): the price of one bitcoin in a currency, from the sources the SDK
 * names, or a custom one.
 *
 * @internal
 */
final class Rates
{
    /** @var list<string> */
    public const NAMES = ['coingecko', 'mempool', 'opennode', 'coinbase', 'kraken'];

    /** @var list<string> */
    private const MEMPOOL_CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'CHF', 'AUD', 'JPY'];

    private function __construct() {}

    /**
     * The sources $value names, in the order they are asked: a source (a name, a RateSource, a
     * Closure), or a non-empty list of them without a name twice; else null.
     *
     * @return list<string|RateSource|\Closure>|null
     */
    public static function sources(mixed $value): ?array
    {
        if (self::isSource($value)) {
            /** @var string|RateSource|\Closure $value */
            return [$value];
        }
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            return null;
        }
        $names = [];
        foreach ($value as $source) {
            if (!self::isSource($source)) {
                return null;
            }
            if (\is_string($source)) {
                if (isset($names[$source])) {
                    return null;
                }
                $names[$source] = true;
            }
        }

        /** @var list<string|RateSource|\Closure> $value */
        return $value;
    }

    public static function name(string|RateSource|\Closure $source): string
    {
        return \is_string($source) ? $source : 'custom';
    }

    /**
     * The first usable price of $sources, asked one at a time in their order (SPEC.md §26).
     *
     * @param list<string|RateSource|\Closure>                                              $sources
     * @param (\Closure(string|RateSource|\Closure, string): array{0: Decimal, 1: RateInfo})|null $ask
     *
     * @return array{0: Decimal, 1: RateInfo}
     *
     * @throws NetworkException|RateException|UnsupportedCurrencyException|SattlemeException
     */
    public static function first(Context $context, array $sources, string $currency, #[\SensitiveParameter] ?string $coingeckoApiKey, Cancellation $cancellation, ?\Closure $ask = null): array
    {
        $key = $coingeckoApiKey === null ? null : new Secret($coingeckoApiKey); // Never in a closure as it is.
        $ask ??= static fn(string|RateSource|\Closure $source, string $currency): array => self::ask($context, $source, $currency, $key?->reveal(), $cancellation);
        $failure = null;
        $unsupported = null;
        foreach ($sources as $index => $source) {
            try {
                return $ask($source, $currency);
            } catch (UnsupportedCurrencyException $e) {
                $unsupported = $e;
            } catch (NetworkException|RateException $e) {
                $failure = $e;
            }
            $next = $sources[$index + 1] ?? null;
            if ($next !== null) {
                $context->warn(sprintf('Rate source %s failed (%s): %s; trying %s', self::name($source), $e->getErrorCode(), $e->getMessage(), self::name($next)));
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        if (\count($sources) > 1) {
            throw new UnsupportedCurrencyException($currency, 'None of ' . implode(', ', array_map(self::name(...), $sources)) . " has a price in {$currency}");
        }
        \assert($unsupported !== null);
        throw $unsupported;
    }

    /**
     * The price of one bitcoin in $currency from $source, checked (SPEC.md §25): an exact positive
     * decimal; when dated, a safe integer not more than 300 s ahead nor 3600 s old.
     *
     * @return array{0: Decimal, 1: RateInfo}
     */
    public static function ask(Context $context, string|RateSource|\Closure $source, string $currency, #[\SensitiveParameter] ?string $coingeckoApiKey, Cancellation $cancellation): array
    {
        $name = self::name($source);
        [$price, $time] = \is_string($source)
            ? self::named($context, $source, $currency, $coingeckoApiKey)
            : self::custom($source, $currency, $cancellation);

        return self::checked($name, $currency, $price, $time, $context->now());
    }

    /**
     * @return array{0: Decimal, 1: RateInfo}
     */
    public static function checked(string $name, string $currency, ?Decimal $price, mixed $time, int $now): array
    {
        if ($price === null) {
            throw new RateException("{$name} gave no valid price in {$currency}");
        }
        $dated = null;
        if ($time !== null) {
            $dated = Json::safeInteger($time);
            if ($dated === null || $dated > $now + Protocol::CLOCK_TOLERANCE) {
                throw new RateException("{$name} gave an invalid date for its price");
            }
            if ($now - $dated > Protocol::RATE_AGE) {
                throw new RateException("{$name}'s price in {$currency} is too old");
            }
        }

        return [$price, new RateInfo($name, $currency, $price->plain(), $now, $dated)];
    }

    /** @return array{0: ?Decimal, 1: mixed} the price and its time (null: undated) */
    private static function custom(RateSource|\Closure $source, string $currency, Cancellation $cancellation): array
    {
        try {
            $answer = $source instanceof RateSource ? $source->price($currency, $cancellation) : $source($currency, $cancellation);
        } catch (CancelledException|SattlemeException|NestedCallException $e) {
            throw $e; // A nested call is the integrator's mistake, never a rate to try again.
        } catch (\Throwable $e) {
            if ($cancellation->isCancelled()) {
                throw new CancelledException();
            }
            throw new RateException('The custom rate source failed', $e);
        }
        if (\is_string($answer)) {
            return [Decimal::fromText($answer), null];
        }
        if (!\is_array($answer)) {
            throw new RateException('The custom rate source gave no price');
        }

        return [Decimal::fromText($answer['price'] ?? null), $answer['time'] ?? null];
    }

    /** @return array{0: ?Decimal, 1: mixed} */
    private static function named(Context $context, string $name, string $currency, #[\SensitiveParameter] ?string $coingeckoApiKey): array
    {
        switch ($name) {
            case 'coingecko':
                $code = Text::asciiLower($currency);
                $body = self::json($context, $name, "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies={$code}&include_last_updated_at=true", $coingeckoApiKey !== null ? ['x-cg-demo-api-key' => $coingeckoApiKey] : []);
                $bitcoin = self::object($body, 'bitcoin', $name);
                $time = Json::get($bitcoin, 'last_updated_at');

                return [self::price($bitcoin, $code, $name, $currency), \is_int($time) || \is_float($time) ? $time : null];
            case 'mempool':
                if (!\in_array($currency, self::MEMPOOL_CURRENCIES, true)) {
                    throw new UnsupportedCurrencyException($currency, "mempool has no price in {$currency}");
                }
                $body = self::json($context, $name, 'https://mempool.space/api/v1/prices');
                $time = Json::get($body, 'time');
                if (!\is_int($time) && !\is_float($time)) {
                    throw new RateException('mempool gave no time');
                }

                return [self::price($body, $currency, $name, $currency), $time];
            case 'opennode':
                $body = self::json($context, $name, 'https://api.opennode.com/v1/rates');
                $data = self::object($body, 'data', $name);
                if (!Json::has($data, "BTC{$currency}")) {
                    throw new UnsupportedCurrencyException($currency, "opennode has no price in {$currency}");
                }

                return [self::price(self::object($data, "BTC{$currency}", $name), $currency, $name, $currency), null];
            case 'coinbase':
                $body = self::json($context, $name, 'https://api.coinbase.com/v2/exchange-rates?currency=BTC');
                $data = self::object($body, 'data', $name);
                if (Json::get($data, 'currency') !== 'BTC') {
                    throw new RateException('coinbase gave no rates of BTC');
                }

                return [self::price(self::object($data, 'rates', $name), $currency, $name, $currency), null];
            case 'kraken':
                $body = self::json($context, $name, "https://api.kraken.com/0/public/Ticker?pair=XBT{$currency}");
                $errors = Json::get($body, 'error');
                if (!\is_array($errors) || !array_is_list($errors)) {
                    throw new RateException('kraken gave an unexpected answer');
                }
                foreach ($errors as $error) {
                    if (\is_string($error) && str_starts_with($error, 'EQuery:Unknown asset pair')) {
                        throw new UnsupportedCurrencyException($currency, "kraken has no price in {$currency}");
                    }
                }
                if ($errors !== []) {
                    throw new RateException('kraken answered an error');
                }
                $entries = get_object_vars(self::object($body, 'result', $name));
                if (\count($entries) !== 1) {
                    throw new RateException('kraken gave an unexpected answer');
                }
                $last = Json::get(array_values($entries)[0], 'c');
                if (!\is_array($last) || !array_is_list($last)) {
                    throw new RateException('kraken gave an unexpected answer');
                }

                return [self::priceValue($last[0] ?? null, \array_key_exists(0, $last), $name, $currency), null];
        }
        throw new \LogicException("No rate source named {$name}");
    }

    /**
     * A source's JSON object: NetworkException without an answer, for a redirect (HttpGet) and for
     * a status from 500 to 599, 408 or 429 (SPEC.md §26: try again); RateException for any other
     * status, 600 and above included, and for anything else.
     *
     * @param array<string, string> $headers
     */
    private static function json(Context $context, string $name, string $url, #[\SensitiveParameter] array $headers = []): \stdClass
    {
        $response = HttpGet::get($context, $url, $headers);
        $status = $response->status;
        if ($status < 200 || $status >= 300) {
            if (($status >= 500 && $status <= 599) || $status === 408 || $status === 429) {
                throw new NetworkException("{$name} answered HTTP {$status}");
            }
            throw new RateException("{$name} answered HTTP {$status}");
        }
        $body = HttpGet::json($response);
        if (!$body instanceof \stdClass) {
            throw new RateException("{$name} gave no JSON object");
        }

        return $body;
    }

    private static function object(\stdClass $parent, string $member, string $name): \stdClass
    {
        $value = Json::get($parent, $member);
        if (!$value instanceof \stdClass) {
            throw new RateException("{$name} gave an unexpected answer");
        }

        return $value;
    }

    /** The price at $member: absent is unsupported_currency; neither a string nor a number, rate. */
    private static function price(\stdClass $parent, string $member, string $name, string $currency): ?Decimal
    {
        return self::priceValue(Json::get($parent, $member), Json::has($parent, $member), $name, $currency);
    }

    private static function priceValue(mixed $value, bool $present, string $name, string $currency): ?Decimal
    {
        if (!$present) {
            throw new UnsupportedCurrencyException($currency, "{$name} has no price in {$currency}");
        }
        if (\is_string($value)) {
            return Decimal::fromText($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return Decimal::fromNumber($value);
        }
        throw new RateException("{$name} gave an unexpected price");
    }

    private static function isSource(mixed $value): bool
    {
        return (\is_string($value) && \in_array($value, self::NAMES, true)) || $value instanceof RateSource || $value instanceof \Closure;
    }
}
