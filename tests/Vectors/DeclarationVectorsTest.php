<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Crypto\Nip44;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * Declarations (SPEC.md §20) against the merchant's app: its declaration opens, ours opens with
 * the vector's keys into exactly the app's JSON (PORTING.md, definition of done, point 2), and the
 * implementation's declaration of vectors/app/ opens.
 */
final class DeclarationVectorsTest extends TestCase
{
    public function testTheAppsDeclarationOpens(): void
    {
        [$key, $registry, $app] = self::app();
        $declaration = V::a($app['declaration']);
        $opened = Declaration::open(self::eventJson('vectors/app/app.json', ['declaration', 'event']), Sale::of($key, V::s($declaration['ref'])), $registry);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        $value = $opened[1];
        self::assertSame($declaration['invoice'], $value->invoice);
        self::assertSame($declaration['verifyUrl'], $value->verifyUrl);
        self::assertSame($declaration['fiat'], $value->fiat);
        self::assertSame($declaration['note'], $value->note);
        self::assertNull($value->rest);
        self::assertSame('21', $value->sats());
        // Another sale does not open it.
        self::assertNull(Declaration::open(self::eventJson('vectors/app/app.json', ['declaration', 'event']), Sale::of($key, 'ORD-2001'), $registry));
    }

    public function testTheImplementationsDeclarationOpens(): void
    {
        [$key, $registry] = self::app();
        $vector = V::object('vectors/app/implementation-declaration.json');
        $sale = Sale::of($key, V::s($vector['ref']));
        self::assertSame($vector['saleAuthor'], $sale->author);
        self::assertSame($vector['taker'], $sale->takerTag);
        $opened = Declaration::open(self::eventJson('vectors/app/implementation-declaration.json', ['event']), $sale, $registry);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        $value = $opened[1];
        self::assertSame($vector['invoice'], $value->invoice);
        self::assertSame($vector['sats'], $value->sats());
        self::assertSame($vector['verifyUrl'], $value->verifyUrl);
        self::assertSame($vector['fiat'], $value->fiat);
        self::assertSame($vector['note'], $value->note);
        self::assertSame($vector['paymentHash'], $value->decoded->paymentHash);
    }

    /** PORTING.md, definition of done, point 2. */
    public function testOurDeclarationOfTheAppsOrderOpensWithItsKeys(): void
    {
        [$key, $registry, $app] = self::app();
        $declaration = V::a($app['declaration']);
        $appEvent = V::a($declaration['event']);
        $sale = Sale::of($key, V::s($declaration['ref']));
        /** @var array{minor: int, digits: int, currency: string} $fiat */
        $fiat = V::a($declaration['fiat']);
        $inside = Declaration::inside(V::s($declaration['invoice']), V::s($declaration['verifyUrl']), $sale->keyHex(), $fiat, V::s($declaration['note']), $sale->takerTag, null);
        $context = TestContext::create(new TestConnector());
        $event = Declaration::event($sale, $registry, V::s($declaration['paymentHash']), $inside, V::i($appEvent['created_at']), $context);

        self::assertSame(30078, $event->kind);
        self::assertSame($declaration['saleAuthor'], $event->pubkey);
        self::assertSame($appEvent['created_at'], $event->createdAt);
        self::assertSame($appEvent['tags'], $event->tags);
        self::assertTrue($event->verify());
        $outer = Nip44::decrypt($event->content, V::bytes($declaration['outerKey']));
        self::assertSame(0, \strlen($outer) % 256);
        $envelope = Json::decode($outer);
        self::assertSame('registry-declaration', Json::get($envelope, 't'));
        $ours = Nip44::decrypt(V::s(Json::get(Json::get($envelope, 'v'), 'sealed')), V::bytes($declaration['innerConversationKey']));

        $appOuter = Nip44::decrypt(V::s($appEvent['content']), V::bytes($declaration['outerKey']));
        $theirs = Nip44::decrypt(V::s(Json::get(Json::get(Json::decode($appOuter), 'v'), 'sealed')), V::bytes($declaration['innerConversationKey']));
        self::assertSame($theirs, $ours, 'exactly the JSON of the app\'s declaration');
        self::assertSame(\strlen($appOuter), \strlen($outer), 'outsideBytes');
        self::assertSame($declaration['outsideBytes'], \strlen($outer));
    }

    /**
     * @return array{0: IntegrationKey, 1: Registry, 2: array<string, mixed>}
     */
    private static function app(): array
    {
        $app = V::object('vectors/app/app.json');
        $key = IntegrationKey::parse($app['secretKey']);
        $channel = V::a(V::a(V::a($app['channel'])['value'])['v']);

        return [$key, new Registry(new Secret(V::bytes($channel['registryKey'])), V::s($channel['registryReader'])), $app];
    }

    /**
     * An event of a vector file as a relay sends it (JSON objects as objects).
     *
     * @param list<string> $path
     */
    private static function eventJson(string $file, array $path): mixed
    {
        $text = file_get_contents(V::path($file));
        \assert(\is_string($text));
        $value = Json::decode($text);
        foreach ($path as $member) {
            $value = Json::get($value, $member);
        }
        self::assertNotNull(Event::fromJson($value));

        return $value;
    }
}
