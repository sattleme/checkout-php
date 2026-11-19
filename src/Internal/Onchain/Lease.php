<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Onchain;

use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Crypto\Nip44Exception;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Nostr\Stamp;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Internal\Text;
use Sattleme\Checkout\Internal\Tolerance;
use Sattleme\Checkout\Internal\Url;

/**
 * An order's lease of a deposit address (SPEC.md §31, "Leases"): a sealed record of type
 * registry-onchain in the merchant's registry, signed by the sale secret, at the registry address
 * of the deposit address. Its outside ({version, end, sealed} or a withdrawal) opens with the
 * registry key; its inside (the order's terms) with the inner key, for the sale and the merchant.
 * A lease is published with `confirmed` false, then, once confirmed, again with `confirmed` true
 * (its mark): only a confirmed lease gives its invoice an address.
 *
 * @internal
 */
final class Lease
{
    /** The order's tolerance, as the channel's (Channel): never in a dump nor a trace. */
    private readonly Secret $tolerance;

    /**
     * @param numeric-string                                    $sats
     * @param array{minor: int, digits: int, currency: string} $fiat
     */
    private function __construct(
        public readonly Event $event,
        public readonly string $address,
        public readonly string $sats,
        public readonly ?string $invoice,
        public readonly ?string $verifyUrl,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly array $fiat,
        public readonly string $note,
        public readonly ?string $taker,
        #[\SensitiveParameter]
        int $tolerance,
        public readonly bool $confirmed,
    ) {
        $this->tolerance = new Secret((string) $tolerance);
    }

    /** The order's tolerance of on-chain underpayments, basis points (SPEC.md §31). */
    public function tolerance(): int
    {
        return (int) $this->tolerance->reveal();
    }

