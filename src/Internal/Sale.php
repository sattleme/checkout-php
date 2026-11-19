<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * An order's sale (SPEC.md §10): its key, its Nostr secret and author, its taker tag. Every key
 * is a Secret.
 *
 * @internal
 */
final class Sale
{
    /** @var array<string, Secret> inner keys, by registry reader */
    private array $innerKeys = [];

    private function __construct(
        public readonly string $ref,
        public readonly Secret $key,
        public readonly Secret $secret,
        public readonly string $author,
        public readonly string $takerTag,
    ) {}

    public static function of(IntegrationKey $integration, string $ref): self
    {
        $ik = $integration->key->reveal();
        $key = Derive::saleKey($ik, $ref);
        $secret = Derive::saleSecret($key);
        $author = Derive::author($secret);

        return new self($ref, new Secret($key), new Secret($secret), $author, Derive::takerTag(Derive::takerKey($ik), $author));
    }

    /** The sale key in hex, as a declaration writes it. */
    public function keyHex(): string
    {
        return bin2hex($this->key->reveal());
    }

    /** The inner key of the sale's records in $registry: the NIP-44 conversation key with its reader. */
    public function innerKey(Registry $registry): string
    {
        $secret = $this->innerKeys[$registry->reader] ??= new Secret(Derive::innerKey($this->secret->reveal(), $registry->reader));

        return $secret->reveal();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['ref' => $this->ref, 'key' => '[secret]', 'secret' => '[secret]', 'author' => $this->author];
    }
}
