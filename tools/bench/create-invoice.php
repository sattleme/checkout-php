<?php

declare(strict_types=1);

/*
 * The time of createInvoice, and of the cryptography it does, on this PHP (with or without gmp:
 * the SDK uses bcmath and OpenSSL only). Against the tests' local world (relays and fakes on
 * 127.0.0.1, TLS included): never the network. Run after composer install (it uses the tests'
 * support): php tools/bench/create-invoice.php [runs]
 */

use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Crypto\Schnorr;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Tests\Support\World;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$runs = (int) ($argv[1] ?? 20);

/** @param Closure(): mixed $operation */
function median(int $runs, Closure $operation): float
{
    return medians($runs, $operation)[0];
}

/**
 * The median wall time and the median CPU time (user + system, the local fakes' included) in ms.
 *
 * @param Closure(): mixed $operation
 *
 * @return array{0: float, 1: float}
 */
function medians(int $runs, Closure $operation): array
{
    $times = [];
    $cpu = [];
    for ($i = 0; $i < $runs; ++$i) {
        $before = getrusage();
        $start = hrtime(true);
        $operation();
        $times[] = (hrtime(true) - $start) / 1e6;
        $after = getrusage();
        $cpu[] = (($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'] - $before['ru_stime.tv_sec']) * 1e6
            + $after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'] - $before['ru_stime.tv_usec']) / 1e3;
    }
    sort($times);
    sort($cpu);

    return [$times[intdiv(count($times), 2)], $cpu[intdiv(count($cpu), 2)]];
}

printf("PHP %s, %s, gmp %s\n", PHP_VERSION, OPENSSL_VERSION_TEXT, extension_loaded('gmp') ? 'loaded' : 'absent');

$secret = random_bytes(32);
$message = random_bytes(32);
$public = Secp256k1::xOnlyPublicKey($secret);
$signature = Schnorr::sign($secret, $message, random_bytes(32));
$peer = Secp256k1::xOnlyPublicKey(random_bytes(32));
printf("  BIP-340 signature:          %6.2f ms\n", median(200, static fn() => Schnorr::sign($secret, $message, random_bytes(32))));
printf("  BIP-340 verification:       %6.2f ms\n", median(200, static fn() => Schnorr::verify($public, $message, $signature)));
printf("  NIP-44 conversation key:    %6.2f ms\n", median(200, static fn() => Nip44::conversationKey($secret, $peer)));
printf("  NIP-44 encryption (1 KiB):  %6.2f ms\n", median(200, static fn() => Nip44::encrypt(str_repeat('a', 1024), $message, random_bytes(32))));

$world = new World();
$world->channel();
$client = $world->client();
$n = 0;
[$wall, $cpu] = medians($runs, static function () use ($client, &$n): void {
    $client->createInvoice(['ref' => 'BENCH-' . ++$n, 'sats' => '500']);
});
printf("  createInvoice, Lightning:    %6.1f ms, CPU %5.1f ms (medians of %d; local relays and fakes, their CPU included)\n", $wall, $cpu, $runs);
[$wall, $cpu] = medians($runs, static function () use ($client, $world, &$n): void {
    ++$world->now;
    $world->lot([World::address()], $world->now - 1);
    $client->createInvoice(['ref' => 'BENCH-' . ++$n, 'sats' => '49000']);
});
printf("  createInvoice, with address: %6.1f ms, CPU %5.1f ms\n", $wall, $cpu);
$world->stop();
