<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\BigInt;

/**
 * The parts of transactions paying an address (SPEC.md §31, "Replacements"): a replacement (RBF)
 * counts at the time of the payment it replaces, for at most what that payment paid; what it pays
 * more is a part of its own.
 *
 * @internal
 */
final class Parts
{
    private function __construct() {}

    /**
     * The parts of each listed transaction, in the order of step 1: by txid, a list of [sats,
     * root txid] (the root is the transaction itself for a part of its own).
     *
     * @param list<array{txid: string, sats: numeric-string, inputs: list<string>, order: int|null, listed: bool}> $transactions
     *
     * @return array<string, list<array{0: numeric-string, 1: string}>>
     */
    public static function of(array $transactions): array
    {
        // 1. A txid given twice is taken once, its first entry; by order (unknown last), then txid.
        $unique = [];
        foreach ($transactions as $transaction) {
            $unique[$transaction['txid']] ??= $transaction;
        }
        $sorted = array_values($unique);
        usort($sorted, static function (array $a, array $b): int {
            if ($a['order'] !== $b['order']) {
                if ($a['order'] === null) {
                    return 1;
                }
                if ($b['order'] === null) {
                    return -1;
                }

                return $a['order'] <=> $b['order'];
            }

            return strcmp($a['txid'], $b['txid']);
        });

        // 2. The roots of each: those of the transactions before it that spend one of its coins.
        $position = [];
        $roots = [];
        foreach ($sorted as $index => $transaction) {
            $position[$transaction['txid']] = $index;
            $coins = array_flip($transaction['inputs']);
            $found = [];
            for ($before = 0; $before < $index; ++$before) {
                $earlier = $sorted[$before];
                foreach ($earlier['inputs'] as $coin) {
                    if (isset($coins[$coin])) {
                        foreach ($roots[$earlier['txid']] as $root) {
                            $found[$root] = $position[$root];
                        }
                        break;
                    }
                }
            }
            asort($found);
            $roots[$transaction['txid']] = $found === [] ? [$transaction['txid']] : array_map('strval', array_keys($found));
        }

        // 3. Each root gives its sats once: to the listed transactions, in order.
        $toGive = [];
        foreach ($sorted as $transaction) {
            $toGive[$transaction['txid']] = $transaction['sats'];
        }
        $parts = [];
        foreach ($sorted as $transaction) {
            if (!$transaction['listed']) {
                continue;
            }
            $txid = $transaction['txid'];
            if ($roots[$txid] === [$txid]) {
                $parts[$txid] = [[$transaction['sats'], $txid]];
                $toGive[$txid] = '0';
                continue;
            }
            $pays = $transaction['sats'];
            $own = [];
            foreach ($roots[$txid] as $root) {
                $part = BigInt::cmp($pays, $toGive[$root]) <= 0 ? $pays : $toGive[$root];
                if (BigInt::cmp($part, '0') > 0) {
                    $own[] = [$part, $root];
                    $pays = BigInt::sub($pays, $part);
                    $toGive[$root] = BigInt::sub($toGive[$root], $part);
                }
            }
            if (BigInt::cmp($pays, '0') > 0) {
                $own[] = [$pays, $txid];
            }
            $parts[$txid] = $own;
        }

        return $parts;
    }
}
