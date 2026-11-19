<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Base64;
use Sattleme\Checkout\Internal\Checkout\CheckoutLink;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\SealedRecord;
use Sattleme\Checkout\Internal\Onchain\Combined;
use Sattleme\Checkout\Internal\Onchain\RestInvoice;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\RestPart;
use Sattleme\Checkout\Internal\Onchain\RestRecord;
use Sattleme\Checkout\Internal\Order;
use Sattleme\Checkout\Internal\Pricing;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The checkout links made by an implementation, which the merchant's web app reads
 * (vectors/app/checkout-link.json, vectors/app/checkout-rest.json): PORTING.md, definition of
 * done, point 3.
 */
final class AppCheckoutVectorsTest extends TestCase
{
    /** PORTING.md, definition of done, point 3. */
    public function testOurLinkOfTheOrderHoldsExactlyTheVectorsValue(): void
    {
        $vector = V::object('vectors/app/checkout-link.json');
        $order = Order::check(V::o($vector['order']), ['coingecko']);
        \assert($order->fiat !== null);
        $fiat = ['minor' => (int) $order->fiat->minor, 'digits' => $order->fiat->digits, 'currency' => $order->fiat->currency];
        $value = CheckoutLink::valueV1(V::s($vector['businessName']), V::s($vector['invoice']), V::s($vector['verifyUrl']), $fiat, $order->label, $order->redirectUrl);
        self::assertSame(Json::encode($vector['value']), Json::encode($value), 'exactly the vector\'s value, in its order');

        $context = TestContext::create(new TestConnector());
        $linkKey = random_bytes(32);
        $secret = $context->randomSecret();
        $event = CheckoutLink::event($value, $linkKey, $secret, V::i(V::o($vector['event'])['created_at']), V::i($vector['expiresAt']), $context->random(...));
        /** @var list<string> $relays */
        $relays = V::l($vector['relays']);
        $url = CheckoutLink::url(V::s($vector['origin']), $event->pubkey, $linkKey, $relays);

        // What the web app does: the URL's fragment, then the record.
        $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
        self::assertSame('link', $fragment[0]);
        self::assertSame($event->pubkey, $fragment[1]);
        self::assertSame($relays, $fragment[3]);
        self::assertTrue($event->verify());
        self::assertSame(30078, $event->kind);
        self::assertSame([['d', Derive::linkAddress($fragment[2])], ['expiration', (string) (V::i($vector['expiresAt']) + 604800)]], $event->tags);
        $plaintext = Nip44::decrypt($event->content, Derive::linkEncryptionKey($fragment[2]));
        self::assertSame(0, \strlen($plaintext) % 256);
        $record = SealedRecord::open($event->toJson(), 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
        self::assertNotNull($record);
        self::assertSame(Json::encode($vector['value']), Json::encode($record->value));
        self::assertSame('ok', CheckoutLink::read($record->value)[0] ?? null);
        self::assertStringStartsWith('https://pay.example/pay#c.', $url);
    }

    public function testTheVectorsLinkOpensWithItsKeys(): void
    {
        $vector = V::object('vectors/app/checkout-link.json');
        $url = V::s($vector['url']);
        $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
        self::assertSame('link', $fragment[0]);
        self::assertSame($vector['author'], $fragment[1]);
        self::assertSame($vector['linkKey'], bin2hex($fragment[2]));
        self::assertSame($vector['address'], Derive::linkAddress($fragment[2]));
        self::assertSame($vector['encryptionKey'], bin2hex(Derive::linkEncryptionKey($fragment[2])));
        $record = SealedRecord::open(V::json('vectors/app/checkout-link.json', ['event']), 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
        self::assertNotNull($record);
        self::assertSame(Json::encode($vector['value']), Json::encode($record->value));
        $read = CheckoutLink::read($record->value);
        self::assertNotNull($read);
        self::assertSame('ok', $read[0]);
        self::assertSame(V::s($vector['sats']), $read[1]['sats']);
        self::assertSame($vector['paymentHash'], $read[1]['paymentHash'] ?? null);
        self::assertSame('https://pay.example/pay#c.' . Base64::encodeUrl((string) hex2bin(V::s($vector['author']))) . '.' . Base64::encodeUrl($fragment[2]) . '.cmVsYXkuZXhhbXBsZQ', $url);
    }

    public function testOurLinkAndRestRecordOfAnUnderpaidOrder(): void
    {
        $vector = V::object('vectors/app/checkout-rest.json');
        // The vector's integration key, with a team of its own (the rest keys derive from the key alone).
        $team = V::s(V::o(V::object('vectors/app/app.json')['parsed'])['team']);
        $key = IntegrationKey::parse('sattleme_ck_' . Base64::encodeUrl((string) hex2bin($team)) . '.' . Base64::encodeUrl((string) hex2bin(V::s($vector['integrationKey']))) . '.cmVsYXkuZXhhbXBsZQ');
        $order = Order::check(V::o($vector['order']), []);
        $link = V::o($vector['link']);
        $address = V::s($vector['onchainAddress']);
        $expiresAt = V::i($vector['expiresAt']);
        $rest = V::o($vector['rest']);
        $keys = RestKeys::of($key, $order->ref, $address, $expiresAt);
        self::assertSame($rest['restKey'], bin2hex($keys->key->reveal()));
        self::assertSame($rest['restAuthor'], $keys->author);
        self::assertSame($rest['encryptionKey'], bin2hex($keys->encryptionKey->reveal()));
        self::assertSame($rest['address'], $keys->recordAddress(V::s($rest['paymentHash'])));

        // The link's record: exactly the vector's value.
        $linkValue = V::o($link['value']);
        $value = CheckoutLink::valueV2(
            'Sunrise Bakery',
            V::s($linkValue['invoice']),
            V::s($linkValue['verifyUrl']),
            $address,
            (int) $order->sats,
            V::i($vector['createdAt']),
            $expiresAt,
            null,
            $order->label,
            $order->redirectUrl,
            [$keys->author, bin2hex($keys->key->reveal())],
        );
        self::assertSame(Json::encode($linkValue), Json::encode($value));
        $url = V::s($link['url']);
        $fragment = CheckoutLink::fragment(substr($url, (int) strpos($url, '#')));
        self::assertSame('link', $fragment[0]);
        $record = SealedRecord::open(V::json('vectors/app/checkout-rest.json', ['link', 'event']), 'checkout', Derive::linkAddress($fragment[2]), $fragment[1], Derive::linkEncryptionKey($fragment[2]));
        self::assertNotNull($record);
        self::assertSame(Json::encode($linkValue), Json::encode($record->value));
        $read = CheckoutLink::read($record->value);
        self::assertNotNull($read);
        self::assertSame('ok', $read[0]);
        self::assertSame(['author' => $keys->author, 'key' => bin2hex($keys->key->reveal())], $read[1]['rest'] ?? null);

        // Its rest record: ours opens as the vector's does, for the page.
        $context = TestContext::create(new TestConnector());
        $restEvent = V::o($rest['event']);
        $ours = RestRecord::event($keys, V::s($rest['invoice']), V::s($rest['verifyUrl']), V::s($rest['paymentHash']), $expiresAt, V::i($restEvent['created_at']), $context);
        self::assertSame($restEvent['tags'], $ours->tags);
        self::assertSame($restEvent['pubkey'], $ours->pubkey);
        $page = RestKeys::ofLink($keys->author, $keys->key->reveal());
        $expected = ['invoice' => $rest['invoice'], 'paymentHash' => $rest['paymentHash'], 'verifyUrl' => $rest['verifyUrl'], 'sats' => $rest['sats']];
        $sats = V::s($vector['sats']);
        \assert(is_numeric($sats));
        self::assertEquals(['ok', $expected], RestRecord::read($ours->toJson(), $page, $sats, V::i($vector['createdAt']), $expiresAt));
        self::assertEquals(['ok', $expected], RestRecord::read(V::json('vectors/app/checkout-rest.json', ['rest', 'event']), $page, $sats, V::i($vector['createdAt']), $expiresAt));
        $theirs = SealedRecord::open(V::json('vectors/app/checkout-rest.json', ['rest', 'event']), 'checkout-rest', V::s($rest['address']), $keys->author, $keys->encryptionKey->reveal());
        self::assertNotNull($theirs);
        self::assertSame(Json::encode($rest['value']), Json::encode(['version' => 1, 'invoice' => $rest['invoice'], 'verifyUrl' => $rest['verifyUrl']]));
        self::assertSame(Json::encode($rest['value']), Json::encode($theirs->value));

        // The status the check gave: open, 20 000 received, the rest and its invoice.
        $restSats = V::s($rest['sats']);
        \assert(is_numeric($restSats));
        $restInvoice = new RestInvoice(V::s($rest['invoice']), V::s($rest['paymentHash']), V::s($rest['verifyUrl']), $restSats, V::i($restEvent['created_at']), V::i($restEvent['created_at']) + 540);
        $restInvoice->state = ['open'];
        $received = V::s(V::o($vector['transaction'])['sats']);
        \assert(is_numeric($received));
        $now = V::i($restEvent['created_at']);
        $status = PaymentStatus::open($expiresAt, $received, Combined::rest($sats, $expiresAt, $address, $now, $received, new RestPart([$restInvoice])));
        self::assertSame(Json::encode($vector['status']), Json::encode($status->toArray()));
        self::assertNull(Pricing::declared(['minor' => 49000, 'digits' => 0, 'currency' => 'SAT']));
    }
}
