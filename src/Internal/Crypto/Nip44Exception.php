<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Crypto;

/**
 * A NIP-44 payload that does not decrypt: invalid, or of a newer format (SPEC.md §12).
 *
 * @internal
 */
final class Nip44Exception extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $newerFormat = false)
    {
        parent::__construct($message);
    }
}
