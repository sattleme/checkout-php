<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Records;
use Sattleme\Checkout\Internal\Secret;

/**
 * The integration's lot of deposit addresses (SPEC.md §31, "The lot"), which the merchant's app
 * writes: a sealed record of the team, under the channel encryption key, at the lot address.
 *
 * @internal
 */
final class Lot
{
    private function __construct() {}

    /**
     * A lot record's value (SPEC.md §31): ['ok', addresses], ['newer'], or null when it is invalid.
     *
     * @return array{0: 'ok', 1: list<string>}|array{0: 'newer'}|null
     */
    public static function validate(mixed $value, Event $event): ?array
    {
        if (!$value instanceof \stdClass) {
            return null;
        }
        $version = Json::get($value, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::LOT_VERSION) {
            return ['newer'];
        }
        if (Json::safeInteger($version) !== Protocol::LOT_VERSION || Json::safeInteger(Json::get($value, 'updatedAt')) !== $event->createdAt) {
            return null;
        }
        $addresses = Json::get($value, 'addresses');
        if (!\is_array($addresses) || !array_is_list($addresses) || \count($addresses) > Protocol::MAX_LOT) {
            return null;
        }
        $seen = [];
        foreach ($addresses as $address) {
            if (!DepositAddress::isValid($address)) {
                return null;
            }
            \assert(\is_string($address));
            if (isset($seen[$address])) {
                return null;
            }
            $seen[$address] = true;
        }

        /** @var list<string> $addresses */
        return ['ok', $addresses];
    }

    /**
     * Reads the lot on the merchant's relays (not paged, a key of this read alone): the addresses
     * of its newest valid copy; none without one, or when a copy of a newer format is newer than
     * it (logged).
     *
     * @param list<string> $relays the merchant's
     *
     * @return list<string>
     *
     * @throws \Sattleme\Checkout\Exception\NetworkException when fewer than a majority answered
     */
    public static function read(RelayPool $pool, Context $context, IntegrationKey $key, array $relays): array
    {
        $ik = $key->key->reveal();
        $address = Derive::lotAddress($ik);
        $encryptionKey = Derive::channelEncryptionKey($ik);
        $team = $key->team;
        $key = new Secret($encryptionKey);
        $answers = $pool->read(
            $relays,
            ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$team], '#d' => [$address]],
            false,
            $context->randomSecret(...),
            static fn(\stdClass $value): bool => SealedRecord::opens($value, [Protocol::LOT_TYPE], $team, static fn(): string => $key->reveal(), $address),
        );

        return self::fromAnswers($answers, $context, $team, $address, $encryptionKey);
    }

    /**
     * The lot among what the relays sent.
     *
     * @param array<string, list<mixed>> $answers by relay
     *
     * @return list<string>
     */
    public static function fromAnswers(array $answers, Context $context, string $team, string $address, #[\SensitiveParameter] string $encryptionKey): array
    {
        $key = new Secret($encryptionKey);
        $records = Records::newest(
            $answers,
            static fn(mixed $value): ?SealedRecord => SealedRecord::open($value, Protocol::LOT_TYPE, $address, $team, $key->reveal()),
            self::validate(...),
        );
        if ($records->newestNewer !== null && ($records->newest === null || $records->newestNewer > $records->newest[0]->createdAt)) {
            $context->warn('The integration\'s lot of addresses is of a newer format: update sattleme/checkout');

            return [];
        }
        if ($records->newest === null) {
            return [];
        }
        $addresses = $records->newest[1];
        \assert(\is_array($addresses));

        /** @var list<string> $addresses */
        return $addresses;
    }
}
