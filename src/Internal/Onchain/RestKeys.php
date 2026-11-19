<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Secret;

/**
 * The keys of an invoice's rest records and acceptance records (SPEC.md §31, "Rest records"),
 * from the integration key, its order's ref, its address A and its end E. Its checkout links carry
 * the rest author and the rest key, never the rest secret. Every key is a Secret.
 *
 * @internal
 */
final class RestKeys
{
    private function __construct(
        public readonly Secret $key,
        public readonly Secret $secret,
        public readonly string $author,
        public readonly Secret $encryptionKey,
    ) {}

    public static function of(IntegrationKey $integration, string $ref, string $address, int $expiresAt): self
    {
        $ik = $integration->key->reveal();
        $key = Derive::restKey($ik, $ref, $address, $expiresAt);
        $secret = Derive::restSecret($ik, $ref, $address, $expiresAt);

        return new self(new Secret($key), new Secret($secret), Derive::author($secret), new Secret(Derive::restEncryptionKey($key)));
    }

    /** As a checkout link's page holds them: the rest author and the rest key, no secret. */
    public static function ofLink(string $author, #[\SensitiveParameter] string $key): self
    {
        return new self(new Secret($key), new Secret(''), $author, new Secret(Derive::restEncryptionKey($key)));
    }

    /** The rest record address of an invoice of the rest, by its payment hash (hex). */
    public function recordAddress(string $paymentHash): string
    {
        return Derive::restRecordAddress($this->key->reveal(), $paymentHash);
    }

    public function acceptanceAddress(): string
    {
        return Derive::acceptanceAddress($this->key->reveal());
    }

    /** Whether what a relay sent opens as a rest record (SPEC.md §13: what a read of them keeps). */
    public function opensRest(\stdClass $value): bool
    {
        return SealedRecord::opens($value, [Protocol::REST_TYPE], $this->author, fn(): string => $this->encryptionKey->reveal());
    }

    /** Whether what a relay sent opens as the acceptance record (SPEC.md §13: what a read of it keeps). */
    public function opensAcceptance(\stdClass $value): bool
    {
        return SealedRecord::opens($value, [Protocol::ACCEPTANCE_TYPE], $this->author, fn(): string => $this->encryptionKey->reveal(), $this->acceptanceAddress());
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['key' => '[secret]', 'secret' => '[secret]', 'author' => $this->author, 'encryptionKey' => '[secret]'];
    }
}
