<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Lightning;

use Sattleme\Checkout\Internal\Bech32;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Protocol;

/**
 * What the SDK reads of a BOLT11 invoice (SPEC.md §19): network, amount, timestamp, expiry,
 * payment hash, description hash. The signature is not verified: the payee is the LNURL server's
 * node, which nothing here can check; the description hash ties the invoice to the merchant.
 *
 * @internal
 */
final class Bolt11
{
    private const TIMESTAMP_WORDS = 7;
    private const SIGNATURE_WORDS = 104;
    private const HASH_WORDS = 52;
    private const TAG_PAYMENT_HASH = 1;
    private const TAG_DESCRIPTION_HASH = 23;
    private const TAG_EXPIRY = 6;
    /** Millisatoshis per unit of the amount, by multiplier (1 BTC = 10^11 msat). */
    private const MSAT_PER_UNIT = ['' => '100000000000', 'm' => '100000000', 'u' => '100000', 'n' => '100'];

    /**
     * @param numeric-string|null $amountMsat exact, null for an invoice without amount
     */
    private function __construct(
        public readonly string $network,
        public readonly ?string $amountMsat,
        public readonly int $timestamp,
        public readonly int $expirySeconds,
        public readonly string $paymentHash,
        public readonly ?string $descriptionHash,
    ) {}

    /** $invoice's fields, or null when it is no valid BOLT11 invoice. */
    public static function decode(mixed $invoice): ?self
    {
        if (!\is_string($invoice)) {
            return null;
        }
        $decoded = Bech32::decode($invoice, Bech32::BECH32);
        if ($decoded === null) {
            return null;
        }
        [$hrp, $words] = $decoded;
        if (preg_match('/^ln(bcrt|bc|tbs|tb)([0-9]+)?([munp])?$/D', $hrp, $match) !== 1) {
            return null;
        }
        $amount = self::amount($match[2] ?? '', $match[3] ?? '');
        if ($amount === false || \count($words) < self::TIMESTAMP_WORDS + self::SIGNATURE_WORDS) {
            return null;
        }
        $timestamp = Bech32::toInteger(\array_slice($words, 0, self::TIMESTAMP_WORDS));
        $fields = \array_slice($words, self::TIMESTAMP_WORDS, \count($words) - self::TIMESTAMP_WORDS - self::SIGNATURE_WORDS);
        $paymentHash = null;
        $descriptionHash = null;
        $expiry = null;
        $count = \count($fields);
        for ($i = 0; $i < $count;) {
            if ($i + 3 > $count) {
                return null;
            }
            $tag = $fields[$i];
            $length = $fields[$i + 1] * 32 + $fields[$i + 2];
            if ($i + 3 + $length > $count) {
                return null; // A field running past the signature.
            }
            $data = \array_slice($fields, $i + 3, $length);
            // p and h: only a field of 52 words counts, the first one; x: the last one.
            if ($tag === self::TAG_PAYMENT_HASH && $length === self::HASH_WORDS && $paymentHash === null) {
                $paymentHash = bin2hex(substr((string) Bech32::toBytes($data, false), 0, 32));
            } elseif ($tag === self::TAG_DESCRIPTION_HASH && $length === self::HASH_WORDS && $descriptionHash === null) {
                $descriptionHash = bin2hex(substr((string) Bech32::toBytes($data, false), 0, 32));
            } elseif ($tag === self::TAG_EXPIRY) {
                if ($length > Protocol::MAX_EXPIRY_FIELD_WORDS) {
                    return null;
                }
                $expiry = Bech32::toInteger($data);
            }
            $i += 3 + $length;
        }
        if ($paymentHash === null) {
            return null;
        }

        return new self($match[1], $amount, $timestamp, $expiry ?? Protocol::DEFAULT_BOLT11_EXPIRY, $paymentHash, $descriptionHash);
    }

    /** The end: timestamp + expiry. */
    public function expiresAt(): int
    {
        return $this->timestamp + $this->expirySeconds;
    }

    /**
     * The amount in millisatoshis: null without digits, false when invalid.
     *
     * @return numeric-string|null|false
     */
    private static function amount(string $digits, string $multiplier): string|false|null
    {
        if ($digits === '') {
            return $multiplier === '' ? null : false;
        }
        // A leading zero, or longer than any amount up to 21 million bitcoins.
        if (($digits[0] === '0' && \strlen($digits) > 1) || \strlen($digits) > 22) {
            return false;
        }
        \assert(is_numeric($digits));
        if ($multiplier === 'p') {
            if (substr($digits, -1) !== '0') {
                return false; // A pico-bitcoin is a tenth of a millisatoshi.
            }
            $msat = BigInt::div($digits, '10');
        } else {
            $msat = BigInt::mul($digits, self::MSAT_PER_UNIT[$multiplier]);
        }

        return BigInt::cmp($msat, (string) Protocol::MAX_MSAT) > 0 ? false : $msat;
    }
}
