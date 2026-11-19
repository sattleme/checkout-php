<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * Every key derivation (SPEC.md §10, §30, §31) against the merchant's app (vectors/app/) and the
 * vectors of checkout links, leases, witnesses and rests.
 */
final class DerivationVectorsTest extends TestCase
{
    public function testTheAppsDerivations(): void
    {
        $app = V::object('vectors/app/app.json');
        $key = IntegrationKey::parse($app['secretKey']);
        $ik = $key->key->reveal();
        $parsed = V::a($app['parsed']);
        $derived = V::a($app['derived']);
        self::assertSame($parsed['team'], $key->team);
        self::assertSame($parsed['integrationKey'], bin2hex($ik));
        self::assertSame($parsed['relays'], $key->relays);
        self::assertSame($derived['channelAddress'], Derive::channelAddress($ik));
        self::assertSame($derived['channelEncryptionKey'], bin2hex(Derive::channelEncryptionKey($ik)));
        self::assertSame($derived['takerKey'], bin2hex(Derive::takerKey($ik)));

        $declaration = V::a($app['declaration']);
        $saleKey = Derive::saleKey($ik, V::s($declaration['ref']));
        self::assertSame($declaration['saleKey'], bin2hex($saleKey));
        $saleSecret = Derive::saleSecret($saleKey);
        self::assertSame($declaration['saleSecret'], bin2hex($saleSecret));
        $saleAuthor = Derive::author($saleSecret);
        self::assertSame($declaration['saleAuthor'], $saleAuthor);
        self::assertSame($declaration['taker'], Derive::takerTag(Derive::takerKey($ik), $saleAuthor));

        $channel = V::a(V::a(V::a($app['channel'])['value'])['v']);
        $registryKey = V::bytes($channel['registryKey']);
        $address = Derive::registryAddress($registryKey, V::s($declaration['paymentHash']));
        self::assertSame($declaration['address'], $address);
        self::assertSame($declaration['outerKey'], bin2hex(Derive::outerKey($registryKey, $address, $saleAuthor)));
        self::assertSame($declaration['innerConversationKey'], bin2hex(Derive::innerKey($saleSecret, V::s($channel['registryReader']))));

        $lot = V::object('vectors/app/onchain-lot.json');
        self::assertSame($lot['lotAddress'], Derive::lotAddress($ik));
        self::assertSame($lot['channelEncryptionKey'], bin2hex(Derive::channelEncryptionKey($ik)));
    }

    public function testCheckoutLinkDerivations(): void
    {
        $derivations = V::a(V::object('vectors/checkout-links.json')['derivations']);
        self::assertNotEmpty($derivations);
        foreach ($derivations as $case) {
            $case = V::a($case);
            $linkKey = V::bytes($case['linkKey']);
            self::assertSame($case['address'], Derive::linkAddress($linkKey));
            self::assertSame($case['encryptionKey'], bin2hex(Derive::linkEncryptionKey($linkKey)));
        }
    }

    public function testDepositRegistryAddressesAndOuterKeys(): void
    {
        $registry = V::a(V::object('vectors/onchain-addresses.json')['registry']);
        $registryKey = V::bytes($registry['registryKey']);
        self::assertNotEmpty($registry['cases']);
        foreach (V::a($registry['cases']) as $case) {
            $case = V::a($case);
            self::assertSame($case['registryAddress'], Derive::depositRegistryAddress($registryKey, V::s($case['address'])));
        }
        $leases = V::object('vectors/onchain-leases.json');
        $registryKey = V::bytes($leases['registryKey']);
        $saleSecret = V::bytes($leases['saleSecret']);
        self::assertSame($leases['saleAuthor'], Derive::author($saleSecret));
        self::assertSame($leases['innerConversationKey'], bin2hex(Derive::innerKey($saleSecret, V::s($leases['registryReader']))));
        foreach (V::a($leases['derived']) as $case) {
            $case = V::a($case);
            self::assertSame($case['registryAddress'], Derive::depositRegistryAddress($registryKey, V::s($case['address'])));
            self::assertSame($case['outerKey'], bin2hex(Derive::outerKey($registryKey, V::s($case['registryAddress']), V::s($leases['saleAuthor']))));
        }
    }

    public function testWitnessDerivations(): void
    {
        $witnesses = V::object('vectors/onchain-witnesses.json');
        $ik = V::bytes($witnesses['integrationKey']);
        $derived = V::a($witnesses['derived']);
        $seenSecret = Derive::seenSecret($ik);
        self::assertSame($derived['seenSecret'], bin2hex($seenSecret));
        self::assertSame($derived['seenAuthor'], Derive::author($seenSecret));
        $seenAddressKey = Derive::seenAddressKey($ik);
        self::assertSame($derived['seenAddressKey'], bin2hex($seenAddressKey));
        self::assertSame($witnesses['channelEncryptionKey'], bin2hex(Derive::channelEncryptionKey($ik)));
        foreach (V::a($witnesses['addresses']) as $case) {
            $case = V::a($case);
            self::assertSame($case['witnessAddress'], Derive::witnessAddress($seenAddressKey, V::s($case['address']), V::s($case['txid']), V::ns($case['sale'])));
        }
        foreach (V::a($witnesses['orderTags']) as $case) {
            $case = V::a($case);
            self::assertSame($case['orderTag'], Derive::orderTag($seenAddressKey, V::s($case['address']), V::ns($case['sale'])));
        }
    }

    public function testRestDerivations(): void
    {
        $rests = V::object('vectors/onchain-rests.json');
        $ik = V::bytes($rests['integrationKey']);
        $keys = V::a($rests['keys']);
        self::assertCount(4, $keys);
        foreach ($keys as $case) {
            $case = V::a($case);
            [$ref, $address, $expiresAt] = [V::s($case['ref']), V::s($case['address']), V::i($case['expiresAt'])];
            $restKey = Derive::restKey($ik, $ref, $address, $expiresAt);
            self::assertSame($case['restKey'], bin2hex($restKey));
            $restSecret = Derive::restSecret($ik, $ref, $address, $expiresAt);
            self::assertSame($case['restSecret'], bin2hex($restSecret));
            self::assertSame($case['restAuthor'], Derive::author($restSecret));
            self::assertSame($case['restEncryptionKey'], bin2hex(Derive::restEncryptionKey($restKey)));
            self::assertSame($case['acceptanceAddress'], Derive::acceptanceAddress($restKey));
            foreach (V::a($case['records']) as $record) {
                $record = V::a($record);
                self::assertSame($record['address'], Derive::restRecordAddress($restKey, V::s($record['paymentHash'])));
            }
        }
    }
}
