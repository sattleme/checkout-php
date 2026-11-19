<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** invalid_order: a parameter of the order, or a stored invoice, is invalid; param names it. */
final class InvalidOrderException extends SattlemeException
{
    public function __construct(private readonly string $param, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : "Invalid order: {$param}", 'invalid_order', false, $previous);
    }

    /** The parameter: order, ref, sats, fiat, rateSource, durationSeconds, note, checkout, redirectUrl or invoice. */
    public function getParam(): string
    {
        return $this->param;
    }
}
