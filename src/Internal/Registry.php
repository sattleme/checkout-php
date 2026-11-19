<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * The merchant's registry, as the channel gives it: its key (which addresses records) and its
 * reader's public key (whom the inside of a record is sealed for).
 *
 * @internal
 */
final class Registry
{
    public function __construct(
        public readonly Secret $key,
        public readonly string $reader,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['key' => '[secret]', 'reader' => $this->reader];
    }
}
