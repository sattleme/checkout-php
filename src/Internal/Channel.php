<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Checkout\Exception\RevokedException;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\BitcoinNode;

/**
 * The integration's channel (SPEC.md §14): the merchant's business name, Lightning address,
 * registry, relays, web app for checkout links, tolerance of on-chain underpayments and Bitcoin
 * node.
 *
 * @internal
 */
final class Channel
{
    /**
     * The merchant's tolerance: never in what the shop's page or the customer gets, a log, an error
     * (SPEC.md §27), so never in a dump nor a trace either: held as a Secret, read by tolerance().
     */
    private readonly Secret $tolerance;

    /**
     * @param list<string>|null $relays      the channel's relay list, when it names one
     * @param BitcoinNode|null  $bitcoinNode the merchant's Bitcoin node (SPEC.md §31): absent or
     *                                       invalid in any way, none (the server reads
     *                                       mempool.space), the channel valid either way
     */
    private function __construct(
        public readonly bool $removed,
        public readonly string $businessName,
        #[\SensitiveParameter]
        public readonly ?LightningAddress $lightningAddress,
        public readonly ?Secret $registryKey,
        public readonly string $registryReader,
        public readonly ?array $relays,
        public readonly ?string $checkoutOrigin,
        #[\SensitiveParameter]
        int $tolerance,
        public readonly ?BitcoinNode $bitcoinNode,
    ) {
        $this->tolerance = new Secret((string) $tolerance);
    }

    /** The merchant's tolerance of on-chain underpayments, basis points (SPEC.md §31). */
    public function tolerance(): int
    {
        return (int) $this->tolerance->reveal();
    }

    /** @return array<string, mixed> without the tolerance, the registry key nor the Lightning address */
    public function __debugInfo(): array
    {
        return ['removed' => $this->removed, 'businessName' => $this->businessName, 'registryReader' => $this->registryReader, 'relays' => $this->relays, 'checkoutOrigin' => $this->checkoutOrigin, 'bitcoinNode' => $this->bitcoinNode];
    }

    /**
     * A channel record's value, valid or not (SPEC.md §14, "A valid channel value").
     *
     * @return array{0: 'ok', 1: self}|array{0: 'newer'}|null
     */
    public static function validate(mixed $value, Event $event): ?array
    {
        if (!$value instanceof \stdClass) {
            return null;
        }
        $version = Json::get($value, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::CHANNEL_VERSION) {
            return ['newer'];
        }
        if (Json::safeInteger($version) !== Protocol::CHANNEL_VERSION || Json::safeInteger(Json::get($value, 'updatedAt')) !== $event->createdAt) {
            return null;
        }
        $status = Json::get($value, 'status');
        if ($status === 'removed') {
            return ['ok', new self(true, '', null, null, '', null, null, 0, null)];
        }
        if ($status !== 'active' || Json::get($value, 'role') !== 'integration') {
            return null;
        }
        $profile = Json::get($value, 'profile');
        $businessName = Json::get($profile, 'businessName');
        if (!$profile instanceof \stdClass || !self::isBusinessName($businessName)) {
            return null;
        }
        \assert(\is_string($businessName));
        $address = LightningAddress::parse(Json::get($profile, 'lightningAddress'));
        $registryKey = Json::get($value, 'registryKey');
        $registryReader = Json::get($value, 'registryReader');
        if ($address === null || !Text::isHex32($registryKey) || !Text::isHex32($registryReader)) {
            return null;
        }
        \assert(\is_string($registryKey) && \is_string($registryReader));
        if (!Secp256k1::isXOnlyPublicKey((string) hex2bin($registryReader))) {
            return null;
        }
        $relays = null;
        if (Json::has($value, 'relays')) {
            $relays = RelayList::parse(Json::get($value, 'relays'));
            if ($relays === null) {
                return null;
            }
        }
        $checkout = Json::get($value, 'checkout');
        $origin = $checkout instanceof \stdClass ? self::checkoutOrigin(Json::get($checkout, 'origin')) : null;

        return ['ok', new self(
            false,
            $businessName,
            $address,
            new Secret((string) hex2bin($registryKey)),
            $registryReader,
            $relays,
            $origin,
            Tolerance::read(Json::get($value, 'onchainTolerance')),
            // A field a reader that does not know it ignores: no new version of the channel.
            BitcoinNode::of(Json::get($value, 'bitcoinNode')),
        )];
    }

