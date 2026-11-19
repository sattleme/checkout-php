<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Protocol;

/**
 * When the nodes a call read failed a read, by node URL (SPEC.md §31, `fallback`: "in the same
 * call"): of one call alone (createInvoice, getStatus, one waitForPayment with its WebSocket),
 * never kept between calls. By the client's clock, in seconds.
 *
 * @internal
 */
final class NodeFailures
{
    /** @var array<string, int> by node URL: when it last failed (Unix seconds) */
    private array $at = [];

    /** The node at $url failed a read (or its WebSocket a connection) at $now. */
    public function failed(string $url, int $now): void
    {
        $this->at[$url] = $now;
    }

    /** Whether the node at $url failed a read less than 60 s before $now. */
    public function recently(string $url, int $now): bool
    {
        return isset($this->at[$url]) && $now - $this->at[$url] < Protocol::NODE_FAILED_RECENTLY_SECONDS;
    }
}
