<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** onchain_unavailable: the order can only be paid on-chain, and no address of the lot could be leased (SPEC.md §31). */
final class OnchainUnavailableException extends SattlemeException
{
    public function __construct(string $message = 'No deposit address could be leased', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'onchain_unavailable', true, $previous);
    }
}
