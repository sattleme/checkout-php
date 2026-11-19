<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

/** A request a test server received. */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers lowercase names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $target,
        public readonly array $headers,
    ) {}

    public function host(): string
    {
        return $this->headers['host'] ?? '';
    }

    /** The target's path, without its query. */
    public function path(): string
    {
        $question = strpos($this->target, '?');

        return $question === false ? $this->target : substr($this->target, 0, $question);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        $question = strpos($this->target, '?');
        if ($question === false) {
            return [];
        }
        parse_str(substr($this->target, $question + 1), $query);
        $strings = [];
        foreach ($query as $name => $value) {
            if (\is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }
}
