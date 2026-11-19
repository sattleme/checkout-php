<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Loop\Parallel;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Tolerance;

/**
 * The on-chain part of an invoice with an address A, for its order's sale S (SPEC.md §31, "The
 * on-chain payment"): steps 1 to 3 (observe: the transactions, their parts, which came in time,
 * the witnesses published, the merchant's acceptance read), then step 4 (judge: what counts
 * against what is due).
 *
 * @internal
 */
final class OnchainPart
{
    public const IN_TIME = 'in time';
    public const REPLACEMENT = 'replacement';
    public const LATE = 'late';
    public const UNDECIDED = 'undecided';
    /** Another invoice's: a witness of another invoice names it, or the root of one of its parts. */
    public const ANOTHERS = "another's";

    /**
     * @param list<Transaction>             $listed   the transactions paying A, in the explorer's order
     * @param array<string, numeric-string> $counts   what counts of each
     * @param array<string, string>         $verdicts by txid
     * @param int                           $now      when it was checked: once the explorer answered
     * @param numeric-string|null           $merchantAccepted the `received` of the merchant's acceptance that counts, null without one
     */
    private function __construct(
        private readonly int $height,
        private readonly array $listed,
        private readonly array $counts,
        public readonly array $verdicts,
        public readonly int $now,
        public readonly ?string $merchantAccepted,
    ) {}