    /** Whether $value is a business name: not empty once ASCII whitespace is trimmed, 200 code points at most. */
    public static function isBusinessName(mixed $value): bool
    {
        return \is_string($value) && Text::trimAscii($value) !== '' && Text::codePoints($value) <= Protocol::MAX_BUSINESS_NAME;
    }

    /**
     * $value as a checkout origin (SPEC.md §30): https://, a host as a relay address's, an
     * optional port, nothing else; else null.
     */
    public static function checkoutOrigin(mixed $value): ?string
    {
        if (!\is_string($value) || preg_match('~^https://([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?::([0-9]{1,5}))?$~D', $value, $match) !== 1) {
            return null;
        }
        $port = $match[2] ?? '';
        if (($port !== '' && ((int) $port < 1 || (int) $port > 65535)) || !Url::isHost($match[1])) {
            return null;
        }

        return $value;
    }

    /**
     * Reads the channel (SPEC.md §14): on the key's relays, then on the lists it moves to (3 at
     * most). The merchant's relays are $merchantRelays.
     *
     * @param list<string>|null $merchantRelays set to the channel's relays, else the key's
     *
     * @param-out list<string> $merchantRelays
     *
     * @throws \Sattleme\Checkout\Exception\NetworkException
     * @throws ChannelNotFoundException
     * @throws RevokedException
     */
    public static function read(RelayPool $pool, Context $context, IntegrationKey $key, ?array &$merchantRelays = null): self
    {
        $ik = $key->key->reveal();
        $address = Derive::channelAddress($ik);
        $encryptionKey = Derive::channelEncryptionKey($ik);
        $readOn = $key->relays;
        $found = self::lookup($pool, $context, $key->team, $address, $encryptionKey, $readOn);
        if ($found->newest === null) {
            throw new ChannelNotFoundException($found->newestNewer !== null);
        }
        $channel = $found->newest[1];
        \assert($channel instanceof self);
        for ($moves = 0; $moves < Protocol::RELAY_MOVES && !$channel->removed && $channel->relays !== null && !RelayList::same($channel->relays, $readOn); ++$moves) {
            $readOn = $channel->relays;
            $moved = self::lookup($pool, $context, $key->team, $address, $encryptionKey, $readOn);
            if ($moved->newest === null) {
                break; // Not copied there yet: the channel read so far holds.
            }
            $next = $moved->newest[1];
            \assert($next instanceof self);
            $channel = $next;
        }
        if ($channel->removed) {
            throw new RevokedException();
        }
        $merchantRelays = $channel->relays ?? $key->relays;

        return $channel;
    }

    /**
     * Lookup on a list: the newest valid copy.
     *
     * @param list<string> $relays
     */
    private static function lookup(RelayPool $pool, Context $context, string $team, string $address, #[\SensitiveParameter] string $encryptionKey, array $relays): Records
    {
        $key = new Secret($encryptionKey);
        $answers = $pool->read(
            $relays,
            ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$team], '#d' => [$address]],
            false,
            $context->randomSecret(...),
            static fn(\stdClass $value): bool => SealedRecord::opens($value, [Protocol::CHANNEL_TYPE], $team, static fn(): string => $key->reveal(), $address),
        );
        $records = Records::newest(
            $answers,
            static fn(mixed $value): ?SealedRecord => SealedRecord::open($value, Protocol::CHANNEL_TYPE, $address, $team, $key->reveal()),
            self::validate(...),
        );
        if ($records->newestNewer !== null && ($records->newest === null || $records->newestNewer > $records->newest[0]->createdAt)) {
            $context->warn('The integration\'s channel is of a newer format: update sattleme/checkout');
        }

        return $records;
    }
}
