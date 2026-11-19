<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** checkout_unavailable: a checkout link was asked, and the merchant's app gives no web app for it (SPEC.md §30). */
final class CheckoutUnavailableException extends SattlemeException
{
    public function __construct(string $message = 'The merchant\'s app gives no web app for checkout links', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'checkout_unavailable', false, $previous);
    }
}
