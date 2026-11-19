<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * A JSON string holding a lone surrogate (`"\ud800"`), as Json::decode reads it: JavaScript reads
 * such a string, PHP's json_decode refuses the whole text. A text holding one is invalid wherever
 * SPEC.md checks a text (§6): this is never a string, so that every check of a text refuses it, and
 * what checks no text (a member nobody reads) is left as JavaScript leaves it.
 *
 * @internal
 */
final class LoneSurrogate implements \JsonSerializable
{
    /** Never written back: no UTF-8 holds it (SPEC.md §11). */
    public function jsonSerialize(): never
    {
        throw new \LogicException('A string holding a lone surrogate has no UTF-8');
    }
}
