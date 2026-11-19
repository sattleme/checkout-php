<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\LoneSurrogate;
use Sattleme\Checkout\Tests\Support\TestTime;

/**
 * JSON as every implementation reads it (SPEC.md §2): as JavaScript's JSON.parse reads it, and no
 * more than 64 arrays and objects nested one in another. The bodies of payments.json read it
 * through an LNURL answer (LightningVectorsTest); these are the rules one by one.
 */
final class JsonTest extends TestCase
{
    public function testItReads64ArraysAndObjectsNestedAndRefuses65(): void
    {
        self::assertSame([[[1]]], Json::decode(self::arrays(3)));
        $deepest = Json::decode(self::arrays(64));
        for ($depth = 0; $depth < 64; ++$depth) {
            self::assertIsArray($deepest);
            $deepest = $deepest[0];
        }
        self::assertSame(1, $deepest);
        self::assertNotJson(self::arrays(65));
        self::assertNotJson(self::arrays(600));
        // Objects count as arrays do, and both together.
        self::assertInstanceOf(\stdClass::class, Json::decode(self::objects(64)));
        self::assertNotJson(self::objects(65));
        self::assertInstanceOf(\stdClass::class, Json::decode(self::mixed(64)));
        self::assertNotJson(self::mixed(66));
        self::assertNotJson('{"a":' . self::arrays(64) . '}');
        // Empty ones too: `[]` is one level.
        self::assertCount(1, (array) Json::decode(str_repeat('[', 64) . str_repeat(']', 64)));
        self::assertNotJson(str_repeat('[', 65) . str_repeat(']', 65));
    }

    public function testNoBracketInAStringCounts(): void
    {
        $text = '[' . Json::encode('[[[[' . str_repeat('{', 100) . '\\"]]]') . ']';
        self::assertSame(['[[[[' . str_repeat('{', 100) . '\\"]]]'], Json::decode($text));
        self::assertInstanceOf(\stdClass::class, Json::decode('{"a\\"[[[":' . self::arrays(63) . '}'));
    }

    public function testTheBoundHoldsWhereJsonDecodeIsTriedAgain(): void
    {
        // A lone surrogate, or a name starting with U+0000, has the text read a second time: the
        // same bound.
        self::assertCount(2, (array) Json::decode('["\\ud800",' . self::arrays(63) . ']'));
        self::assertNotJson('["\\ud800",' . self::arrays(64) . ']');
        self::assertInstanceOf(\stdClass::class, Json::decode('{"\\u0000":1,"a":' . self::arrays(63) . '}'));
        self::assertNotJson('{"\\u0000":1,"a":' . self::arrays(64) . '}');
    }

    public function testItReadsWhatJavaScriptReads(): void
    {
        // Of members with one name, the last; names in another case are other names.
        self::assertEquals((object) ['a' => 2, 'A' => 3], Json::decode('{"a":1,"A":3,"a":2}'));
        // A number is a double whatever its length: beyond the largest double, infinite.
        self::assertSame([\INF], Json::decode('[' . str_repeat('9', 5000) . ']'));
        self::assertSame([\INF, -\INF, 500, 500.0, 500.0], Json::decode('[1e400,-1e400,500,500.0,5e2]'));
        // A lone surrogate is a string, of no text (SPEC.md §6); a pair is its character.
        $value = Json::decode('{"a":"\\ud800","b":"\\ud83d\\ude00","c":"\\\\ud800"}');
        self::assertInstanceOf(\stdClass::class, $value);
        self::assertInstanceOf(LoneSurrogate::class, $value->a);
        self::assertSame('😀', $value->b);
        self::assertSame('\\ud800', $value->c, 'an escaped backslash, then text');
    }

