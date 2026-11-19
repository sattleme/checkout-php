<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Protocol;

/**
 * A transaction as an Esplora explorer writes it (SPEC.md §31, "Explorers"), checked: its txid,
 * the coins it spends, its outputs, and whether and where it is confirmed.
 *
 * @internal
 */
final class Transaction
{
    /**
     * @param list<string>                         $coins   `<txid>:<vout>` of each input that is not a coinbase, in order
     * @param list<array{0: int, 1: string|null}> $outputs value (sats) and address of each output
     */
    private function __construct(
        public readonly string $txid,
        public readonly array $coins,
        public readonly array $outputs,
        public readonly bool $confirmed,
        public readonly ?int $blockHeight,
    ) {}

    /**
     * $value as a transaction: an object with `txid` (64 lowercase hex characters), `vin` (each
     * `is_coinbase` true, or a `txid` and a `vout` from 0 to 4 294 967 295), `vout` (each a
     * non-negative safe integer `value`, all together at most 2 100 000 000 000 000 sats: every
     * bitcoin there will ever be, a transaction paying more is none; a string
     * `scriptpubkey_address` when present) and `status` (`confirmed` a boolean, then a
     * non-negative safe integer `block_height`); else null.
     */
    public static function fromJson(mixed $value): ?self
    {
        $txid = Json::get($value, 'txid');
        $inputs = Json::get($value, 'vin');
        $outputs = Json::get($value, 'vout');
        $status = Json::get($value, 'status');
        if (!$value instanceof \stdClass || !Witness::isTxid($txid) || !\is_array($inputs) || !array_is_list($inputs)
            || !\is_array($outputs) || !array_is_list($outputs) || !$status instanceof \stdClass) {
            return null;
        }
        \assert(\is_string($txid));
        $coins = [];
        foreach ($inputs as $input) {
            if (!$input instanceof \stdClass) {
                return null;
            }
            if (Json::get($input, 'is_coinbase') === true) {
                continue;
            }
            $spent = Json::get($input, 'txid');
            $vout = Json::intIn(Json::get($input, 'vout'), 0, 4294967295);
            if (!Witness::isTxid($spent) || $vout === null) {
                return null;
            }
            \assert(\is_string($spent));
            $coins[] = $spent . ':' . $vout;
        }
        $outs = [];
        $total = 0;
        foreach ($outputs as $output) {
            if (!$output instanceof \stdClass) {
                return null;
            }
            $sats = Json::intIn(Json::get($output, 'value'), 0, Protocol::MAX_SAFE_INTEGER);
            // Absent, or a string (SPEC.md §31): null is neither.
            $address = Json::get($output, 'scriptpubkey_address');
            if ($sats === null || (Json::has($output, 'scriptpubkey_address') && !\is_string($address))) {
                return null;
            }
            // Below 2^53 each, at most every bitcoin before: the sum never overflows.
            $total += $sats;
            if ($total > Protocol::MAX_SATS) {
                return null;
            }
            \assert($address === null || \is_string($address));
            $outs[] = [$sats, $address];
        }
        $confirmed = Json::get($status, 'confirmed');
        if (!\is_bool($confirmed)) {
            return null;
        }
        $height = null;
        if ($confirmed) {
            $height = Json::intIn(Json::get($status, 'block_height'), 0, Protocol::MAX_SAFE_INTEGER);
            if ($height === null) {
                return null;
            }
        }

        return new self($txid, $coins, $outs, $confirmed, $height);
    }

    /**
     * What it pays $address: the sum of its outputs to it.
     *
     * @return numeric-string
     */
    public function pays(string $address): string
    {
        $sum = '0';
        foreach ($this->outputs as [$sats, $to]) {
            if ($to === $address) {
                $sum = BigInt::add($sum, (string) $sats);
            }
        }

        return $sum;
    }

    /** Its confirmations at height $height: 0 when not confirmed, else height − block_height + 1, 1 at least. */
    public function confirmations(int $height): int
    {
        if (!$this->confirmed || $this->blockHeight === null) {
            return 0;
        }

        return max(1, $height - $this->blockHeight + 1);
    }
}
