<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Crypto;

use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Text;

/**
 * NIP-44 version 2 (https://github.com/nostr-protocol/nips/blob/master/44.md): ChaCha20 (IETF,
 * counter 0), HMAC-SHA256 of the nonce and the ciphertext, keys of each message from a random
 * 32-byte nonce, padded plaintext. Checked against the official vectors (spec/vectors/nip44/).
 * Decryption runs the reference implementation's checks in its order.
 *
 * @internal
 */
final class Nip44
{
    /** The longest plaintext, in bytes. */
    public const MAX_PLAINTEXT = 65535;

    private const VERSION = 2;
    private const MIN_PLAINTEXT = 1;

    private function __construct() {}

    /**
     * get_conversation_key: HKDF-Extract (salt "nip44-v2") of the unhashed x coordinate of the
     * ECDH of $secret and the x-only $publicKey.
     *
     * @throws \InvalidArgumentException for a secret out of range or no public key
     */
    public static function conversationKey(#[\SensitiveParameter] string $secret, string $publicKey): string
    {
        return Hkdf::extract('nip44-v2', Secp256k1::sharedX($secret, $publicKey));
    }

    /** calc_padded_len. */
    public static function paddedLength(int $length): int
    {
        if ($length <= 32) {
            return 32;
        }
        $nextPower = 1 << (\strlen(decbin($length - 1)));
        $chunk = $nextPower <= 256 ? 32 : intdiv($nextPower, 8);

        return $chunk * (intdiv($length - 1, $chunk) + 1);
    }

    /**
     * The length of the payload of a plaintext of $length bytes (1 to MAX_PLAINTEXT): its base64
     * of the version byte, the nonce, the padded plaintext with its length, and the MAC. What a
     * sealed record holds is sized with it, no random bytes drawn.
     */
    public static function payloadLength(int $length): int
    {
        return 4 * intdiv(1 + 32 + 2 + self::paddedLength($length) + 32 + 2, 3);
    }

    /**
     * get_message_keys: the ChaCha20 key and nonce and the HMAC key of a message.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function messageKeys(#[\SensitiveParameter] string $conversationKey, string $nonce): array
    {
        if (\strlen($conversationKey) !== 32 || \strlen($nonce) !== 32) {
            throw new \InvalidArgumentException('Invalid NIP-44 key or nonce length');
        }
        $keys = Hkdf::expand($conversationKey, $nonce, 76);

        return [substr($keys, 0, 32), substr($keys, 32, 12), substr($keys, 44, 32)];
    }

    /**
     * The payload of $plaintext (1 to 65 535 bytes of UTF-8) under $conversationKey, with $nonce
     * (32 fresh random bytes).
     */
    public static function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $conversationKey, string $nonce): string
    {
        $length = \strlen($plaintext);
        if ($length < self::MIN_PLAINTEXT || $length > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('Invalid NIP-44 plaintext length');
        }
        [$chachaKey, $chachaNonce, $hmacKey] = self::messageKeys($conversationKey, $nonce);
        $padded = pack('n', $length) . $plaintext . str_repeat("\0", self::paddedLength($length) - $length);
        $ciphertext = self::chacha20($chachaKey, $chachaNonce, $padded);

        return Base64::encode(\chr(self::VERSION) . $nonce . $ciphertext . hash_hmac('sha256', $nonce . $ciphertext, $hmacKey, true));
    }

    /**
     * The plaintext of $payload under $conversationKey.
     *
     * @throws Nip44Exception when it does not decrypt; of a newer format when the payload is
     *                        empty, starts with "#" or holds another version
     */
    public static function decrypt(string $payload, #[\SensitiveParameter] string $conversationKey): string
    {
        $length = \strlen($payload);
        if ($length === 0 || $payload[0] === '#') {
            throw new Nip44Exception('Unknown NIP-44 version', true);
        }
        if ($length < 132 || $length > 87472) {
            throw new Nip44Exception('Invalid NIP-44 payload size');
        }
        $data = Base64::decode($payload);
        if ($data === null) {
            throw new Nip44Exception('Invalid NIP-44 base64');
        }
        $size = \strlen($data);
        if ($size < 99 || $size > 65603) {
            throw new Nip44Exception('Invalid NIP-44 data size');
        }
        if (\ord($data[0]) !== self::VERSION) {
            throw new Nip44Exception('Unknown NIP-44 version', true);
        }
        $nonce = substr($data, 1, 32);
        $ciphertext = substr($data, 33, $size - 65);
        [$chachaKey, $chachaNonce, $hmacKey] = self::messageKeys($conversationKey, $nonce);
        if (!hash_equals(hash_hmac('sha256', $nonce . $ciphertext, $hmacKey, true), substr($data, $size - 32))) {
            throw new Nip44Exception('Invalid NIP-44 MAC');
        }
        $padded = self::chacha20($chachaKey, $chachaNonce, $ciphertext);
        $plainLength = unpack('n', $padded);
        $plainLength = \is_array($plainLength) && \is_int($plainLength[1] ?? null) ? $plainLength[1] : 0;
        if ($plainLength < self::MIN_PLAINTEXT || \strlen($padded) !== 2 + self::paddedLength($plainLength)) {
            throw new Nip44Exception('Invalid NIP-44 padding');
        }
        $plaintext = substr($padded, 2, $plainLength);
        if (!Text::isUtf8($plaintext)) {
            throw new Nip44Exception('Invalid NIP-44 plaintext');
        }

        return $plaintext;
    }

    /**
     * ChaCha20 of RFC 8439 with a 12-byte nonce and the block counter starting at 0. OpenSSL's
     * "chacha20" takes a 16-byte IV: the 32-bit counter, little-endian, then the nonce.
     */
    private static function chacha20(#[\SensitiveParameter] string $key, string $nonce, #[\SensitiveParameter] string $data): string
    {
        while (openssl_error_string() !== false) {
        }
        $output = openssl_encrypt($data, 'chacha20', $key, \OPENSSL_RAW_DATA, "\0\0\0\0" . $nonce);
        while (openssl_error_string() !== false) {
        }
        if (!\is_string($output) || \strlen($output) !== \strlen($data)) {
            throw new \LogicException('OpenSSL ChaCha20 failed');
        }

        return $output;
    }
}