    /** @return array<string, mixed> without the tolerance */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->event->id,
            'address' => $this->address,
            'sats' => $this->sats,
            'invoice' => $this->invoice,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'confirmed' => $this->confirmed,
        ];
    }

    /** Whether it is confirmed: `confirmed` true; anything else, absent included, is not. */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * The lease's JSON (inside), in this order; `tolerance` only above 0; `confirmed` (false as
     * first published, true in its mark) last.
     *
     * @param array{minor: int, digits: int, currency: string} $fiat
     *
     * @return array<string, mixed>
     */
    public static function inside(
        string $address,
        int $sats,
        ?string $invoice,
        ?string $verifyUrl,
        int $createdAt,
        int $expiresAt,
        #[\SensitiveParameter]
        string $saleKeyHex,
        array $fiat,
        string $note,
        string $takerTag,
        #[\SensitiveParameter]
        int $tolerance,
        ?bool $confirmed = null,
    ): array {
        $inside = [
            'version' => Protocol::LEASE_VERSION,
            'address' => $address,
            'sats' => $sats,
            'invoice' => $invoice,
            'verifyUrl' => $verifyUrl,
            'createdAt' => $createdAt,
            'expiresAt' => $expiresAt,
            'sale' => ['key' => $saleKeyHex, 'fiat' => $fiat, 'note' => $note],
            'taker' => $takerTag,
        ];
        if ($tolerance > 0) {
            $inside['tolerance'] = $tolerance;
        }
        if ($confirmed !== null) {
            $inside['confirmed'] = $confirmed;
        }

        return $inside;
    }

    /**
     * The lease's event: {version, end, sealed} with end = expiresAt + 120, its JSON sealed with
     * the inner key, signed by the sale secret.
     *
     * @param array<string, mixed> $inside with its `expiresAt`
     *
     * @throws \LengthException when it does not fit a NIP-44 plaintext (a verify URL that long)
     */
    public static function event(Sale $sale, Registry $registry, string $address, #[\SensitiveParameter] array $inside, int $createdAt, Context $context): Event
    {
        $expiresAt = $inside['expiresAt'];
        \assert(\is_int($expiresAt));
        $json = Json::encode($inside);
        if (\strlen($json) > Nip44::MAX_PLAINTEXT) {
            throw new \LengthException('A lease too large for NIP-44');
        }
        $sealed = Nip44::encrypt($json, $sale->innerKey($registry), $context->random(32));

        return self::record($sale, $registry, $address, self::sealedValue($expiresAt, $sealed), $createdAt, $context);
    }

    /**
     * Whether the lease of $inside fits NIP-44 plaintexts (event() would make it): $inside, then
     * the record that seals it again, as a declaration. By its size alone: no random bytes are
     * drawn, so that it may be checked before anything is published (SPEC.md §17, step 6).
     *
     * @param array<string, mixed> $inside with its `expiresAt`
     */
    public static function fits(#[\SensitiveParameter] array $inside): bool
    {
        $expiresAt = $inside['expiresAt'];
        \assert(\is_int($expiresAt));
        $length = \strlen(Json::encode($inside));

        return $length <= Nip44::MAX_PLAINTEXT && SealedRecord::fits(Protocol::LEASE_TYPE, self::sealedValue($expiresAt, str_repeat('A', Nip44::payloadLength($length))));
    }

    /**
     * The value of the record of a lease ending at $expiresAt + 120, its inside sealed as $sealed.
     *
     * @return array{version: int, end: int, sealed: string}
     */
    private static function sealedValue(int $expiresAt, string $sealed): array
    {
        return ['version' => Protocol::LEASE_VERSION, 'end' => $expiresAt + Protocol::GRACE_SECONDS, 'sealed' => $sealed];
    }

    /** The withdrawal of the sale's lease of $address: it replaces it when strictly later. */
    public static function withdrawal(Sale $sale, Registry $registry, string $address, int $createdAt, Context $context): Event
    {
        return self::record($sale, $registry, $address, ['version' => Protocol::LEASE_VERSION, 'withdrawn' => true], $createdAt, $context);
    }

    /**
     * What a relay sent, as a record of anyone at $registryAddress (SPEC.md §31, "Opening a lease",
     * the outside only), or null when it is none. Anybody can write an event at that `d` tag: its
     * id is checked first; a content of a newer NIP-44 version is no record there (only a value of
     * a newer version, which the outer key seals, is one); its signature is left to the record
     * (LeaseRecord::signed()), checked only where it decides whether the address is held.
     */
    public static function outside(mixed $value, Registry $registry, string $registryAddress): ?LeaseRecord
    {
        // Its id first: one that is not its hash is ignored (and hides no other event of that id).
        $event = Event::fromJson($value);
        if ($event === null || !$event->idMatches()) {
            return null;
        }
        $author = $event->pubkey;
        $record = SealedRecord::open($value, Protocol::LEASE_TYPE, $registryAddress, $author, Derive::outerKey($registry->key->reveal(), $registryAddress, $author), false, true);
        $outside = $record === null ? null : self::outsideOf($record);

        return match ($outside[0] ?? null) {
            'lease' => new LeaseRecord(LeaseRecord::LEASE, $outside[1], Stamp::of($outside[2])),
            'withdrawn' => new LeaseRecord(LeaseRecord::WITHDRAWN, null, Stamp::of($outside[1])),
            'newer' => new LeaseRecord(LeaseRecord::NEWER, null, Stamp::of($outside[1])),
            default => null,
        };
    }

    /**
     * What a relay sent, as the sale's lease (SPEC.md §31, "Opening it as the sale"): ['ok',
     * lease], ['withdrawn', event], ['newer', event], or null when it is none.
     *
     * @return array{0: 'ok', 1: self}|array{0: 'withdrawn', 1: Event}|array{0: 'newer', 1: Event}|null
     */
    public static function open(mixed $value, Sale $sale, Registry $registry): ?array
    {
        $registryAddress = SealedRecord::dTag($value);
        if ($registryAddress === null || Json::get($value, 'pubkey') !== $sale->author) {
            return null;
        }
        $record = self::openRecord($value, $registry, $registryAddress);
        if ($record === null) {
            return null;
        }
        $outside = self::outsideOf($record);
        if ($outside === null || $outside[0] !== 'lease') {
            return $outside;
        }
        $end = $outside[1];
        $event = $outside[2];
        $sealed = Json::get($record->value, 'sealed');
        \assert(\is_string($sealed));
        try {
            $inside = Json::decode(Nip44::decrypt($sealed, $sale->innerKey($registry)));
        } catch (Nip44Exception $e) {
            return $e->newerFormat ? ['newer', $event] : null;
        } catch (\JsonException) {
            return null;
        }
        if (!$inside instanceof \stdClass) {
            return null;
        }
        $version = Json::get($inside, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::LEASE_VERSION) {
            return ['newer', $event];
        }
        $lease = self::terms($inside, $event, $end, $registry, $registryAddress, $sale);

        return $lease === null ? null : ['ok', $lease];
    }

    /**
     * Whether $records, those of one address, lease it at $t (SPEC.md §31, "Leases"): of each
     * author, its records from the newest (the highest created_at, then the lowest id): one that
     * holds the address makes it leased; one that holds nothing ends the walk, unless an older
     * record of the author holds the address and the signature of this one does not check out:
     * this one is then passed over. So the signature of a record that holds is never checked, only
     * that of a record that would end an older one's hold. Every author is walked.
     *
     * @param list<LeaseRecord> $records
     */
    public static function held(array $records, int $t): bool
    {
        $byAuthor = [];
        foreach ($records as $record) {
            $byAuthor[$record->stamp->pubkey][] = $record;
        }
        $leased = false;
        foreach ($byAuthor as $list) {
            usort($list, static fn(LeaseRecord $a, LeaseRecord $b): int => $a->stamp->replaces($b->stamp) ? -1 : ($b->stamp->replaces($a->stamp) ? 1 : 0));
            foreach ($list as $index => $record) {
                if ($record->holds($t)) {
                    $leased = true;
                    break;
                }
                $olderHolds = false;
                foreach (\array_slice($list, $index + 1) as $older) {
                    $olderHolds = $olderHolds || $older->holds($t);
                }
                if (!$olderHolds || $record->signed()) {
                    break; // That author holds it no more.
                }
            }
        }

        return $leased;
    }

    /**
     * Whether the lease $own (the stamp of a sale's event) is confirmed by $records, a read of its
     * address's records on every relay, $complete when a majority read completely (SPEC.md §31,
     * "Leasing an address", step 3.3): the read is complete, shows the lease, shows it as the
     * newest record of its author there (its signature not checked), and no other author's
     * records lease the address at $t.
     *
     * @param list<LeaseRecord> $records
     */
    public static function isConfirmedBy(array $records, Stamp $own, int $t, bool $complete): bool
    {
        if (!$complete) {
            return false; // What a relay left out cannot be told.
        }
        $shown = false;
        $others = [];
        foreach ($records as $record) {
            if ($record->stamp->id === $own->id) {
                $shown = true;
            } elseif ($record->stamp->pubkey !== $own->pubkey) {
                $others[] = $record;
            } elseif ($record->stamp->replaces($own)) {
                return false; // A newer record of its sale.
            }
        }

        return $shown && !self::held($others, $t);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function record(Sale $sale, Registry $registry, string $address, array $value, int $createdAt, Context $context): Event
    {
        $registryAddress = Derive::depositRegistryAddress($registry->key->reveal(), $address);

        return SealedRecord::seal(
            Protocol::LEASE_TYPE,
            $value,
            $sale->secret->reveal(),
            Derive::outerKey($registry->key->reveal(), $registryAddress, $sale->author),
            $registryAddress,
            $createdAt,
            $context->random(...),
        );
    }

    private static function openRecord(mixed $value, Registry $registry, string $registryAddress): ?SealedRecord
    {
        $author = Json::get($value, 'pubkey');
        if (!Text::isHex32($author)) {
            return null;
        }
        \assert(\is_string($author));

        return SealedRecord::open($value, Protocol::LEASE_TYPE, $registryAddress, $author, Derive::outerKey($registry->key->reveal(), $registryAddress, $author));
    }

    /**
     * @return array{0: 'lease', 1: int, 2: Event}|array{0: 'withdrawn', 1: Event}|array{0: 'newer', 1: Event}|null
     */
    private static function outsideOf(SealedRecord $record): ?array
    {
        if ($record->status === SealedRecord::NEWER) {
            return ['newer', $record->event];
        }
        $value = $record->value;
        if (!$value instanceof \stdClass) {
            return null;
        }
        $version = Json::get($value, 'version');
        if ((\is_int($version) || \is_float($version)) && $version > Protocol::LEASE_VERSION) {
            return ['newer', $record->event];
        }
        if (Json::safeInteger($version) !== Protocol::LEASE_VERSION) {
            return null;
        }
        if (Json::get($value, 'withdrawn') === true) {
            return ['withdrawn', $record->event];
        }
        $end = Json::safeInteger(Json::get($value, 'end'));
        if ($end === null || !\is_string(Json::get($value, 'sealed'))) {
            return null;
        }

        return ['lease', $end, $record->event];
    }

    /** The lease's JSON, checked (SPEC.md §31, "Opening it as the sale"), else null. */
    private static function terms(\stdClass $inside, Event $event, int $end, Registry $registry, string $registryAddress, Sale $sale): ?self
    {
        $address = Json::get($inside, 'address');
        $sats = Json::intIn(Json::get($inside, 'sats'), 1, Protocol::MAX_SATS);
        $invoice = Json::get($inside, 'invoice');
        $verifyUrl = Json::get($inside, 'verifyUrl');
        $createdAt = Json::safeInteger(Json::get($inside, 'createdAt'));
        $expiresAt = Json::safeInteger(Json::get($inside, 'expiresAt'));
        $terms = Declaration::saleTerms(Json::get($inside, 'sale'), $sale);
        // `invoice` and `verifyUrl` both null for an order paid on-chain only; absent, it is no
        // lease (SPEC.md §2: absent is not null).
        if (Json::safeInteger(Json::get($inside, 'version')) !== Protocol::LEASE_VERSION || !DepositAddress::isValid($address)
            || $sats === null || $createdAt === null || $expiresAt === null || $createdAt >= $expiresAt || $terms === null
            || $end !== $expiresAt + Protocol::GRACE_SECONDS || !Json::has($inside, 'invoice') || !Json::has($inside, 'verifyUrl')) {
            return null;
        }
        \assert(\is_string($address));
        if (Derive::depositRegistryAddress($registry->key->reveal(), $address) !== $registryAddress) {
            return null;
        }
        if ($invoice !== null || $verifyUrl !== null) {
            $decoded = Bolt11::decode($invoice);
            if ($decoded === null || $decoded->network !== 'bc' || $decoded->amountMsat !== $sats . '000' || !Url::isHttps($verifyUrl)
                || $decoded->timestamp !== $createdAt || $decoded->expiresAt() !== $expiresAt) {
                return null;
            }
            \assert(\is_string($invoice) && \is_string($verifyUrl));
        }
        $taker = Json::get($inside, 'taker');
        $taker = \is_string($taker) && preg_match('/^[0-9a-f]{32}$/D', $taker) === 1 ? $taker : null;

        return new self(
            $event,
            $address,
            (string) $sats,
            $invoice,
            $verifyUrl,
            $createdAt,
            $expiresAt,
            $terms['fiat'],
            $terms['note'],
            $taker,
            Json::has($inside, 'tolerance') ? Tolerance::read(Json::get($inside, 'tolerance')) : 0,
            Json::get($inside, 'confirmed') === true,
        );
    }
}
