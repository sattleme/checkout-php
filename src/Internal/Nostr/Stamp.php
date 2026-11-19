<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

/**
 * What a read keeps of an event that opens without its signature checked (a lease, SPEC.md §13,
 * Limits): its id, author, date and signature, never its tags nor its content, so that copies a
 * relay makes of it under ids of their own take a few bytes each. Whoever takes one has checked
 * that its id is its event's hash.
 *
 * @internal
 */
final class Stamp
{
    private function __construct(
        public readonly string $id,
        public readonly string $pubkey,
        public readonly int $createdAt,
        public readonly string $sig,
    ) {}

    /** $event's stamp: its id, author, date and signature (its id checked by the caller). */
    public static function of(Event $event): self
    {
        return new self($event->id, $event->pubkey, $event->createdAt, $event->sig);
    }

    /** The same event with the signature $sig (another relay's copy of it). */
    public function withSig(string $sig): self
    {
        return new self($this->id, $this->pubkey, $this->createdAt, $sig);
    }

    /** Whether its signature checks out (its id, its event's hash, was checked already). */
    public function verify(): bool
    {
        return Event::signs($this->pubkey, $this->id, $this->sig);
    }

    /** As Event::replaces(): the higher created_at, then the lower id. */
    public function replaces(self $other): bool
    {
        return $this->createdAt > $other->createdAt || ($this->createdAt === $other->createdAt && strcmp($this->id, $other->id) < 0);
    }
}
