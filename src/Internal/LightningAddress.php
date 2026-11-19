<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * A Lightning address (SPEC.md §16, LUD-16).
 *
 * @internal
 */
final class LightningAddress
{
    private function __construct(
        public readonly string $name,
        public readonly string $domain,
    ) {}

    /**
     * $value as a Lightning address: trimmed of ASCII whitespace, lowered; its pay URL an https
     * URL (SPEC.md §15), so its domain a host as in a relay address, never an IP address; else null.
     */
    public static function parse(mixed $value): ?self
    {
        if (!\is_string($value)) {
            return null;
        }
        $text = Text::asciiLower(Text::trimAscii($value));
        if (preg_match('/^([a-z0-9\-_.+]+)@([a-z0-9-]+(?:\.[a-z0-9-]+)+)$/D', $text, $match) !== 1) {
            return null;
        }
        $address = new self($match[1], $match[2]);

        return Url::isHttps($address->payUrl()) ? $address : null;
    }

    /** https://<domain>/.well-known/lnurlp/<name> */
    public function payUrl(): string
    {
        return 'https://' . $this->domain . '/.well-known/lnurlp/' . $this->name;
    }

    /** @return array<string, string> its domain alone: the merchant's address stays out of dumps */
    public function __debugInfo(): array
    {
        return ['domain' => $this->domain];
    }
}
