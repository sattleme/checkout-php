<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit\Nostr;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\CompleteRead;

/**
 * Complete reads (SPEC.md §13) beyond relay-reads.json: review L3 of the specification's drafts,
 * as checkout-node's test/onchain-security-review.test.ts states it.
 */
final class CompleteReadTest extends TestCase
{
    public function testL3AFullPageWhoseOnlyNewEventIsUndatedAsksTheNextPage(): void
    {
        // A new event, dated or not, asks the next page (SPEC.md §13): from the same second,
        // the page's oldest dated one. That page bringing nothing new, the read stops incomplete,
        // rather than asking it again until its 20th.
        $seen = [];
        $first = array_map(static fn(int $i): array => ["a{$i}", 1000], range(0, 99));
        self::assertSame(['until' => 1000], CompleteRead::afterPage($first, $seen, 1));
        $second = [...\array_slice($first, 0, 99), ['new', Json::safeInteger(2 ** 60)]];
        self::assertSame(['until' => 1000], CompleteRead::afterPage($second, $seen, 2));
        self::assertSame(['complete' => false], CompleteRead::afterPage($second, $seen, 3));
        // Kept all the same, once.
        self::assertCount(101, $seen);
    }
}
