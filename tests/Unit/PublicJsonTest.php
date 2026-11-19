<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\Fiat;
use Sattleme\Checkout\Invoice;
use Sattleme\Checkout\Merchant;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\RateInfo;
use Sattleme\Checkout\Rest;

/**
 * SPEC.md §3: every object an integrator stores or holds is written as the JSON there, with those
 * field names, in that order, absent values null, never left out. The README stores an invoice with
 * json_encode(), PHP's own serializer: each type an integrator may hold gives §3's JSON to it, as
 * toArray() gives it, whatever the type it is held as.
 */
final class PublicJsonTest extends TestCase
{
    private const ADDRESS = 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4';

    private const HASH = 'ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb';

    /** @return iterable<string, array{0: object, 1: string}> */
    public static function values(): iterable
    {
        $fiat = new Fiat('24.50', 'CHF', '2450', 2);
        $rate = new RateInfo('coingecko', 'CHF', '69012.5', 1791378000, null);
        $rest = new Rest('29000', 'lnbc290u1rest', 'bitcoin:' . self::ADDRESS . '?amount=0.00029&lightning=lnbc290u1rest');
        yield 'an Invoice' => [
            new Invoice('ORD-1042', 'lnbc490u1x', self::HASH, 'https://breez.tips/lnurlp/verify/x', self::ADDRESS, 'bitcoin:' . self::ADDRESS . '?amount=0.00049&lightning=lnbc490u1x', '49000', $fiat, $rate, 'Order 1042', 1791378000, 1791378900, 'https://pay.example/pay#c.x', 'https://shop.example/thanks'),
            '{"ref":"ORD-1042","bolt11":"lnbc490u1x","paymentHash":"' . self::HASH . '","verifyUrl":"https://breez.tips/lnurlp/verify/x","onchainAddress":"' . self::ADDRESS . '","paymentUri":"bitcoin:' . self::ADDRESS . '?amount=0.00049&lightning=lnbc490u1x","sats":"49000","fiat":{"amount":"24.50","currency":"CHF","minor":"2450","digits":2},"rate":{"source":"coingecko","currency":"CHF","price":"69012.5","fetchedAt":1791378000,"time":null},"note":"Order 1042","createdAt":1791378000,"expiresAt":1791378900,"checkoutUrl":"https://pay.example/pay#c.x","redirectUrl":"https://shop.example/thanks"}',
        ];
        yield 'an Invoice paid on-chain only, in sats' => [
            new Invoice('ORD-1043', null, null, null, self::ADDRESS, 'bitcoin:' . self::ADDRESS . '?amount=0.00049', '49000', null, null, 'ORD-1043', 1791378000, 1791378900, null, null),
            '{"ref":"ORD-1043","bolt11":null,"paymentHash":null,"verifyUrl":null,"onchainAddress":"' . self::ADDRESS . '","paymentUri":"bitcoin:' . self::ADDRESS . '?amount=0.00049","sats":"49000","fiat":null,"rate":null,"note":"ORD-1043","createdAt":1791378000,"expiresAt":1791378900,"checkoutUrl":null,"redirectUrl":null}',
        ];
        yield 'a DeclaredInvoice' => [
            new DeclaredInvoice('ORD-1042', 'lnbc490u1x', self::HASH, 'https://breez.tips/lnurlp/verify/x', null, 'lightning:lnbc490u1x', '49000', $fiat, 'Order 1042', 1791378000, 1791378900, 1791378001),
            '{"ref":"ORD-1042","bolt11":"lnbc490u1x","paymentHash":"' . self::HASH . '","verifyUrl":"https://breez.tips/lnurlp/verify/x","onchainAddress":null,"paymentUri":"lightning:lnbc490u1x","sats":"49000","fiat":{"amount":"24.50","currency":"CHF","minor":"2450","digits":2},"note":"Order 1042","createdAt":1791378000,"expiresAt":1791378900,"declaredAt":1791378001}',
        ];
        yield 'a Fiat' => [$fiat, '{"amount":"24.50","currency":"CHF","minor":"2450","digits":2}'];
        yield 'a RateInfo' => [new RateInfo('kraken', 'EUR', '61234.1', 1791378000, 1791377990), '{"source":"kraken","currency":"EUR","price":"61234.1","fetchedAt":1791378000,"time":1791377990}'];
        yield 'a Rest' => [$rest, '{"sats":"29000","bolt11":"lnbc290u1rest","paymentUri":"bitcoin:' . self::ADDRESS . '?amount=0.00029&lightning=lnbc290u1rest"}'];
        yield 'a Rest without its invoice' => [new Rest('500', null, null), '{"sats":"500","bolt11":null,"paymentUri":null}'];
        yield 'a status open' => [PaymentStatus::open(1791379180, '0', null), '{"state":"open","expiresAt":1791379180,"received":"0","rest":null}'];
        yield 'a status open, underpaid' => [PaymentStatus::open(1791379180, '20000', $rest), '{"state":"open","expiresAt":1791379180,"received":"20000","rest":{"sats":"29000","bolt11":"lnbc290u1rest","paymentUri":"bitcoin:' . self::ADDRESS . '?amount=0.00029&lightning=lnbc290u1rest"}}'];
        yield 'a status unconfirmed' => [PaymentStatus::unconfirmed('49000', 1, [self::HASH]), '{"state":"unconfirmed","received":"49000","confirmations":1,"txids":["' . self::HASH . '"]}'];
        yield 'a status paid by Lightning' => [PaymentStatus::paidByLightning(self::HASH), '{"state":"paid","method":"lightning","preimage":"' . self::HASH . '"}'];
        yield 'a status paid on-chain' => [PaymentStatus::paidOnchain('49000', [self::HASH]), '{"state":"paid","method":"onchain","received":"49000","txids":["' . self::HASH . '"]}'];
        yield 'a status paid both ways' => [PaymentStatus::paidMixed('49000', [self::HASH], [self::HASH]), '{"state":"paid","method":"mixed","received":"49000","txids":["' . self::HASH . '"],"preimages":["' . self::HASH . '"]}'];
        yield 'a status paid by rest invoices alone' => [PaymentStatus::paidMixed('49000', [], [self::HASH]), '{"state":"paid","method":"mixed","received":"49000","txids":[],"preimages":["' . self::HASH . '"]}'];
        yield 'a status expired' => [PaymentStatus::expired('0'), '{"state":"expired","received":"0"}'];
        yield 'a Merchant' => [new Merchant('Sunrise Bakery', '1', '4000000', 3), '{"businessName":"Sunrise Bakery","minSats":"1","maxSats":"4000000","onchainAddresses":3}'];
    }

    #[DataProvider('values')]
    public function testItsJsonIsTheSpecs(object $value, string $json): void
    {
        self::assertSame($json, json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        // As PHP writes it by default (slashes escaped): the same JSON value.
        self::assertSame(json_decode($json, true), json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true));
        // Held as any object, or within one: the same.
        $held = (object) ['value' => $value];
        self::assertSame('{"value":' . $json . '}', json_encode($held, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        self::assertTrue(method_exists($value, 'toArray'));
        self::assertSame(json_decode($json, true), $value->toArray());
    }
}
