<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Secret;

/**
 * What a check opens the merchant's acceptance of an invoice with (SPEC.md §31, "The merchant's
 * acceptance"): the team, its only author (the integration key opens it but cannot make one); the
 * channel encryption key, its NIP-44 key; its address, from the integration key, the sale author S
 * of the order's ref, the invoice's address A and its end E. The key is a Secret.
 *
 * @internal
 */
final class MerchantAcceptanceKeys
{
    private function __construct(
        public readonly string $team,
        public readonly Secret $encryptionKey,
        public readonly string $address,
    ) {}

    public static function of(IntegrationKey $key, string $sale, string $address, int $expiresAt): self
    {
        $ik = $key->key->reveal();

        return new self($key->team, new Secret(Derive::channelEncryptionKey($ik)), Derive::merchantAcceptanceAddress($ik, $sale, $address, $expiresAt));
    }

    /** Whether what a relay sent opens as the merchant's acceptance (SPEC.md §13: what its read keeps). */
    public function opens(\stdClass $value): bool
    {
        return SealedRecord::opens($value, [Protocol::MERCHANT_ACCEPTANCE_TYPE], $this->team, fn(): string => $this->encryptionKey->reveal(), $this->address);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['team' => $this->team, 'encryptionKey' => '[secret]', 'address' => $this->address];
    }
}
