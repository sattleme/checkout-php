<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support;

use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\StreamHttpClient;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Net\Connector;

/**
 * A call's context for tests of the SDK's parts: a fixed clock (or one of the test's) when given,
 * the logs kept; its timeouts ($relayTimeoutMs, and 2 000 ms for HTTP) scaled (TestTime).
 */
final class TestContext
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $warnings = [];

    /** @param int|(\Closure(): int)|null $now Unix seconds, or the clock that gives them */
    public static function create(Connector $connector, int|\Closure|null $now = null, ?HttpClient $http = null, int $relayTimeoutMs = 2000, ?\Closure $random = null): Context
    {
        self::$warnings = [];

        return new Context(
            $connector,
            $http ?? new StreamHttpClient($connector),
            match (true) {
                $now === null => static fn(): int => time(),
                $now instanceof \Closure => $now,
                default => static fn(): int => $now,
            },
            $random ?? static fn(int $n): string => random_bytes(max(1, $n)),
            static function (string $message, array $context): void {
                self::$warnings[] = [$message, $context];
            },
            TestTime::ms($relayTimeoutMs),
            TestTime::ms(2000),
        );
    }
}
