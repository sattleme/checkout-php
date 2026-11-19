<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

/** How a test server answers. */
final class HttpResponseSpec
{
    /**
     * @param 'length'|'chunked'|'close' $framing how the body's end is told
     * @param array<string, string>      $headers
     * @param int                        $delayMs how late it answers, in ms (scaled: TestTime)
     * @param string|null                $raw     the whole answer, as it is written (raw())
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly string $framing = 'length',
        public readonly array $headers = [],
        public readonly int $delayMs = 0,
        public readonly bool $hangUp = false,
        public readonly bool $silent = false,
        public readonly ?string $raw = null,
    ) {}

    /** An answer of these bytes, written as they are (its status line and head included). */
    public static function raw(string $answer): self
    {
        return new self(raw: $answer);
    }

    /**
     * A JSON answer of $value.
     *
     * @param 'length'|'chunked'|'close' $framing
     */
    public static function json(mixed $value, int $status = 200, string $framing = 'length', int $delayMs = 0): self
    {
        return new self($status, (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $framing, [], $delayMs);
    }
}
