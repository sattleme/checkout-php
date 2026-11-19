<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** unsupported_currency: none of the rate sources has a price in the currency. */
final class UnsupportedCurrencyException extends SattlemeException
{
    public function __construct(private readonly string $currency, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : "No price in {$currency}", 'unsupported_currency', false, $previous);
    }

    /** The currency (ISO 4217). */
    public function getCurrency(): string
    {
        return $this->currency;
    }
}
