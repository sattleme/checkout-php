<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\Internal\Crypto\Hkdf;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Crypto\Secp256k1;

/**
 * Every key derivation of the protocol (SPEC.md §10, §30, §31). They never change: the merchant's
 * app derives the same. Raw bytes unless the name says hex; infos are written as they are (ASCII).
 *
 * @internal
 */
final class Derive
{
    private function __construct() {}

    /** channel address (d): hex. */
    public static function channelAddress(#[\SensitiveParameter] string $integrationKey): string
    {
        return bin2hex(self::integration($integrationKey, 'channel/address/v1'));
    }

    public static function channelEncryptionKey(#[\SensitiveParameter] string $integrationKey): string
    {
        return self::integration($integrationKey, 'channel/encryption-key/v1');
    }

    public static function takerKey(#[\SensitiveParameter] string $integrationKey): string
    {
        return self::integration($integrationKey, 'taker/v1');
    }

    public static function saleKey(#[\SensitiveParameter] string $integrationKey, string $ref): string
    {
        return self::integration($integrationKey, "sale/{$ref}/v1");
    }

    public static function saleSecret(#[\SensitiveParameter] string $saleKey): string
    {
        return Hkdf::derive($saleKey, Protocol::SALE_SALT, 'nostr-secret-key/v1');
    }

    /** hex of the x-only public key of a secret (the sale author, the seen author…). */
    public static function author(#[\SensitiveParameter] string $secret): string
    {
        return bin2hex(Secp256k1::xOnlyPublicKey($secret));
    }

    /** taker tag: hex of the first 16 bytes of HMAC(taker key, bytes of the sale author). */
    public static function takerTag(#[\SensitiveParameter] string $takerKey, string $saleAuthorHex): string
    {
        return bin2hex(substr(hash_hmac('sha256', (string) hex2bin($saleAuthorHex), $takerKey, true), 0, 16));
    }

    /** registry address of a payment hash (hex): hex. */
    public static function registryAddress(#[\SensitiveParameter] string $registryKey, string $paymentHashHex): string
    {
        return bin2hex(hash_hmac('sha256', (string) hex2bin($paymentHashHex), $registryKey, true));
    }

    /** registry address of a deposit address: hex. */
    public static function depositRegistryAddress(#[\SensitiveParameter] string $registryKey, string $address): string
    {
        return bin2hex(hash_hmac('sha256', 'onchain/v1/' . $address, $registryKey, true));
    }

    /** outer key of the records of $authorHex at the registry address $addressHex. */
    public static function outerKey(#[\SensitiveParameter] string $registryKey, string $addressHex, string $authorHex): string
    {
        return Hkdf::derive($registryKey, Protocol::DECLARATION_SALT, "outer/v1/{$addressHex}/{$authorHex}");
    }

    /** inner key: NIP-44 conversation key of the sale secret and the registry reader (hex). */
    public static function innerKey(#[\SensitiveParameter] string $saleSecret, string $registryReaderHex): string
    {
        return Nip44::conversationKey($saleSecret, (string) hex2bin($registryReaderHex));
    }

    /** lot address (d): hex. */
    public static function lotAddress(#[\SensitiveParameter] string $integrationKey): string
    {
        return bin2hex(self::integration($integrationKey, 'onchain-lot/address/v1'));
    }

    public static function seenSecret(#[\SensitiveParameter] string $integrationKey): string
    {
        return self::integration($integrationKey, 'onchain-seen/nostr-secret-key/v1');
    }

    public static function seenAddressKey(#[\SensitiveParameter] string $integrationKey): string
    {
        return self::integration($integrationKey, 'onchain-seen/address-key/v1');
    }

    /** witness address of an address, a transaction and a sale author (null: none): hex. */
    public static function witnessAddress(#[\SensitiveParameter] string $seenAddressKey, string $address, string $txid, ?string $sale): string
    {
        return bin2hex(hash_hmac('sha256', $address . '/' . $txid . '/' . ($sale ?? 'none'), $seenAddressKey, true));
    }

    /** order tag of an address and a sale author (null: none): hex. */
    public static function orderTag(#[\SensitiveParameter] string $seenAddressKey, string $address, ?string $sale): string
    {
        return bin2hex(hash_hmac('sha256', 'order/' . $address . '/' . ($sale ?? 'none'), $seenAddressKey, true));
    }

    /** address tag of a deposit address: a witness's second `o` tag, every witness of the address. */
    public static function addressTag(#[\SensitiveParameter] string $seenAddressKey, string $address): string
    {
        return bin2hex(hash_hmac('sha256', 'address/' . $address, $seenAddressKey, true));
    }

    /**
     * merchant's acceptance address of an order's invoice (SPEC.md §31, "The merchant's
     * acceptance"): its sale author, its deposit address and its end, written as they are: hex.
     */
    public static function merchantAcceptanceAddress(#[\SensitiveParameter] string $integrationKey, string $saleAuthor, string $address, int $expiresAt): string
    {
        return bin2hex(self::integration($integrationKey, "onchain-accepted/{$saleAuthor}/{$address}/{$expiresAt}/v1"));
    }

    /** rest key of an order's invoice: its ref, its deposit address, its end. */
    public static function restKey(#[\SensitiveParameter] string $integrationKey, string $ref, string $address, int $expiresAt): string
    {
        return self::integration($integrationKey, "checkout-rest/{$ref}/{$address}/{$expiresAt}/key/v1");
    }

    public static function restSecret(#[\SensitiveParameter] string $integrationKey, string $ref, string $address, int $expiresAt): string
    {
        return self::integration($integrationKey, "checkout-rest/{$ref}/{$address}/{$expiresAt}/nostr-secret-key/v1");
    }

    public static function restEncryptionKey(#[\SensitiveParameter] string $restKey): string
    {
        return Hkdf::derive($restKey, Protocol::CHECKOUT_SALT, 'rest/encryption-key/v1');
    }

    /** rest record address of a payment hash (hex): hex. */
    public static function restRecordAddress(#[\SensitiveParameter] string $restKey, string $paymentHashHex): string
    {
        return bin2hex(Hkdf::derive($restKey, Protocol::CHECKOUT_SALT, "rest/address/{$paymentHashHex}/v1"));
    }

    /** acceptance record address: hex. */
    public static function acceptanceAddress(#[\SensitiveParameter] string $restKey): string
    {
        return bin2hex(Hkdf::derive($restKey, Protocol::CHECKOUT_SALT, 'rest/accepted/v1'));
    }

    /** link address (d) of a checkout link: hex. */
    public static function linkAddress(#[\SensitiveParameter] string $linkKey): string
    {
        return bin2hex(Hkdf::derive($linkKey, Protocol::CHECKOUT_SALT, 'address/v1'));
    }

    public static function linkEncryptionKey(#[\SensitiveParameter] string $linkKey): string
    {
        return Hkdf::derive($linkKey, Protocol::CHECKOUT_SALT, 'encryption-key/v1');
    }

    private static function integration(#[\SensitiveParameter] string $integrationKey, string $info): string
    {
        return Hkdf::derive($integrationKey, Protocol::INTEGRATION_SALT, $info);
    }
}
