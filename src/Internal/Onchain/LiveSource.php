<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Loop\Parallel;
use Sattleme\Checkout\Internal\Nostr\RelayPool;

/**
 * The on-chain part's source (SPEC.md §31): the call's explorer (mempool.space, or the merchant's
 * Bitcoin node with its fallback) over HTTP, the witnesses and the merchant's acceptance on the
 * merchant's relays, BlockCypher.
 *
 * @internal
 */
final class LiveSource implements OnchainSource
{
    private readonly WitnessKeys $keys;

    /**
     * @param list<string> $relays the merchant's
     */
    public function __construct(
        private readonly Context $context,
        private readonly RelayPool $pool,
        private readonly IntegrationKey $key,
        private readonly array $relays,
        private readonly Explorer $explorer,
    ) {
        $this->keys = WitnessKeys::of($key);
    }

    public function explorer(string $address): array
    {
        // One read, of one explorer: a transaction's confirmations compare its block's height with
        // that explorer's height. Either failing, both are asked of the next one (SPEC.md §31).
        [$transactions, $height] = $this->explorer->read(static fn(Esplora $explorer): array => Parallel::all([
            static fn(): array => $explorer->transactions($address),
            static fn(): int => $explorer->height(),
        ]));
        \assert(\is_array($transactions) && \is_int($height));

        /** @var list<Transaction> $transactions */
        return [$transactions, $height];
    }

    public function witnesses(string $address, string $sale, int $expiresAt, bool $again = false): WitnessSet
    {
        return WitnessSet::read($this->pool, $this->context, $this->keys, $this->relays, $address, $sale, $expiresAt, $again);
    }

    public function merchantAcceptance(string $address, string $sale, string $sats, int $expiresAt): MerchantAcceptance
    {
        return MerchantAcceptance::read($this->pool, $this->context, MerchantAcceptanceKeys::of($this->key, $sale, $address, $expiresAt), $this->relays, $sats, $expiresAt);
    }

    public function publish(string $address, string $txid, ?string $sale, string $sats, array $inputs, bool $replacement, ?int $expiresAt): bool
    {
        $event = Witness::event($this->keys, $address, $txid, $sale, (int) $sats, $inputs, $replacement, $expiresAt, $this->context->now(), $this->context);
        $keys = $this->keys;

        return $this->pool->publish($this->relays, $event, static fn(): string => $keys->seenSecret->reveal());
    }

    public function received(string $txid): ?int
    {
        return BlockCypher::received($this->context, $txid);
    }

}
