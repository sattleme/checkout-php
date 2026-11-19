<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Bech32;
use Sattleme\Checkout\Internal\BigInt;

/**
 * Deposit addresses (SPEC.md §31): taproot addresses of Bitcoin's main network, as the merchant's
 * wallet hands them out, and the payment URIs that show them.
 *
 * @internal
 */
final class DepositAddress
{
    /**
     * A text of a deposit address's length ("bc1p" and 58 more characters), no address: what a
     * lease holds is sized with it before its address is leased.
     */
    public const SIZED = 'bc1p' . 'qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

    private function __construct() {}

    /**
     * Whether $value is a deposit address: the bech32m (BIP-350) encoding of "bc", witness version 1
     * and a 32-byte program, in lowercase: "bc1p" and 58 more characters.
     */
    public static function isValid(mixed $value): bool
    {
        if (!\is_string($value) || \strlen($value) !== 62 || preg_match('/^bc1p[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{58}$/D', $value) !== 1) {
            return false;
        }
        $decoded = Bech32::decode($value, Bech32::BECH32M);
        if ($decoded === null || $decoded[0] !== 'bc' || ($decoded[1][0] ?? null) !== 1) {
            return false;
        }
        $program = Bech32::toBytes(\array_slice($decoded[1], 1), true);

        return $program !== null && \strlen($program) === 32;
    }

    /**
     * $sats in bitcoins, exact: no trailing zero in the fraction, no fraction when whole.
     *
     * @param numeric-string $sats
     */
    public static function btc(string $sats): string
    {
        $padded = str_pad($sats, 9, '0', \STR_PAD_LEFT);
        $whole = ltrim(substr($padded, 0, -8), '0');
        $fraction = rtrim(substr($padded, -8), '0');

        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    /**
     * What to show as one QR code (SPEC.md §31, "Payment URIs"): with an address,
     * bitcoin:<address>?amount=<BTC>[&lightning=<pr>]; without one, lightning:<pr>.
     *
     * @param numeric-string $sats
     */
    public static function paymentUri(?string $address, string $sats, ?string $bolt11): string
    {
        if ($address === null) {
            if ($bolt11 === null) {
                throw new \LogicException('Neither an address nor an invoice');
            }

            return 'lightning:' . $bolt11;
        }

        return 'bitcoin:' . $address . '?amount=' . self::btc($sats) . ($bolt11 !== null ? '&lightning=' . $bolt11 : '');
    }

    /**
     * Whether $sats can be paid on-chain: the on-chain floor (1 000 sats) or more.
     *
     * @param numeric-string $sats
     */
    public static function allowsOnchain(string $sats): bool
    {
        return BigInt::cmp($sats, '1000') >= 0;
    }
}
