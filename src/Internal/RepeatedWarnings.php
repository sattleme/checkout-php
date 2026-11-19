<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * The warnings of a wait for a payment (SPEC.md §23): a failure that each check meets again (an
 * explorer or a Lightning address that does not answer, a rest invoice that cannot be asked, a
 * witness `none` that cannot be published) is logged once, and again only after it stopped, not
 * at each check. It stopped once each kind of check that met it ran again without meeting it.
 *
 * A failure is its message but for its numbers: an HTTP client's own message may hold a time
 * ("timed out after 10001 milliseconds") or a status that changes from one check to the next.
 *
 * @internal
 */
final class RepeatedWarnings
{
    /** @var array<string, array<string, true>> each failure met, with the kinds of check that met it in their last run */
    private array $met = [];

    /** @var array<string, true> the failures the running check met */
    private array $now = [];

    private string $kind = '';

    /** A check of $kind begins. */
    public function begin(string $kind): void
    {
        $this->kind = $kind;
        $this->now = [];
    }

    /** Whether $message is to be logged: a failure not met before (or met again after it stopped). */
    public function isNew(string $message): bool
    {
        $failure = (string) preg_replace('/[0-9]+/', '0', $message);
        $new = !isset($this->met[$failure]) && !isset($this->now[$failure]);
        $this->now[$failure] = true;

        return $new;
    }

    /** The running check ended: the failures it met, and those its kind no longer meets. */
    public function end(): void
    {
        foreach ($this->met as $failure => $kinds) {
            if (!isset($this->now[$failure])) {
                unset($kinds[$this->kind]);
                if ($kinds === []) {
                    unset($this->met[$failure]);
                } else {
                    $this->met[$failure] = $kinds;
                }
            }
        }
        foreach (array_keys($this->now) as $failure) {
            $this->met[$failure][$this->kind] = true;
        }
        $this->now = [];
    }
}
