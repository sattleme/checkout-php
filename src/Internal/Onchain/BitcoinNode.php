<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Json;

/**
 * The merchant's Bitcoin node (SPEC.md §31, "The merchant's Bitcoin node"): a node of its own
 * that serves mempool.space's API (an Umbrel, a server of its own), which the channel names
 * (`bitcoinNode`, SPEC.md §14), and which the server then reads the blockchain from, rather than
 * from mempool.space. Its URL is a node URL (NodeUrl); `fallback` says what a read does when the
 * node does not answer it; `payers`, whether the pages of checkout links read it too.
 *
 * @internal
 */
final class BitcoinNode
{
    /** The same read of mempool.space when the node does not answer. */
    public const MEMPOOL = 'mempool';

    /** Never mempool.space: while the node does not answer, the read fails. */
    public const NONE = 'none';

    /**
     * @param self::MEMPOOL|self::NONE $fallback
     */
    private function __construct(
        public readonly NodeUrl $url,
        public readonly string $fallback,
        public readonly bool $payers,
    ) {}

    /**
     * The channel's `bitcoinNode` (SPEC.md §14, §31): an object, not an array, whose `url` is a
     * node URL, whose `fallback` is exactly `"mempool"` or `"none"` and whose `payers` is a JSON
     * boolean, true only for a node of the Internet; its other members ignored. Null when it is
     * absent or invalid in any way: there is none, the server reads mempool.space, and the
     * channel stays valid. Nothing is repaired.
     */
    public static function of(mixed $value): ?self
    {
        if (!$value instanceof \stdClass) {
            return null;
        }
        $url = NodeUrl::parse(Json::get($value, 'url'));
        $fallback = self::fallback(Json::get($value, 'fallback'));
        $payers = Json::get($value, 'payers');
        if ($url === null || $fallback === null || !\is_bool($payers)) {
            return null;
        }
        // A link must never make each customer's browser probe its own local network.
        if ($payers && !$url->internet) {
            return null;
        }

        return new self($url, $fallback, $payers);
    }

    /**
     * What a checkout link's record of version 2 holds of the channel's node (SPEC.md §30): its
     * `url` and `fallback`, copied as they are, never `payers`; null (the field absent, never
     * `null`) when the channel names no node or its `payers` is false.
     *
     * @return array{url: string, fallback: self::MEMPOOL|self::NONE}|null
     */
    public static function forRecord(?self $node): ?array
    {
        return $node !== null && $node->payers ? ['url' => $node->url->url, 'fallback' => $node->fallback] : null;
    }

    /**
     * The node a page reads from a record of version 2's `bitcoinNode` (SPEC.md §30, "Reading a
     * record"): an object whose `url` is a node URL of a node of the Internet and whose
     * `fallback` is exactly `"mempool"` or `"none"` (its other members ignored); null for
     * anything else (`null` included), set aside: the page reads mempool.space, and the record
     * stays valid.
     *
     * @return array{url: string, fallback: self::MEMPOOL|self::NONE}|null
     */
    public static function ofRecord(mixed $value): ?array
    {
        if (!$value instanceof \stdClass) {
            return null;
        }
        $url = NodeUrl::parse(Json::get($value, 'url'));
        $fallback = self::fallback(Json::get($value, 'fallback'));
        if ($url === null || !$url->internet || $fallback === null) {
            return null;
        }

        return ['url' => $url->url, 'fallback' => $fallback];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['url' => $this->url->url, 'fallback' => $this->fallback, 'payers' => $this->payers];
    }

    /** @return self::MEMPOOL|self::NONE|null $value when it is exactly one of them */
    private static function fallback(mixed $value): ?string
    {
        return $value === self::MEMPOOL || $value === self::NONE ? $value : null;
    }
}
