<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\HttpGet;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Protocol;

/**
 * When BlockCypher first received a transaction (SPEC.md §31, "Explorers"): the same time for
 * every reader, which never changes. Without a token it takes 100 requests an hour from an IP
 * address: it is asked only about a transaction no check saw while its lease ran.
 *
 * @internal
 */
final class BlockCypher
{
    /** As BlockCypher writes a time (SPEC.md §31, "Explorers"): UTC, a fraction of 1 to 9 digits, "Z". */
    private const RECEIVED = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,9}))?Z$/D';

    private function __construct() {}

    /**
     * When BlockCypher received $txid, in Unix milliseconds; null when it does not know it yet
     * (HTTP 404: it receives it later).
     *
     * @throws NetworkException
     */
    public static function received(Context $context, string $txid): ?int
    {
        $url = Protocol::BLOCKCYPHER . '/txs/' . $txid . '?limit=1&includeHex=false';
        $response = HttpGet::get($context, $url);
        if ($response->status === 404) {
            return null;
        }
        $body = HttpGet::json($response);
        $time = self::time(Json::get($body, 'received'));
        if ($response->status < 200 || $response->status >= 300 || !$body instanceof \stdClass || Json::get($body, 'hash') !== $txid
            || $time === null) {
            throw new NetworkException(HttpGet::host($url) . " gave no time for the transaction (HTTP {$response->status})");
        }

        return $time;
    }

    /**
     * A transaction's `received` as BlockCypher writes it (SPEC.md §31, "Explorers"), in Unix
     * milliseconds above 0; else null (a failure: another spelling of RFC 3339 is not BlockCypher's).
     */
    public static function time(mixed $received): ?int
    {
        $time = \is_string($received) ? self::milliseconds($received) : null;

        return $time !== null && $time > 0 ? $time : null;
    }

    /**
     * Unix milliseconds of a time as BlockCypher writes it: UTC, matching RECEIVED, a real date and
     * time (a month of 01 to 12, a day of that month, hours 00 to 23, minutes and seconds 00 to 59:
     * no leap second, as JavaScript's Date refuses it); the fraction past milliseconds dropped,
     * never rounded. Null when it is not one.
     */
    public static function milliseconds(string $text): ?int
    {
        if (preg_match(self::RECEIVED, $text, $match) !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', \array_slice($match, 1, 6));
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $milliseconds = (int) str_pad(substr($match[7] ?? '', 0, 3), 3, '0');

        return self::days($year, $month, $day) * 86400000 + (($hour * 60 + $minute) * 60 + $second) * 1000 + $milliseconds;
    }

    /** Days from 1970-01-01 to a date of the proleptic Gregorian calendar (exact, any year 0–9999). */
    private static function days(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra - 719468;
    }
}
