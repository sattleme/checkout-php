<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** rate: no usable price: invalid, too old, or a custom source failed (SPEC.md §26). */
final class RateException extends SattlemeException
{
    public function __construct(string $message = 'No usable price', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'rate', true, $previous);
    }
}