    /**
     * Steps 1 to 3 (the lease ends at expiresAt + 120), checked at `now`: the server's clock read
     * once the explorer answered step 1, never before (SPEC.md §31: what it lists was broadcast by
     * then; read before, a payment broadcast after the lease's end while the explorer answered
     * would count as in time). The merchant's acceptance of the invoice (of $sats) is read with the
     * witnesses, when some transaction pays A; a check publishes none.
     *
     * @param numeric-string $sats
     *
     * @throws NetworkException a failure of the explorer, of the relays or of a sale's witness's publication
     */
    public static function observe(OnchainSource $source, Context $context, string $address, int $expiresAt, string $sale, string $sats): self
    {
        $end = $expiresAt + Protocol::GRACE_SECONDS;

        // 1. The transactions paying A (a txid once), the chain's height; then the witnesses, and
        // the merchant's acceptance at the same time, on every relay.
        [$transactions, $height] = $source->explorer($address);
        $now = $context->now();
        $listed = [];
        $paying = [];
        foreach ($transactions as $transaction) {
            // At most every bitcoin: a transaction whose outputs hold more is none (Transaction).
            $pays = $transaction->pays($address);
            if (BigInt::cmp($pays, '0') > 0 && !isset($paying[$transaction->txid])) {
                $listed[] = $transaction;
                $paying[$transaction->txid] = $pays;
            }
        }
        if ($listed === []) {
            return new self($height, [], [], [], $now, null);
        }
        [$witnesses, $accepted] = Parallel::all([
            static fn(): WitnessSet => $source->witnesses($address, $sale, $expiresAt),
            static fn(): MerchantAcceptance => $source->merchantAcceptance($address, $sale, $sats, $expiresAt),
        ]);
        \assert($witnesses instanceof WitnessSet && $accepted instanceof MerchantAcceptance);
        if ($witnesses->newer !== []) {
            // Never used nor written over (SPEC.md §12): the SDK is to be updated.
            $context->warn('A witness of this address has a newer format than this version of sattleme/checkout: update it');
        }
        if ($accepted->newer) {
            // Whatever its date and however many relays sent it (SPEC.md §31, "Reading it").
            $context->warn('A merchant\'s acceptance of this order has a newer format than this version of sattleme/checkout: update it');
        }

        // 2. The parts: from the witnesses of this invoice and of other invoices (a replacement of
        // another invoice's payment stands for it), and the transactions no such witness names.
        $parts = self::parts($witnesses, $listed, $paying);
        $txids = array_keys($paying);
        $txids = array_map('strval', $txids);

        // 3. In time, a replacement, late, another's or undecided: each in the explorer's order.
        $verdicts = [];
        $published = false;
        foreach ($listed as $transaction) {
            $txid = $transaction->txid;
            // 3.1 Another invoice's: a transaction counts for one invoice at most. Nothing is
            // published nor asked for it.
            if (self::isAnothers($witnesses, $parts, $txid)) {
                $verdicts[$txid] = self::ANOTHERS;
                continue;
            }
            $witness = $witnesses->ofSale[$txid] ?? null;
            if ($witness !== null && !$witness['replacement']) {
                $verdicts[$txid] = self::IN_TIME;
                continue;
            }
            if ($witnesses->newerAt($txid, $sale)) {
                $verdicts[$txid] = self::UNDECIDED;
                continue;
            }
            if ($now <= $end) {
                self::publishOfSale($source, $address, $transaction, $paying[$txid], $sale, false, $expiresAt);
                $published = true;
                $verdicts[$txid] = self::IN_TIME;
                continue;
            }
            if ($witness !== null) {
                $verdicts[$txid] = self::REPLACEMENT;
                continue;
            }
            $own = false;
            $others = false;
            foreach ($parts[$txid] ?? [] as [, $root]) {
                if ($root === $txid) {
                    $own = true;
                } else {
                    $others = true;
                }
            }
            if (!$own) {
                $verdict = self::REPLACEMENT;
            } else {
                $received = $source->received($txid);
                if ($received !== null && $received <= $end * 1000) {
                    self::publishOfSale($source, $address, $transaction, $paying[$txid], $sale, false, $expiresAt);
                    $published = true;
                    $verdicts[$txid] = self::IN_TIME;
                    continue;
                }
                $verdict = $others ? self::REPLACEMENT : self::LATE;
            }
            if ($witnesses->newerNamesNoneOf($txids)) {
                $verdicts[$txid] = self::UNDECIDED;
                continue;
            }
            if ($verdict === self::REPLACEMENT) {
                self::publishOfSale($source, $address, $transaction, $paying[$txid], $sale, true, $expiresAt);
                $published = true;
            } elseif (!isset($witnesses->none[$txid]) && !$witnesses->newerAt($txid, null)
                && !$source->publish($address, $txid, null, $paying[$txid], $transaction->coins, false, null)) {
                // For the merchant's app only: it takes the address out of the lot. Not over a
                // witness of a newer format at the witness address of A, T and none: a newer
                // version found it late too, and it is never written over (SPEC.md §12).
                $context->warn('A witness of a late on-chain payment could not be published on a majority of the relays');
            }
            $verdicts[$txid] = $verdict;
        }
        // Its witnesses on a majority, read again on every relay: of two checks that publish
        // witnesses of one transaction at once for two sales, the later reader sees both, whatever
        // one relay hides; rule 3.1 again, the parts made again from them for it alone.
        if ($published) {
            $again = $source->witnesses($address, $sale, $expiresAt, true);
            $partsAgain = self::parts($again, $listed, $paying);
            foreach ($listed as $transaction) {
                if (self::isAnothers($again, $partsAgain, $transaction->txid)) {
                    $verdicts[$transaction->txid] = self::ANOTHERS;
                }
            }
        }

        // 4 (what counts): the parts of the roots in time, of transactions not undecided.
        $inTime = [];
        foreach ($verdicts as $txid => $verdict) {
            if ($verdict === self::IN_TIME) {
                $inTime[$txid] = true;
            }
        }
        foreach ($witnesses->ofSale as $txid => $witness) {
            if (!isset($paying[$txid]) && !$witness['replacement']) {
                $inTime[$txid] = true;
            }
        }
        $counts = [];
        foreach ($listed as $transaction) {
            $txid = $transaction->txid;
            $count = '0';
            if ($verdicts[$txid] !== self::UNDECIDED && $verdicts[$txid] !== self::ANOTHERS) {
                foreach ($parts[$txid] ?? [] as [$sats, $root]) {
                    if (isset($inTime[$root])) {
                        $count = BigInt::add($count, $sats);
                    }
                }
            }
            $counts[$txid] = $count;
        }

        return new self($height, $listed, $counts, $verdicts, $now, $accepted->received);
    }

    /** Whether a transaction paying A is undecided: the part is then never final. */
    public function hasUndecided(): bool
    {
        return \in_array(self::UNDECIDED, $this->verdicts, true);
    }

    /**
     * What counts: the sum of the parts whose root came in time.
     *
     * @return numeric-string
     */
    public function received(): string
    {
        $sum = '0';
        foreach ($this->counts as $count) {
            $sum = BigInt::add($sum, $count);
        }

        return $sum;
    }

