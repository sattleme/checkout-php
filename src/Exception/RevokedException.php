<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** revoked: the merchant removed the integration. */
final class RevokedException extends SattlemeException
{
    public function __construct(string $message = 'The merchant removed this integration', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'revoked', false, $previous);
    }
}
