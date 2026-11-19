<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Nostr\Stamp;
use Sattleme\Checkout\Internal\Protocol;

/**
 * A record of the registry at a deposit address's registry address, by any author, its outside
 * opened (SPEC.md §31, "Opening a lease"): a lease (and its end), a withdrawal, or a record of a
 * newer format. Its id is checked; its signature only where it decides (signed()), once. Of its
 * event, only its stamp is kept (SPEC.md §13, Limits): never its tags nor its content.
 *
 * @internal
 */
final class LeaseRecord
{
    public const LEASE = 'lease';
    public const WITHDRAWN = 'withdrawn';
    public const NEWER = 'newer';

    /** @var list<string> other relays' signatures of the event */
    private array $copies = [];

    private ?bool $signed = null;

    /** @var \Closure(Stamp): bool whether a copy's signature checks out */
    private readonly \Closure $verify;

    /**
     * @param self::LEASE|self::WITHDRAWN|self::NEWER $kind
     * @param int|null                                $end    a lease's
     * @param (\Closure(Stamp): bool)|null            $verify Stamp::verify() (the conformance vectors give their own)
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $end,
        public readonly Stamp $stamp,
        ?\Closure $verify = null,
    ) {
        $this->verify = $verify ?? static fn(Stamp $copy): bool => $copy->verify();
    }

    /**
     * Whether it holds its address at $t: a lease whose end is at least t − 300 (another server's
     * clock may be behind), or a record of a newer format (an unreadable lease is never written
     * over); a withdrawal, or a lease that ended, holds nothing.
     */
    public function holds(int $t): bool
    {
        return $this->kind === self::NEWER || ($this->kind === self::LEASE && $this->end >= $t - Protocol::CLOCK_TOLERANCE);
    }

    /**
     * Whether a copy of the event a relay sent carries a signature that checks out: a relay's copy
     * with a damaged signature never hides the valid one another relay sent (SPEC.md §11: a copy
     * that does not check out is ignored). Checked once, when first asked.
     */
    public function signed(): bool
    {
        if ($this->signed === null) {
            $this->signed = false;
            foreach ([$this->stamp->sig, ...$this->copies] as $sig) {
                Loop::get()->checkpoint();
                if (($this->verify)($this->stamp->withSig($sig))) {
                    $this->signed = true;
                    break;
                }
            }
        }

        return $this->signed;
    }

    /** Another relay's copy of the event, its id checked as its hash: its signature one more to try. */
    public function addCopy(string $sig): void
    {
        if ($sig === $this->stamp->sig || \in_array($sig, $this->copies, true)) {
            return;
        }
        $this->copies[] = $sig;
        if ($this->signed === false) {
            $this->signed = null;
        }
    }
}
