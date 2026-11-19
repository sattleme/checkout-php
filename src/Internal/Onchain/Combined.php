<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Rest;

/**
 * The status of an invoice with an address (SPEC.md §31, after "The on-chain payment"): its
 * Lightning part, its on-chain part and its rest part, combined.
 *
 * @internal
 */
final class Combined
{
    private function __construct() {}

    /**
     * Whether the Lightning part paid the invoice: its status is then decided, and the on-chain
     * part is not judged.
     *
     * @param array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning
     */
    public static function paidByLightning(array|SattlemeException|null $lightning): bool
    {
        return \is_array($lightning) && $lightning[0] === 'paid';
    }

    /**
     * The on-chain part once every part answered (SPEC.md §31, step 4 of "The on-chain payment"):
     * nothing when the Lightning part paid the invoice (null: nothing more is asked), its error
     * when it failed, else what counts in $observed against what is due, its tolerance, the
     * merchant's acceptance $observed read and what the rest invoices paid.
     *
     * @param numeric-string                                                                              $sats
     * @param array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning
     * @param numeric-string                                                                              $paidByRests
     */
    public static function judge(OnchainPart|SattlemeException $observed, array|SattlemeException|null $lightning, string $sats, #[\SensitiveParameter] int $tolerance, string $paidByRests, int $expiresAt): OnchainResult|SattlemeException|null
    {
        if (self::paidByLightning($lightning)) {
            return null;
        }

        return $observed instanceof OnchainPart ? $observed->judge($sats, $tolerance, $paidByRests, $expiresAt) : $observed;
    }

    /**
     * @param numeric-string                                                                              $sats
     * @param array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning null without a Lightning invoice
     * @param OnchainResult|SattlemeException|null                                                       $onchain   null when not judged (judge())
     *
     * @throws SattlemeException the error of a part, when no part pays nor is being confirmed
     */
    public static function status(string $sats, int $expiresAt, string $address, int $now, array|SattlemeException|null $lightning, OnchainResult|SattlemeException|null $onchain, RestPart $rests): PaymentStatus
    {
        // 1. Its Lightning invoice paid.
        if (\is_array($lightning) && $lightning[0] === 'paid') {
            return PaymentStatus::paidByLightning($lightning[1]);
        }
        $onchain ??= new NetworkException('The on-chain part was not checked');
        $paidByRests = $rests->paid();
        $received = BigInt::add($onchain instanceof OnchainResult ? $onchain->received : '0', $paidByRests);
        $preimages = $rests->preimages();
        // 2. Its rest invoices alone pay it.
        if (BigInt::cmp($paidByRests, $sats) >= 0) {
            return PaymentStatus::paidMixed($received, [], $preimages);
        }
        if ($onchain instanceof OnchainResult) {
            // 3. Its on-chain part paid.
            if ($onchain->state === OnchainResult::PAID) {
                return $preimages === [] ? PaymentStatus::paidOnchain($received, $onchain->txids) : PaymentStatus::paidMixed($received, $onchain->txids, $preimages);
            }
            // 4. Its on-chain part being confirmed.
            if ($onchain->state === OnchainResult::UNCONFIRMED) {
                return PaymentStatus::unconfirmed($received, $onchain->confirmations, $onchain->txids);
            }
        }
        // 5. An error of a part: the Lightning part's, the rest part's, the on-chain part's.
        if ($lightning instanceof SattlemeException) {
            throw $lightning;
        }
        $error = $rests->error();
        if ($error !== null) {
            throw $error;
        }
        if ($onchain instanceof SattlemeException) {
            throw $onchain;
        }
        // 6. Expired when every part is final, else open.
        $lightningFinal = $lightning === null || $lightning[0] === 'expired';
        if ($lightningFinal && $onchain->state === OnchainResult::UNDERPAID && $onchain->final && $rests->isFinal()) {
            return PaymentStatus::expired($received);
        }

        return PaymentStatus::open($expiresAt, $received, self::rest($sats, $expiresAt, $address, $now, $received, $rests));
    }

    /**
     * The status's `rest` (SPEC.md §31): when `received` is above 0 before the invoice's end, what
     * is left, the rest invoice of exactly that, and the payment URI.
     *
     * @param numeric-string $sats
     * @param numeric-string $received
     */
    public static function rest(string $sats, int $expiresAt, string $address, int $now, string $received, RestPart $rests): ?Rest
    {
        if (BigInt::cmp($received, '0') <= 0 || $now >= $expiresAt || BigInt::cmp($received, $sats) >= 0) {
            return null;
        }
        $left = BigInt::sub($sats, $received);
        $bolt11 = $rests->invoiceOf($left, $now)?->bolt11;
        if (DepositAddress::allowsOnchain($left)) {
            $uri = DepositAddress::paymentUri($address, $left, $bolt11);
        } else {
            $uri = $bolt11 === null ? null : 'lightning:' . $bolt11;
        }

        return new Rest($left, $bolt11, $uri);
    }
}
