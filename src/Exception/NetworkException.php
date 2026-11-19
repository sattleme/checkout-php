<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/** network: a relay, the Lightning address, a rate source, the explorer or BlockCypher did not answer usably, or fewer than a majority of relays answered or took a record. */
final class NetworkException extends SattlemeException
{
    public function __construct(string $message = 'No usable answer', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'network', true, $previous);
    }
}
