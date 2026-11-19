<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** invalid_option: an option of the client or of a call is invalid; param names it. */
final class InvalidOptionException extends SattlemeException
{
    public function __construct(private readonly string $param, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : "Invalid option: {$param}", 'invalid_option', false, $previous);
    }

    /** The option: options, rateSource, coingeckoApiKey, httpClient, connector, relayTimeoutMs… */
    public function getParam(): string
    {
        return $this->param;
    }
}
