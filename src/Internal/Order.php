<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Fiat;
use Sattleme\Checkout\RateSource;

/**
 * An order, checked before any request (SPEC.md §17, "The order"): refused, never repaired.
 *
 * @internal
 */
final class Order
{
    /** @var list<string> the order's parameters: any other key is refused (a typo would be ignored) */
    private const KEYS = ['ref', 'sats', 'fiat', 'rateSource', 'durationSeconds', 'note', 'checkout', 'redirectUrl'];

    /**
     * @param numeric-string|null                   $sats        the amount in sats, or null for a price in a currency
     * @param list<string|RateSource|\Closure>|null $rateSources with a price in a currency
     */
    private function __construct(
        public readonly string $ref,
        public readonly ?string $sats,
        public readonly ?Fiat $fiat,
        public readonly ?array $rateSources,
        public readonly int $durationSeconds,
        public readonly string $label,
        public readonly bool $checkout,
        public readonly ?string $redirectUrl,
    ) {}

    /**
     * @param list<string|RateSource|\Closure> $clientSources what an order in a currency without its own asks
     *
     * @throws InvalidOrderException
     */
    public static function check(mixed $order, array $clientSources): self
    {
        if (!\is_array($order) || (array_is_list($order) && $order !== [])) {
            throw new InvalidOrderException('order', "An order is an array: ['ref' => …, 'sats' => … or 'fiat' => …]");
        }
        foreach (array_keys($order) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw new InvalidOrderException('order', 'Unknown order parameter: ' . (\is_string($key) ? $key : (string) $key));
            }
        }
        $ref = self::ref($order['ref'] ?? null);
        $hasSats = \array_key_exists('sats', $order);
        $hasFiat = \array_key_exists('fiat', $order);
        if ($hasSats === $hasFiat) {
            throw new InvalidOrderException('sats', 'An order gives either sats or fiat');
        }
        $sats = $hasSats ? self::sats($order['sats']) : null;
        $fiat = $hasFiat ? Pricing::fiat($order['fiat']) : null;
        $sources = null;
        if (\array_key_exists('rateSource', $order)) {
            if ($fiat === null) {
                throw new InvalidOrderException('rateSource', 'rateSource is only for an order priced in a currency (fiat)');
            }
            $sources = Rates::sources($order['rateSource']);
            if ($sources === null) {
                throw new InvalidOrderException('rateSource', self::RATE_SOURCE_RULE);
            }
        } elseif ($fiat !== null) {
            $sources = $clientSources;
        }
        $duration = $order['durationSeconds'] ?? Protocol::DEFAULT_DURATION;
        if (!\is_int($duration) || $duration < Protocol::MIN_DURATION || $duration > Protocol::MAX_DURATION) {
            throw new InvalidOrderException('durationSeconds', 'durationSeconds: an integer, 60 to 3600');
        }
        $label = self::label($order['note'] ?? null, $ref);
        $checkout = \array_key_exists('checkout', $order) ? $order['checkout'] : false;
        if (!\is_bool($checkout)) {
            throw new InvalidOrderException('checkout', 'checkout: true or false');
        }
        $redirectUrl = $order['redirectUrl'] ?? null;
        if ($redirectUrl !== null) {
            if (!$checkout) {
                throw new InvalidOrderException('redirectUrl', 'redirectUrl: only with checkout true');
            }
            if (!RedirectUrl::isValid($redirectUrl)) {
                throw new InvalidOrderException('redirectUrl', "redirectUrl: an https URL (a lowercase host, no user name nor password) or an app's link (scheme://…), 2048 characters at most, without spaces nor other characters to escape");
            }
            \assert(\is_string($redirectUrl));
        }

        return new self($ref, $sats, $fiat, $sources, $duration, $label, $checkout, $redirectUrl);
    }

    public const RATE_SOURCE_RULE = 'rateSource: "coingecko", "mempool", "opennode", "coinbase", "kraken", a RateSource or a Closure, or a non-empty list of them without a name twice';

    /** A reference: 1 to 64 characters A–Z a–z 0–9 . _ : - */
    public static function isRef(mixed $ref): bool
    {
        return \is_string($ref) && preg_match('/^[A-Za-z0-9._:\-]{1,64}$/D', $ref) === 1;
    }

    /** @throws InvalidOrderException */
    public static function ref(mixed $ref): string
    {
        if (!self::isRef($ref)) {
            throw new InvalidOrderException('ref', 'ref: 1 to 64 characters A–Z a–z 0–9 . _ : -');
        }
        \assert(\is_string($ref));

        return $ref;
    }

    /**
     * The label (SPEC.md §17): the note without the spaces (U+0020) around it, else the reference;
     * no control character, 100 code points at most.
     *
     * @throws InvalidOrderException
     */
    public static function label(mixed $note, string $ref): string
    {
        if ($note === null) {
            return $ref;
        }
        if (!\is_string($note) || !Text::isUtf8($note)) {
            throw new InvalidOrderException('note', 'note: a string of UTF-8');
        }
        if (Text::hasControl($note)) {
            throw new InvalidOrderException('note', 'note: no control characters');
        }
        $label = Text::trimSpaces($note);
        if ($label === '') {
            return $ref;
        }
        if (Text::codePoints($label) > Protocol::MAX_NOTE) {
            throw new InvalidOrderException('note', 'note: 100 characters at most');
        }

        return $label;
    }

    /**
     * Sats: a string of 1 to 18 digits or an integer, from 1 to 2 100 000 000 000 000.
     *
     * @return numeric-string
     *
     * @throws InvalidOrderException
     */
    private static function sats(mixed $value): string
    {
        $sats = null;
        if (\is_int($value)) {
            $sats = (string) $value;
        } elseif (\is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value) === 1) {
            $sats = ltrim($value, '0');
            $sats = $sats === '' ? '0' : $sats;
        }
        if ($sats === null || !is_numeric($sats) || BigInt::cmp($sats, '1') < 0 || BigInt::cmp($sats, (string) Protocol::MAX_SATS) > 0) {
            throw new InvalidOrderException('sats', 'sats: a whole number of sats, from 1 to 2100000000000000');
        }

        return $sats;
    }
}
