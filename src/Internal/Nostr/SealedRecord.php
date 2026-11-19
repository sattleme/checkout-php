<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Crypto\Nip44Exception;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Loop\Loop;
use Sattleme\Checkout\Internal\Protocol;

/**
 * Sealed records (SPEC.md §12): an event of kind 30078 with a `d` tag and NIP-44 v2 content whose
 * plaintext {"t": <type>, "v": <value>} is padded with spaces to a multiple of 256 bytes.
 *
 * @internal
 */
final class SealedRecord
{
    public const OK = 'ok';
    public const NEWER = 'newer';

    /**
     * @param self::OK|self::NEWER $status
     */
    private function __construct(
        public readonly string $status,
        public readonly Event $event,
        public readonly mixed $value,
    ) {}

    /** The plaintext of a record: its JSON padded with spaces to the next multiple of 256 bytes. */
    public static function plaintext(string $type, #[\SensitiveParameter] mixed $value): string
    {
        $text = Json::encode(['t' => $type, 'v' => $value]);
        $length = \strlen($text);

        return $text . str_repeat(' ', (int) ceil($length / Protocol::RECORD_PADDING) * Protocol::RECORD_PADDING - $length);
    }

    /** Whether the record of $value fits a NIP-44 plaintext (seal() would make it). */
    public static function fits(string $type, #[\SensitiveParameter] mixed $value): bool
    {
        return \strlen(self::plaintext($type, $value)) <= Nip44::MAX_PLAINTEXT;
    }

    /**
     * The signed event of a record.
     *
     * @param list<list<string>> $moreTags tags after the `d` tag
     * @param \Closure(int): string $random the CSPRNG
     */
    public static function seal(
        string $type,
        #[\SensitiveParameter]
        mixed $value,
        #[\SensitiveParameter]
        string $signer,
        #[\SensitiveParameter]
        string $encryptionKey,
        string $address,
        int $createdAt,
        \Closure $random,
        array $moreTags = [],
    ): Event {
        $plaintext = self::plaintext($type, $value);
        if (\strlen($plaintext) > Nip44::MAX_PLAINTEXT) {
            throw new \LengthException('A record too large for NIP-44');
        }
        $content = Nip44::encrypt($plaintext, $encryptionKey, $random(32));

        return Event::sign($signer, Protocol::RECORD_KIND, $createdAt, [['d', $address], ...$moreTags], $content, $random(32));
    }

    /**
     * What a relay sent, as a record of $type at $address by $author under $encryptionKey, in
     * SPEC.md §12's order, the cheap checks first: kind, author, content and `d` tag; decryption;
     * the plaintext's type; the event's id and signature. Null for anything else: a relay is never
     * trusted. The value is returned as it is, for the caller to validate. Without $verify, the
     * signature is left for the caller to check (Event::verify()) where it matters; the id is
     * checked still, so that one id names one event (no copy claiming another's id stands in for it).
     * With $newerContentIgnored, a content of a newer NIP-44 version is no record (null), rather
     * than one of a newer format: for records anybody may write at their `d` tag, where only a
     * value of a newer version, which the record's key seals, is one (SPEC.md §31, "Opening a lease").
     */
    public static function open(mixed $value, string $type, string $address, string $author, #[\SensitiveParameter] string $encryptionKey, bool $verify = true, bool $newerContentIgnored = false): ?self
    {
        if (!self::looksLike($value, $address, $author)) {
            return null;
        }
        \assert($value instanceof \stdClass);
        $content = $value->content;
        \assert(\is_string($content));
        $newer = false;
        $inner = null;
        try {
            $plaintext = Nip44::decrypt($content, $encryptionKey);
            $envelope = Json::decode($plaintext);
            if (!$envelope instanceof \stdClass || !Json::has($envelope, 'v') || Json::get($envelope, 't') !== $type) {
                return null;
            }
            $inner = $envelope->v;
        } catch (Nip44Exception $e) {
            if (!$e->newerFormat || $newerContentIgnored) {
                return null;
            }
            $newer = true;
        } catch (\JsonException) {
            return null;
        }
        $event = Event::fromJson($value);
        if ($event === null) {
            return null;
        }
        if (!$verify) {
            return $event->idMatches() ? new self($newer ? self::NEWER : self::OK, $event, $inner) : null;
        }

        return self::checksOut($event) ? new self($newer ? self::NEWER : self::OK, $event, $inner) : null;
    }

    /**
     * Whether what a relay sent opens as a record of one of $types by $author, at $address (else
     * at its own `d` tag), under the key $keyOf gives for that address: what a read keeps of each
     * event as it comes (SPEC.md §13, Limits), any other being dropped there. The steps of SPEC.md
     * §12, the cheap ones first (kind, author, `d` tag, decryption, type), then its event checked
     * out (§11): a copy of a record under an id of its own, its signature left as it is, is not
     * kept. Each event is checked on its own, whatever else its relay sent; a signature once
     * (Event::signs() remembers it, for open() after the read and the other relays' copies).
     *
     * @param list<string>              $types
     * @param \Closure(string): string  $keyOf the record's encryption key, from its address
     */
    public static function opens(mixed $value, array $types, string $author, \Closure $keyOf, ?string $address = null): bool
    {
        $address ??= self::dTag($value);
        if ($address === null || !self::looksLike($value, $address, $author)) {
            return false;
        }
        \assert($value instanceof \stdClass && \is_string($value->content));
        try {
            $envelope = Json::decode(Nip44::decrypt($value->content, $keyOf($address)));
            if (!$envelope instanceof \stdClass || !Json::has($envelope, 'v') || !\in_array(Json::get($envelope, 't'), $types, true)) {
                return false;
            }
        } catch (Nip44Exception $e) {
            if (!$e->newerFormat) {
                return false;
            }
        } catch (\JsonException) {
            return false;
        }

        $event = Event::fromJson($value);

        return $event !== null && self::checksOut($event);
    }

    /**
     * Whether $event checks out (SPEC.md §11), on its own: what else its relay sent changes
     * nothing. Before the signature check, the one costly step, the other tasks, the deadlines and
     * the Cancellation have their turn; a request's deadline and its 1000 events bound what a
     * relay can make the read check (SPEC.md §13).
     */
    private static function checksOut(Event $event): bool
    {
        Loop::get()->checkpoint();

        return $event->verify();
    }

    /** The first `d` tag's value of what a relay sent when it is hex32, else null. */
    public static function dTag(mixed $value): ?string
    {
        $tags = Json::get($value, 'tags');
        if (!\is_array($tags) || !array_is_list($tags)) {
            return null;
        }
        foreach ($tags as $tag) {
            if (\is_array($tag) && array_is_list($tag) && ($tag[0] ?? null) === 'd') {
                $d = $tag[1] ?? null;

                return \is_string($d) && preg_match('/^[0-9a-f]{64}$/D', $d) === 1 ? $d : null;
            }
        }

        return null;
    }

    /** Step 1 of an opening: kind 30078, the author, a string content and the `d` tag. */
    private static function looksLike(mixed $value, string $address, string $author): bool
    {
        if (!$value instanceof \stdClass || Json::safeInteger(Json::get($value, 'kind')) !== Protocol::RECORD_KIND
            || Json::get($value, 'pubkey') !== $author || !\is_string(Json::get($value, 'content'))) {
            return false;
        }
        $tags = Json::get($value, 'tags');
        if (!\is_array($tags) || !array_is_list($tags)) {
            return false;
        }
        foreach ($tags as $tag) {
            if (\is_array($tag) && array_is_list($tag) && ($tag[0] ?? null) === 'd' && ($tag[1] ?? null) === $address) {
                return true;
            }
        }

        return false;
    }
}
