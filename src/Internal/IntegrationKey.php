<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;

/**
 * An integration's secret key, `sattleme_ck_<team>.<key>.<relays>` (SPEC.md §8). The key itself is a
 * Secret: no property, debug output nor serialization of this object holds it.
 *
 * @internal
 */
final class IntegrationKey
{
    /**
     * @param string       $team   hex of the team's x-only public key: the author of the channel
     * @param list<string> $relays where the channel was published when the key was given
     */
    private function __construct(
        public readonly string $team,
        public readonly Secret $key,
        public readonly array $relays,
    ) {}

    /**
     * Reads an integration key; anything else is InvalidKeyException, whose message never holds
     * the key.
     */
    public static function parse(#[\SensitiveParameter] mixed $text): self
    {
        if (!\is_string($text) || !str_starts_with($text, Protocol::KEY_PREFIX)) {
            throw new InvalidKeyException();
        }
        $parts = explode('.', substr($text, \strlen(Protocol::KEY_PREFIX)));
        if (\count($parts) !== 3) {
            throw new InvalidKeyException();
        }
        $team = Base64::decodeUrl($parts[0]);
        $key = Base64::decodeUrl($parts[1]);
        if ($team === null || $key === null || \strlen($team) !== Protocol::KEY_BYTES || \strlen($key) !== Protocol::KEY_BYTES) {
            throw new InvalidKeyException();
        }
        if (!Secp256k1::isXOnlyPublicKey($team)) {
            throw new InvalidKeyException();
        }
        $relays = RelayList::decode($parts[2]);
        if ($relays === null) {
            throw new InvalidKeyException();
        }

        return new self(bin2hex($team), new Secret($key), $relays);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['team' => $this->team, 'key' => '[secret]', 'relays' => $this->relays];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('An integration key is never serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('An integration key is never serialized');
    }
}
