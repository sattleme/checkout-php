<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** invalid_key: the key is not an integration key (SPEC.md §8). Its message never holds the key. */
final class InvalidKeyException extends SattlemeException
{
    public function __construct(string $message = 'Invalid integration key', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'invalid_key', false, $previous);
    }
}