    public function testReadingTakesTimeLinearInItsSize(): void
    {
        // SPEC.md §13: reading what a relay or a server sends costs time linear in its size: 1 MiB
        // of escapes in one string, with a non-ASCII character between each, or each a lone
        // surrogate, or names starting with U+0000 (the two that json_decode refuses, read
        // again), each read within a time close to json_decode's own (a reader that indexes a
        // string from its start at each escape takes minutes).
        $mib = 1048576;
        $cases = [
            'U+0000 escapes' => ['"' . str_repeat('\\u0000', intdiv($mib, 6)) . '"', str_repeat("\0", intdiv($mib, 6))],
            'escapes and a non-ASCII character between each' => ['"' . str_repeat('\\u0000é', intdiv($mib, 8)) . '"', str_repeat("\0é", intdiv($mib, 8))],
            'lone surrogates' => ['["' . str_repeat('\\ud800', intdiv($mib, 6)) . '"]', null],
            'names starting with U+0000' => ['{"x":1,' . implode(',', array_map(static fn(int $i): string => '"\\u0000' . $i . '":' . $i, range(1, intdiv($mib, 15)))) . '}', null],
        ];
        foreach ($cases as $case => [$text, $expected]) {
            self::assertGreaterThanOrEqual($mib - 16, \strlen($text), $case);
            $start = hrtime(true);
            $value = Json::decode($text);
            $seconds = (hrtime(true) - $start) / 1e9;
            self::assertLessThan(TestTime::seconds(2), $seconds, $case);
            if ($expected !== null) {
                self::assertSame($expected, $value, $case);
            }
        }
        $lone = Json::decode($cases['lone surrogates'][0]);
        self::assertIsArray($lone);
        self::assertInstanceOf(LoneSurrogate::class, $lone[0] ?? null);
        // A name starting with U+0000 is left out (stdClass cannot hold one): no name SPEC.md reads.
        self::assertEquals((object) ['x' => 1], Json::decode($cases['names starting with U+0000'][0]));
    }

    public function testANameMayHoldU0000(): void
    {
        // json_decode refuses a name starting with U+0000 into an object (stdClass cannot hold
        // one): it is read all the same, that member left out (no name SPEC.md reads holds one).
        $value = Json::decode('{"\\u0000extra":1,"status":"OK","settled":true}');
        self::assertEquals((object) ['status' => 'OK', 'settled' => true], $value);
        self::assertEquals((object) ['a' => [(object) ['b' => 1]]], Json::decode('{"a":[{"\\u0000":0,"b":1}]}'));
        // Further on in a name, and in a string, U+0000 is kept.
        $value = Json::decode('{"\\u0000":1,"a\\u0000b":"c\\u0000d","e":"\\\\u0000"}');
        self::assertInstanceOf(\stdClass::class, $value);
        self::assertSame(["a\0b" => "c\0d", 'e' => '\\u0000'], get_object_vars($value));
        self::assertEquals((object) ["x\0" => 1], Json::decode('{"x\\u0000":1}'));
        // Both at once.
        $value = Json::decode('{"\\u0000a":"\\ud800","b":"\\udc00","c":1}');
        self::assertInstanceOf(\stdClass::class, $value);
        self::assertSame(['b', 'c'], array_keys(get_object_vars($value)));
        self::assertInstanceOf(LoneSurrogate::class, $value->b);
    }

    /** @return iterable<string, array{string}> */
    public static function notJson(): iterable
    {
        yield 'a leading zero' => ['[01]'];
        yield 'a comma after the last item' => ['[1,]'];
        yield 'a comma after the last member' => ['{"a":1,}'];
        yield 'a no-break space' => ["[1,\u{a0}2]"];
        yield 'a byte order mark' => ["\u{feff}[1]"];
        yield 'nothing' => [''];
        yield 'an opening alone' => ['['];
        yield 'a closing alone' => [']'];
        yield 'a single quote' => ["{'a':1}"];
        yield 'NaN' => ['NaN'];
        yield 'Infinity' => ['[Infinity]'];
        yield 'a comment' => ['[1]//'];
        yield 'an unknown escape' => ['"\\x41"'];
        yield 'a control character in a string' => ["\"a\nb\""];
        yield 'not UTF-8' => ["\"\xff\""];
    }

    #[DataProvider('notJson')]
    public function testNothingElseIsJson(string $text): void
    {
        self::assertNotJson($text);
    }

    private static function assertNotJson(string $text): void
    {
        $read = true;
        try {
            Json::decode($text);
        } catch (\JsonException) {
            $read = false;
        }
        self::assertFalse($read, 'Read as JSON: ' . substr($text, 0, 80));
    }

    /** $depth arrays nested, around 1. */
    private static function arrays(int $depth): string
    {
        return str_repeat('[', $depth) . '1' . str_repeat(']', $depth);
    }

    /** $depth objects nested, around 1. */
    private static function objects(int $depth): string
    {
        return str_repeat('{"a":', $depth) . '1' . str_repeat('}', $depth);
    }

    /** $depth arrays and objects nested, one in another ($depth even). */
    private static function mixed(int $depth): string
    {
        return str_repeat('{"a":[', intdiv($depth, 2)) . '1' . str_repeat(']}', intdiv($depth, 2));
    }
}
