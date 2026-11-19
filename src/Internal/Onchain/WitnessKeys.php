<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Secret;

/**
 * The keys of the integration's witnesses (SPEC.md §31): the seen secret and author, the seen
 * address key, the channel encryption key. Every key is a Secret.
 *
 * @internal
 */
final class WitnessKeys
{
    private function __construct(
        public readonly Secret $seenSecret,
        public readonly string $seenAuthor,
        public readonly Secret $seenAddressKey,
        public readonly Secret $encryptionKey,
    ) {}

    public static function of(IntegrationKey $key): self
    {
        $ik = $key->key->reveal();
        $seenSecret = Derive::seenSecret($ik);

        return new self(new Secret($seenSecret), Derive::author($seenSecret), new Secret(Derive::seenAddressKey($ik)), new Secret(Derive::channelEncryptionKey($ik)));
    }

    /** Whether what a relay sent opens as a witness (SPEC.md §13: what a read of witnesses keeps). */
    public function opens(\stdClass $value): bool
    {
        return SealedRecord::opens($value, [Protocol::WITNESS_TYPE], $this->seenAuthor, fn(): string => $this->encryptionKey->reveal());
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['seenSecret' => '[secret]', 'seenAuthor' => $this->seenAuthor, 'seenAddressKey' => '[secret]', 'encryptionKey' => '[secret]'];
    }
}
