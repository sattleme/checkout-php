<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\StoredInvoice;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The check of a stored invoice (SPEC.md §22): every member it reads is there, but
 * `onchainAddress` (SPEC.md §2: an absent member is not null). An integrator's JSON without
 * `bolt11`, `paymentHash` or `verifyUrl` is no invoice createInvoice made (review of the PHP port:
 * one with an address was taken as paid on-chain only), whether decoded as arrays or as objects.
 */
final class StoredInvoiceTest extends TestCase
{
    private const ADDRESS = 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function valid(): iterable
    {
        yield 'as createInvoice made it' => [self::lightning()];
        yield 'without an onchainAddress member' => [self::without(self::lightning(), 'onchainAddress')];
        yield 'without a ref member, no address: not read' => [self::without(self::lightning(), 'ref')];
        yield 'with an address' => [['onchainAddress' => self::ADDRESS] + self::lightning()];
        yield 'paid on-chain only' => [self::onchain()];
        yield 'sats with leading zeros, 16 digits' => [['sats' => '0000000000000021'] + self::lightning()];
    }

    /** @param array<string, mixed> $invoice */
    #[DataProvider('valid')]
    public function testAnInvoiceCreateInvoiceMadeIsChecked(array $invoice): void
    {
        foreach ([$invoice, self::object($invoice)] as $stored) {
            $checked = StoredInvoice::check($stored);
            self::assertSame('21', $checked->sats);
            self::assertSame($invoice['onchainAddress'] ?? null, $checked->onchainAddress);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        foreach (['sats', 'expiresAt', 'bolt11', 'paymentHash', 'verifyUrl'] as $member) {
            yield "without {$member}" => [self::without(self::lightning(), $member)];
        }
        // With an address, the members of its Lightning invoice still read null, never absent.
        yield 'on-chain only, no bolt11, paymentHash nor verifyUrl member' => [self::without(self::without(self::without(self::onchain(), 'bolt11'), 'paymentHash'), 'verifyUrl')];
        foreach (['bolt11', 'paymentHash', 'verifyUrl'] as $member) {
            yield "on-chain only, without {$member}" => [self::without(self::onchain(), $member)];
        }
        yield 'an address without a ref member' => [self::without(self::onchain(), 'ref')];
        yield 'an address with ref null' => [['ref' => null] + self::onchain()];
        yield 'sats of 17 digits' => [['sats' => '00000000000000021'] + self::lightning()];
        yield 'sats null' => [['sats' => null] + self::lightning()];
    }

    /** @param array<string, mixed> $invoice */
    #[DataProvider('invalid')]
    public function testAnInvoiceWithoutAMemberItReadsIsRefused(array $invoice): void
    {
        foreach ([$invoice, self::object($invoice)] as $stored) {
            try {
                StoredInvoice::check($stored);
                self::fail('Taken: ' . Json::encode($invoice));
            } catch (InvalidOrderException $e) {
                self::assertSame('invoice', $e->getParam());
            }
        }
    }

    /**
     * The app's real invoice, as createInvoice stores it.
     *
     * @return array<string, mixed>
     */
    private static function lightning(): array
    {
        $real = V::a(V::object('vectors/app/app.json')['realInvoice']);
        $hash = V::s($real['paymentHash']);

        return [
            'ref' => 'ORD-1',
            'bolt11' => V::s($real['invoice']),
            'paymentHash' => $hash,
            'verifyUrl' => 'https://breez.tips/lnurlp/verify/' . $hash,
            'onchainAddress' => null,
            'paymentUri' => 'lightning:' . V::s($real['invoice']),
            'sats' => '21',
            'fiat' => null,
            'rate' => null,
            'note' => 'ORD-1',
            'createdAt' => V::i($real['timestamp']),
            'expiresAt' => V::i($real['timestamp']) + V::i($real['expirySeconds']),
            'checkoutUrl' => null,
            'redirectUrl' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function onchain(): array
    {
        return ['bolt11' => null, 'paymentHash' => null, 'verifyUrl' => null, 'onchainAddress' => self::ADDRESS, 'paymentUri' => 'bitcoin:' . self::ADDRESS . '?amount=0.00000021'] + self::lightning();
    }

    /**
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>
     */
    private static function without(array $invoice, string $member): array
    {
        unset($invoice[$member]);

        return $invoice;
    }

    /** @param array<string, mixed> $invoice */
    private static function object(array $invoice): \stdClass
    {
        $object = Json::decode(Json::encode($invoice));
        \assert($object instanceof \stdClass);

        return $object;
    }
}
