<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Decimal;

/**
 * A price written as a JSON number reads as ECMAScript's Number::toString writes it (SPEC.md §25):
 * against Node's String() of 7 095 doubles (tests/fixtures/ecmascript-shortest.json, from
 * tools/shortest/generate.mjs): every power of two, random bits, random prices.
 */
final class DecimalShortestTest extends TestCase
{
    public function testAsEcmascriptWritesThem(): void
    {
        $fixture = json_decode((string) file_get_contents(\dirname(__DIR__) . '/fixtures/ecmascript-shortest.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['cases']);
        self::assertCount(7095, $fixture['cases']);
        $wrong = [];
        foreach ($fixture['cases'] as $case) {
            \assert(\is_array($case) && \is_string($case[0] ?? null) && \is_string($case[1] ?? null));
            [$bits, $text] = $case;
            $unpacked = unpack('E', (string) hex2bin($bits));
            \assert(\is_array($unpacked) && \is_float($unpacked[1]));
            if (Decimal::shortest($unpacked[1]) !== self::parse($text)) {
                $wrong[] = "{$bits}: {$text}";
            }
        }
        self::assertSame([], $wrong);
    }

    public function testOfTwoAsNearTheEvenOne(): void
    {
        // SPEC.md §25: of two shortest decimals that read back as the double, the nearer; of two
        // as near, the one whose last digit is even. Each of these doubles lies halfway between two
        // decimals of 17 digits, both of which read back as it (Node's String() writes the even
        // one).
        foreach ([
            '1660867710597813.25' => ['1660867710597813.2', '1660867710597813.3'],
            '1660867710597813.75' => ['1660867710597813.8', '1660867710597813.7'],
            '1234567890123456.25' => ['1234567890123456.2', '1234567890123456.3'],
            '2251799813685247.75' => ['2251799813685247.8', '2251799813685247.7'],
        ] as $json => [$even, $odd]) {
            $double = (float) $json;
            self::assertSame([$double, $double], [(float) $even, (float) $odd], "{$json}: both read back as it");
            self::assertSame($even, Decimal::fromNumber($double)?->plain(), $json);
        }
    }

    /**
     * ECMAScript's text of a positive number as its significant digits and the exponent of the
     * first one.
     *
     * @return array{0: string, 1: int}
     */
    private static function parse(string $text): array
    {
        self::assertSame(1, preg_match('/^([0-9]+)(?:\.([0-9]+))?(?:e([+-][0-9]+))?$/D', $text, $match), $text);
        $integer = $match[1];
        $all = $integer . ($match[2] ?? '');
        $leading = \strlen($all) - \strlen(ltrim($all, '0'));

        return [rtrim(ltrim($all, '0'), '0'), \strlen($integer) - 1 - $leading + (int) ($match[3] ?? 0)];
    }
}