    /**
     * Step 4, at the `now` of steps 1 to 3: the part, judged against what is due (the least
     * accepted of $sats with $tolerance, or the `received` of the merchant's acceptance a majority
     * of the relays sent when less, less what the rest invoices paid: Tolerance::due()); paid at 3
     * confirmations on the explorer read, which alone tells what the blockchain shows (SPEC.md
     * §31, "Explorers").
     *
     * @param numeric-string $sats
     * @param numeric-string $paidByRests
     */
    public function judge(string $sats, #[\SensitiveParameter] int $tolerance, string $paidByRests, int $expiresAt): OnchainResult
    {
        $received = $this->received();
        $due = Tolerance::due($sats, $tolerance, $paidByRests, $this->merchantAccepted);
        if ($due === '0') {
            return new OnchainResult(OnchainResult::NOTHING_DUE, $received);
        }
        if (BigInt::cmp($received, $due) < 0) {
            return new OnchainResult(OnchainResult::UNDERPAID, $received, $this->now > $expiresAt + Protocol::GRACE_SECONDS && !$this->hasUndecided());
        }
        $txids = [];
        $confirmations = null;
        foreach ($this->listed as $transaction) {
            if (BigInt::cmp($this->counts[$transaction->txid], '0') > 0) {
                $txids[] = $transaction->txid;
                $depth = $transaction->confirmations($this->height);
                $confirmations = $confirmations === null ? $depth : min($confirmations, $depth);
            }
        }
        \assert($confirmations !== null);
        if ($confirmations >= Protocol::CONFIRMATIONS) {
            return new OnchainResult(OnchainResult::PAID, $received, true, $confirmations, $txids);
        }

        return new OnchainResult(OnchainResult::UNCONFIRMED, $received, false, $confirmations, $txids);
    }

    /**
     * The parts of the transactions paying A (Parts): from the witnesses of this invoice and of
     * other invoices (each its txid, sats, inputs, its created_at as its order, listed when the
     * explorer lists it), then the transactions paying A no such witness names (their order
     * unknown).
     *
     * @param list<Transaction>             $listed
     * @param array<string, numeric-string> $paying by txid
     *
     * @return array<string, list<array{0: numeric-string, 1: string}>>
     */
    private static function parts(WitnessSet $witnesses, array $listed, array $paying): array
    {
        $entries = [];
        foreach ([$witnesses->ofSale, $witnesses->others] as $named) {
            foreach ($named as $txid => $witness) {
                $entries[] = ['txid' => $txid, 'sats' => $witness['sats'], 'inputs' => $witness['inputs'], 'order' => $witness['seenAt'], 'listed' => isset($paying[$txid])];
            }
        }
        foreach ($listed as $transaction) {
            if (!isset($witnesses->ofSale[$transaction->txid]) && !isset($witnesses->others[$transaction->txid])) {
                $entries[] = ['txid' => $transaction->txid, 'sats' => $paying[$transaction->txid], 'inputs' => $transaction->coins, 'order' => null, 'listed' => true];
            }
        }

        return Parts::of($entries);
    }

    /**
     * Whether $txid is another invoice's: a witness of another invoice names it, or one of its
     * parts is of a root one names.
     *
     * @param array<string, list<array{0: numeric-string, 1: string}>> $parts
     */
    private static function isAnothers(WitnessSet $witnesses, array $parts, string $txid): bool
    {
        if (isset($witnesses->others[$txid])) {
            return true;
        }
        foreach ($parts[$txid] ?? [] as [, $root]) {
            if (isset($witnesses->others[$root])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A witness of this invoice: on a majority, else `network` (it proves later that the
     * transaction came in time, or what it replaces).
     *
     * @param numeric-string $sats
     *
     * @throws NetworkException
     */
    private static function publishOfSale(OnchainSource $source, string $address, Transaction $transaction, string $sats, string $sale, bool $replacement, int $expiresAt): void
    {
        if (!$source->publish($address, $transaction->txid, $sale, $sats, $transaction->coins, $replacement, $expiresAt)) {
            throw new NetworkException('Fewer than a majority of the relays took the witness of an on-chain payment');
        }
    }
}
