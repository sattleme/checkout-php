<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Net\Connector;

/**
 * What every part of a call uses: the clients, the clock, the CSPRNG, the logger, the timeouts.
 *
 * @internal
 */
final class Context
{
    /** While a wait for a payment runs: its warnings logged once while each check meets them again. */
    public ?RepeatedWarnings $repeated = null;

    /**
     * @param \Closure(): int                              $now    Unix seconds
     * @param \Closure(int): string                        $random the CSPRNG
     * @param \Closure(string, array<string, mixed>): void $logger warnings
     */
    public function __construct(
        public readonly Connector $connector,
        public readonly HttpClient $http,
        public readonly \Closure $now,
        public readonly \Closure $random,
        public readonly \Closure $logger,
        public readonly int $relayTimeoutMs,
        public readonly int $httpTimeoutMs,
    ) {}

    public function now(): int
    {
        return ($this->now)();
    }

    public function random(int $bytes): string
    {
        $value = ($this->random)($bytes);
        if (\strlen($value) !== $bytes) {
            throw new \LogicException('The randomness gave another length');
        }

        return $value;
    }

    /** 32 random bytes that are a secret key (from 1 to n − 1): drawn again until they are. */
    public function randomSecret(): string
    {
        do {
            $secret = $this->random(32);
        } while (!Secp256k1::isSecretKey($secret));

        return $secret;
    }

    /** @param array<string, mixed> $context never a secret */
    public function warn(string $message, array $context = []): void
    {
        if ($this->repeated !== null && !$this->repeated->isNew($message)) {
            return; // Met again by each check of a wait (SPEC.md §23): logged once.
        }
        try {
            ($this->logger)($message, $context);
        } catch (\Throwable) {
            // A logger that fails never fails a call.
        }
    }
}
