<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Nostr;

use Sattleme\Checkout\Internal\Crypto\Schnorr;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Text;

/**
 * A NIP-01 event (SPEC.md §11). One read from a relay is checked before use: its shape first
 * (fromJson), then its id and signature (verify), the expensive part.
 *
 * @internal
 */
final class Event
{
    /** The signature checks remembered, at most: each is a few bytes, and one takes 3 to 5 ms. */
    private const MAX_REMEMBERED = 4096;

    /**
     * The verdicts of the last signature checks, by SHA-256 of the key, the id and the signature:
     * a record is checked as it comes (SPEC.md §13, Limits), then opened, and each relay sends its
     * copy of it; it is checked once. A verdict depends on these alone.
     *
     * @var array<string, bool>
     */
    private static array $checked = [];

    /**
     * @param list<list<string>> $tags
     */
    private function __construct(
        public readonly string $id,
        public readonly string $pubkey,
        public readonly int $createdAt,
        public readonly int $kind,
        public readonly array $tags,
        public readonly string $content,
        public readonly string $sig,
    ) {}

    /**
     * $value as an event when it has the shape SPEC.md §11 checks: id and pubkey hex32, sig 128
     * lowercase hex characters, created_at a safe integer, kind an integer (a record's is 30078:
     * any other is no event of ours), content a string, tags an array of arrays of strings. The
     * id and the signature are not checked here.
     */
    public static function fromJson(mixed $value): ?self
    {
        if (!$value instanceof \stdClass) {
            return null;
        }
        $id = Json::get($value, 'id');
        $pubkey = Json::get($value, 'pubkey');
        $sig = Json::get($value, 'sig');
        $content = Json::get($value, 'content');
        $tags = Json::get($value, 'tags');
        $createdAt = Json::safeInteger(Json::get($value, 'created_at'));
        $kind = Json::safeInteger(Json::get($value, 'kind'));
        if (!Text::isHex32($id) || !Text::isHex32($pubkey) || !Text::isHex($sig, 64) || !\is_string($content)
            || $createdAt === null || $kind === null || !self::isTags($tags)) {
            return null;
        }
        \assert(\is_string($id) && \is_string($pubkey) && \is_string($sig) && \is_array($tags));

        /** @var list<list<string>> $tags */
        return new self($id, $pubkey, $createdAt, $kind, $tags, $content, $sig);
    }

    /** Whether $tags is an array of arrays of strings. */
    public static function isTags(mixed $tags): bool
    {
        if (!Json::isArray($tags)) {
            return false;
        }
        \assert(\is_array($tags));
        foreach ($tags as $tag) {
            if (!Json::isArray($tag)) {
                return false;
            }
            \assert(\is_array($tag));
            foreach ($tag as $item) {
                if (!\is_string($item)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A signed event of $secret, with 32 fresh random bytes of auxiliary data.
     *
     * @param list<list<string>> $tags
     */
    public static function sign(#[\SensitiveParameter] string $secret, int $kind, int $createdAt, array $tags, string $content, string $auxiliary): self
    {
        $pubkey = bin2hex(Secp256k1::xOnlyPublicKey($secret));
        $id = self::hash($pubkey, $createdAt, $kind, $tags, $content);
        $sig = bin2hex(Schnorr::sign($secret, (string) hex2bin($id), $auxiliary));

        return new self($id, $pubkey, $createdAt, $kind, $tags, $content, $sig);
    }

    /** Whether the id is the hash of the event and the signature verifies. */
    public function verify(): bool
    {
        return $this->idMatches() && self::signs($this->pubkey, $this->id, $this->sig);
    }

    /** Whether $sig (hex) is $pubkey's signature (hex) of the id $id (hex): BIP-340, remembered. */
    public static function signs(string $pubkey, string $id, string $sig): bool
    {
        $key = hash('sha256', $pubkey . $id . $sig, true);
        if (!isset(self::$checked[$key])) {
            if (\count(self::$checked) >= self::MAX_REMEMBERED) {
                self::$checked = [];
            }
            self::$checked[$key] = Schnorr::verify((string) hex2bin($pubkey), (string) hex2bin($id), (string) hex2bin($sig));
        }

        return self::$checked[$key];
    }

    /** Whether its id is the hash of its fields (a hash: no signature check). */
    public function idMatches(): bool
    {
        return $this->id === $this->computedId();
    }

    /** The id of its fields: SHA-256 of their serialization(), hex. */
    public function computedId(): string
    {
        return hash('sha256', $this->serialization());
    }

    /**
     * What its id hashes (SPEC.md §11): [0, pubkey, created_at, kind, tags, content] as NIP-01
     * serializes it, without whitespace, its numbers integers (as it was read), its strings as
     * JavaScript's JSON.stringify writes them (Json::encode()).
     */
    public function serialization(): string
    {
        return self::serialize($this->pubkey, $this->createdAt, $this->kind, $this->tags, $this->content);
    }

    /** The first value of the tags named $name, or null. */
    public function tag(string $name): ?string
    {
        foreach ($this->tags as $tag) {
            if (($tag[0] ?? null) === $name && isset($tag[1])) {
                return $tag[1];
            }
        }

        return null;
    }

    /** Whether the event has a tag [$name, $value, …]. */
    public function hasTag(string $name, string $value): bool
    {
        foreach ($this->tags as $tag) {
            if (($tag[0] ?? null) === $name && ($tag[1] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /** The event as a JSON object, to publish. */
    public function toJson(): \stdClass
    {
        return (object) [
            'id' => $this->id,
            'pubkey' => $this->pubkey,
            'created_at' => $this->createdAt,
            'kind' => $this->kind,
            'tags' => $this->tags,
            'content' => $this->content,
            'sig' => $this->sig,
        ];
    }

    /** Whether this event replaces $other as relays keep them: later, then the lowest id. */
    public function replaces(self $other): bool
    {
        return $this->createdAt > $other->createdAt || ($this->createdAt === $other->createdAt && strcmp($this->id, $other->id) < 0);
    }

    /**
     * The event id: SHA-256 of [0, pubkey, created_at, kind, tags, content] serialized as NIP-01.
     *
     * @param list<list<string>> $tags
     */
    private static function hash(string $pubkey, int $createdAt, int $kind, array $tags, string $content): string
    {
        return hash('sha256', self::serialize($pubkey, $createdAt, $kind, $tags, $content));
    }

    /** @param list<list<string>> $tags */
    private static function serialize(string $pubkey, int $createdAt, int $kind, array $tags, string $content): string
    {
        return Json::encode([0, $pubkey, $createdAt, $kind, $tags, $content]);
    }
}
