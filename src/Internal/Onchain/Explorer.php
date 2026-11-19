<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Protocol;

/**
 * The explorer of a call (SPEC.md §31, "Explorers", "The merchant's Bitcoin node"): mempool.space,
 * or the merchant's Bitcoin node the channel read by the call names, with its `fallback`. The only
 * one asked what the blockchain shows: a payment counts on what it alone shows. Each read follows
 * the fallback on its own: with `"mempool"`, mempool.space when the node fails it; with `"none"`,
 * never mempool.space. A node that fails is remembered in the call's NodeFailures, so that for
 * 60 s it is asked after mempool.space. BlockCypher and the rate source `mempool` are not asked
 * through it.
 *
 * @internal
 */
final class Explorer
{
    public const NODE = 'node';
    public const MEMPOOL = 'mempool';

    private readonly ?NodeUrl $node;

    /** @var BitcoinNode::MEMPOOL|BitcoinNode::NONE|null */
    private readonly ?string $fallback;

    public function __construct(
        private readonly Context $context,
        ?BitcoinNode $node,
        private readonly NodeFailures $failures,
    ) {
        $this->node = $node?->url;
        $this->fallback = $node?->fallback;
    }

    /** The explorer of a call whose channel names no node: mempool.space. */
    public static function mempool(Context $context): self
    {
        return new self($context, null, new NodeFailures());
    }

    /**
     * The explorers one read asks, in order (SPEC.md §31, `fallback`): without a node,
     * mempool.space; with `"none"`, the node alone; with `"mempool"`, the node then mempool.space,
     * or mempool.space first when the node failed a read less than 60 s ago in the same call. The
     * next one is asked only when the one before failed.
     *
     * @param BitcoinNode::MEMPOOL|BitcoinNode::NONE|null $fallback null without a node
     *
     * @return list<self::NODE|self::MEMPOOL>
     */
    public static function toAsk(?string $fallback, bool $failedRecently): array
    {
        if ($fallback === null) {
            return [self::MEMPOOL];
        }
        if ($fallback === BitcoinNode::NONE) {
            return [self::NODE];
        }

        return $failedRecently ? [self::MEMPOOL, self::NODE] : [self::NODE, self::MEMPOOL];
    }

    /**
     * The explorers the next read asks, in order (toAsk()): each its name, its REST URL (the
     * `<explorer>`) and its WebSocket's.
     *
     * @return list<array{name: self::NODE|self::MEMPOOL, rest: string, webSocket: string}>
     */
    public function sources(): array
    {
        $node = $this->node;
        $recently = $node !== null && $this->failures->recently($node->url, $this->context->now());
        $sources = [];
        foreach (self::toAsk($node === null ? null : $this->fallback, $recently) as $name) {
            $sources[] = $name === self::NODE && $node !== null
                ? ['name' => self::NODE, 'rest' => $node->rest, 'webSocket' => $node->webSocket]
                : ['name' => self::MEMPOOL, 'rest' => Protocol::MEMPOOL, 'webSocket' => Protocol::MEMPOOL_WEBSOCKET];
        }

        return $sources;
    }

    /** The node failed a read (or its WebSocket a connection): remembered for the call. */
    public function nodeFailed(): void
    {
        if ($this->node !== null) {
            $this->failures->failed($this->node->url, $this->context->now());
        }
    }

    /**
     * What tells apart the explorers of two channels: the node's URL and fallback, or none.
     */
    public function key(): string
    {
        return $this->node === null ? self::MEMPOOL : $this->fallback . ' ' . $this->node->url;
    }

    /**
     * One read of the explorer: $run with the Esplora API of each explorer of sources(), in order,
     * until one answers. What $run asks of one explorer at once (the chain's height with the
     * address's transactions) is asked again of the next one as a whole when any of it failed,
     * never in part: one read gives the answers of one explorer. A cancellation is thrown as it is.
     *
     * @template T
     *
     * @param \Closure(Esplora): T $run
     *
     * @return T
     *
     * @throws NetworkException when every explorer asked failed
     */
    public function read(\Closure $run): mixed
    {
        $failures = [];
        foreach ($this->sources() as $source) {
            try {
                return $run(new Esplora($this->context, $source['rest']));
            } catch (NetworkException $e) {
                if ($source['name'] === self::NODE) {
                    $this->nodeFailed();
                }
                $failures[] = $e;
            }
        }
        if (\count($failures) === 1) {
            throw $failures[0];
        }
        \assert($failures !== []);

        throw new NetworkException(
            'Neither the merchant\'s Bitcoin node nor mempool.space answered ('
                . implode('; ', array_map(static fn(NetworkException $e): string => $e->getMessage(), $failures)) . ')',
            $failures[\count($failures) - 1],
        );
    }
}
