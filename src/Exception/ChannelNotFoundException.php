<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Exception;

/**
 * channel_not_found: a majority of the relays answered without the integration's channel.
 * Retryable unless a copy of a newer format was seen: then the SDK must be updated.
 */
final class ChannelNotFoundException extends SattlemeException
{
    public function __construct(private readonly bool $newerFormat = false, ?\Throwable $previous = null)
    {
        parent::__construct(
            $newerFormat
                ? "The integration's channel is of a newer format: update the SDK"
                : "The integration's channel was not found on its relays",
            'channel_not_found',
            !$newerFormat,
            $previous,
        );
    }

    /** Whether a copy of a newer format was seen. */
    public function isNewerFormat(): bool
    {
        return $this->newerFormat;
    }
}
