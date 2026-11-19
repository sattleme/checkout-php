<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Nostr;

use Sattleme\Checkout\Tests\Support\Net\TestConnector;

/** Local relays under `wss://` names, reached through a test connector. */
final class Relays
{
    /** @var array<string, LocalRelay> by URL */
    public array $relays = [];

    public function __construct(public readonly TestConnector $connector) {}

    /** A relay at $url (wss://host[:port][/path]). */
    public function add(string $url): LocalRelay
    {
        $relay = new LocalRelay();
        $relay->url = $url;
        $parts = parse_url($url);
        \assert(\is_array($parts) && isset($parts['host']));
        $this->connector->route($parts['host'], $parts['port'] ?? 443, $relay->server);

        return $this->relays[$url] = $relay;
    }

    public function get(string $url): LocalRelay
    {
        return $this->relays[$url] ?? throw new \OutOfBoundsException("No relay {$url}");
    }

    public function stop(): void
    {
        foreach ($this->relays as $relay) {
            $relay->stop();
        }
    }
}
