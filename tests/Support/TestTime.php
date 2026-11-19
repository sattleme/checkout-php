<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support;

use Sattleme\Checkout\Internal\Loop\Loop;

/**
 * The tests' one time scale: SATTLEME_TEST_TIME_SCALE, a whole number from 1 to 6 (1 when unset),
 * multiplies every wall-clock time of the tests together (the timeouts they give the SDK, the
 * delays and waits of the local relays and servers, their sleeps and cancellations, the bounds
 * they assert), so that a slow machine keeps each test's meaning with proportionally more time:
 * the local servers run on the call's one thread, and a slow runner eats the margins (CI's
 * Windows runners: twice Linux's time). At 1, every time is the test's own. At most 6: the
 * tests' longest client timeout, 10 000 ms, stays within the SDK's 60 000. Any other value fails
 * every test that reads it. Never scaled: the CPU time a test counts (HttpClientTest), the SDK's
 * own times (a pong's 5 s, the explorer's reconnections, the 60 s on-chain check) and the bounds
 * a test measures against them, the options a test checks the SDK refuses, and the clock of the
 * records (World::$now).
 */
final class TestTime
{
    public const VARIABLE = 'SATTLEME_TEST_TIME_SCALE';

    /** The largest scale: 10 000 ms (the tests' longest client timeout) times it, within the SDK's 60 000. */
    public const MAX = 6;

    /** The scale once read: the environment does not change while the tests run. */
    private static ?int $scale = null;

    /** @throws \UnexpectedValueException when SATTLEME_TEST_TIME_SCALE is set to anything but 1 to MAX */
    public static function scale(): int
    {
        return self::$scale ??= self::read();
    }

    /** @throws \UnexpectedValueException when SATTLEME_TEST_TIME_SCALE is set to anything but 1 to MAX */
    private static function read(): int
    {
        $value = getenv(self::VARIABLE);
        if ($value === false) {
            return 1;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (int) $value > self::MAX) {
            throw new \UnexpectedValueException(self::VARIABLE . ': a whole number from 1 to ' . self::MAX . ', not ' . var_export($value, true));
        }

        return (int) $value;
    }

    /** $ms milliseconds, scaled. */
    public static function ms(int $ms): int
    {
        return $ms * self::scale();
    }

    /** $seconds, scaled. */
    public static function seconds(float $seconds): float
    {
        return $seconds * self::scale();
    }

    /** The loop's time $seconds (scaled) from now: a deadline. */
    public static function deadline(float $seconds): float
    {
        return Loop::now() + self::seconds($seconds);
    }

    /** Blocks the process $ms milliseconds (scaled), the loop with it. */
    public static function block(int $ms): void
    {
        usleep(self::ms($ms) * 1000);
    }
}
