<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/**
 * Every error the SDK raises but a cancellation (SPEC.md §5): a stable code, and whether the same
 * call may succeed later, unchanged. A message never holds a secret.
 */
abstract class SattlemeException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The stable code: invalid_key, invalid_option, invalid_order, revoked, network… */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Whether the same call may succeed later, unchanged. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
