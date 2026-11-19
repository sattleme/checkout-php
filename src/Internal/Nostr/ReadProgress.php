<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

/**
 * What one relay's complete read sent so far (RelayPool::readComplete()): a read on every relay
 * that stops while the relay is still reading keeps it.
 *
 * @internal
 */
final class ReadProgress
{
    /** @var list<\stdClass> the events the caller kept, once per id */
    public array $events = [];

    /** Whether its first page came: the relay answered. */
    public bool $answered = false;
}
