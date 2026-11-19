<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Declaration;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Onchain\Acceptance;
use Sattleme\Checkout\Internal\Onchain\Combined;
use Sattleme\Checkout\Internal\Onchain\RestAsking;
use Sattleme\Checkout\Internal\Onchain\RestInvoice;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\RestPart;
use Sattleme\Checkout\Internal\Onchain\RestRecord;
use Sattleme\Checkout\Internal\Registry;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Internal\Secret;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The rest of an on-chain underpayment (SPEC.md §31): its keys, its records and acceptance records
 * as a checkout link's page reads them, its declarations as the sale opens them, and when a check
 * asks for an invoice of it.
 */
final class OnchainRestsVectorsTest extends TestCase
{
    public function testTheKeys(): void
    {
        $vector = V::object('vectors/onchain-rests.json');
        $key = self::key();
        foreach (V::a($vector['keys']) as $case) {
            $case = V::a($case);
            $keys = RestKeys::of($key, V::s($case['ref']), V::s($case['address']), V::i($case['expiresAt']));
            self::assertSame($case['restKey'], bin2hex($keys->key->reveal()));
            self::assertSame($case['restSecret'], bin2hex($keys->secret->reveal()));
            self::assertSame($case['restAuthor'], $keys->author);
            self::assertSame($case['restEncryptionKey'], bin2hex($keys->encryptionKey->reveal()));
            self::assertSame($case['acceptanceAddress'], $keys->acceptanceAddress());
            foreach (V::a($case['records']) as $record) {
                $record = V::a($record);
                self::assertSame($record['address'], $keys->recordAddress(V::s($record['paymentHash'])));
            }
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function records(): iterable
    {
        yield from V::indexed('vectors/onchain-rests.json', ['records', 'cases']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('records')]
    public function testARestRecordAsThePageReadsIt(array $case, int $index): void
    {
        $records = V::a(V::object('vectors/onchain-rests.json')['records']);
        $link = V::a($records['link']);
        $keys = RestKeys::ofLink(V::s($records['restAuthor']), V::bytes($records['restKey']));
        $sats = V::s($link['sats']);
        \assert(is_numeric($sats));
        $read = RestRecord::read(V::json('vectors/onchain-rests.json', ['records', 'cases', $index, 'event']), $keys, $sats, V::i($link['createdAt']), V::i($link['expiresAt']));
        if ($case['expected'] === null) {
            self::assertNull($read);
        } elseif ($case['expected'] === 'newer') {
            self::assertSame(['newer'], $read);
        } else {
            self::assertNotNull($read);
            self::assertSame('ok', $read[0]);
            self::assertEquals(V::a($case['expected']), $read[1]);
        }
    }

    public function testOurRestRecordReadsTheSame(): void
    {
        $vector = V::object('vectors/onchain-rests.json');
        $first = V::a(V::a($vector['keys'])[0]);
        $keys = RestKeys::of(self::key(), V::s($first['ref']), V::s($first['address']), V::i($first['expiresAt']));
        $records = V::a($vector['records']);
        $link = V::a($records['link']);
        $expected = V::a(V::a(V::a($records['cases'])[0])['expected']);
        $context = TestContext::create(new TestConnector());
        $event = RestRecord::event($keys, V::s($expected['invoice']), V::s($expected['verifyUrl']), V::s($expected['paymentHash']), V::i($first['expiresAt']), 1791378580, $context);
        self::assertSame(V::a(V::a(V::a($records['cases'])[0])['event'])['tags'], $event->tags);
        self::assertSame($keys->author, $event->pubkey);
        self::assertTrue($event->verify());
        $read = RestRecord::read($event->toJson(), RestKeys::ofLink(V::s($records['restAuthor']), V::bytes($records['restKey'])), '49000', V::i($link['createdAt']), V::i($link['expiresAt']));
        self::assertNotNull($read);
        self::assertEquals(['ok', $expected], $read);
        self::assertNotNull(RestRecord::of($event->toJson(), $keys, strtoupper(V::s($expected['invoice'])), V::s($expected['paymentHash'])));
        self::assertNull(RestRecord::of($event->toJson(), $keys, V::s($expected['invoice']), str_repeat('00', 32)));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function acceptances(): iterable
    {
        yield from V::indexed('vectors/onchain-rests.json', ['acceptances', 'cases']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('acceptances')]
    public function testAnAcceptanceRecordAsThePageReadsIt(array $case, int $index): void
    {
        $acceptances = V::a(V::object('vectors/onchain-rests.json')['acceptances']);
        $keys = RestKeys::ofLink(V::s($acceptances['restAuthor']), V::bytes($acceptances['restKey']));
        $sats = V::s(V::a($acceptances['link'])['sats']);
        \assert(is_numeric($sats));
        $read = Acceptance::read(V::json('vectors/onchain-rests.json', ['acceptances', 'cases', $index, 'event']), $keys, $sats);
        if ($case['expected'] === null) {
            self::assertNull($read);
        } elseif ($case['expected'] === 'newer') {
            self::assertNotNull($read);
            self::assertSame('newer', $read[0]);
        } else {
            self::assertNotNull($read);
            self::assertSame('ok', $read[0]);
            self::assertSame(V::a($case['expected'])['received'], $read[1]);
        }
    }

    public function testOurAcceptanceRecordReadsTheSame(): void
    {
        $vector = V::object('vectors/onchain-rests.json');
        $first = V::a(V::a($vector['keys'])[0]);
        $keys = RestKeys::of(self::key(), V::s($first['ref']), V::s($first['address']), V::i($first['expiresAt']));
        $context = TestContext::create(new TestConnector());
        $event = Acceptance::event($keys, 46550, V::i($first['expiresAt']), 1791378680, $context);
        $theirs = V::a(V::a(V::a(V::a($vector['acceptances'])['cases'])[0])['event']);
        self::assertSame($theirs['tags'], $event->tags);
        $read = Acceptance::read($event->toJson(), $keys, '49000');
        self::assertNotNull($read);
        self::assertSame(['ok', '46550'], [$read[0], $read[1]]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function declarations(): iterable
    {
        yield from V::indexed('vectors/onchain-rests.json', ['declarations', 'cases']);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('declarations')]
    public function testADeclarationOfTheSale(array $case, int $index): void
    {
        $declarations = V::a(V::object('vectors/onchain-rests.json')['declarations']);
        $registry = new Registry(new Secret(V::bytes($declarations['registryKey'])), V::s($declarations['registryReader']));
        $sale = Sale::of(self::key(), V::s($declarations['ref']));
        $opened = Declaration::open(V::json('vectors/onchain-rests.json', ['declarations', 'cases', $index, 'event']), $sale, $registry);
        if ($case['expected'] === null) {
            self::assertNull($opened);

            return;
        }
        $expected = V::a($case['expected']);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        $declaration = $opened[1];
        self::assertSame($expected['invoice'], $declaration->invoice);
        self::assertSame($expected['note'], $declaration->note);
        self::assertSame($expected['rest'], $declaration->rest);
    }

    public function testOurDeclarationOfARestOpens(): void
    {
        $declarations = V::a(V::object('vectors/onchain-rests.json')['declarations']);
        $registry = new Registry(new Secret(V::bytes($declarations['registryKey'])), V::s($declarations['registryReader']));
        $sale = Sale::of(self::key(), V::s($declarations['ref']));
        $expected = V::a(V::a(V::a($declarations['cases'])[0])['expected']);
        /** @var array{address: string, expiresAt: int} $rest */
        $rest = V::a($expected['rest']);
        $inside = Declaration::inside(V::s($expected['invoice']), 'https://breez.tips/lnurlp/verify/x', $sale->keyHex(), ['minor' => 2450, 'digits' => 2, 'currency' => 'CHF'], V::s($expected['note']), $sale->takerTag, $rest);
        self::assertSame(['version', 'invoice', 'verifyUrl', 'sale', 'taker', 'rest'], array_keys($inside));
        $context = TestContext::create(new TestConnector());
        $decoded = \Sattleme\Checkout\Internal\Lightning\Bolt11::decode($expected['invoice']);
        \assert($decoded !== null);
        $event = Declaration::event($sale, $registry, $decoded->paymentHash, $inside, 1791378580, $context);
        $opened = Declaration::open($event->toJson(), $sale, $registry);
        self::assertNotNull($opened);
        self::assertSame('ok', $opened[0]);
        self::assertSame($rest, $opened[1]->rest);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function asking(): iterable
    {
        yield from V::named(V::l(V::object('vectors/onchain-rests.json')['asking']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('asking')]
    public function testWhenACheckAsksForAnInvoiceOfTheRest(array $case): void
    {
        $sats = V::s($case['sats']);
        $received = V::s($case['received']);
        $expiresAt = V::i($case['expiresAt']);
        $now = V::i($case['now']);
        \assert(is_numeric($sats) && is_numeric($received));
        $ids = [];
        $invoices = [];
        foreach (V::a($case['rests']) as $value) {
            $value = V::a($value);
            $restSats = V::s($value['sats']);
            \assert(is_numeric($restSats));
            $invoice = new RestInvoice('lnbc' . V::s($value['id']), V::s($value['paymentHash']), 'https://breez.tips/v', $restSats, V::i($value['createdAt']), V::i($value['expiresAt']));
            $invoice->state = $value['paid'] === true ? ['paid', str_repeat('00', 32)] : ['open'];
            $ids[$invoice->bolt11] = V::s($value['id']);
            $invoices[] = $invoice;
        }
        $rests = new RestPart($invoices);
        $rest = Combined::rest($sats, $expiresAt, 'bc1px22v5j99gdwhxqyelg2p6tdcfajjd3njhzn22c2gura2dvxg2xlqw2wte4', $now, $received, $rests);
        $lease = $case['lease'] === null ? null : ['sats' => V::s(V::a($case['lease'])['sats']), 'expiresAt' => V::i(V::a($case['lease'])['expiresAt'])];
        $sendable = V::a($case['sendable']);
        $min = V::s($sendable['min']);
        $max = V::s($sendable['max']);
        \assert(is_numeric($min) && is_numeric($max));
        $ask = RestAsking::ask($rest, $sats, $expiresAt, $now, $lease, $min, $max);

        $expected = V::a($case['expected']);
        self::assertSame(
            $expected,
            [
                'rest' => $rest === null ? null : ['sats' => $rest->sats, 'invoice' => $rest->bolt11 === null ? null : $ids[$rest->bolt11]],
                'ask' => $ask === null ? null : ['sats' => $ask[0], 'expirySeconds' => $ask[1]],
            ],
        );
    }

    private static function key(): IntegrationKey
    {
        $key = IntegrationKey::parse(V::object('vectors/app/app.json')['secretKey']);
        self::assertSame(V::object('vectors/onchain-rests.json')['integrationKey'], bin2hex($key->key->reveal()));

        return $key;
    }
}
