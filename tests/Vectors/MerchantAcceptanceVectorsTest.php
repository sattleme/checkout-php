<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Derive;
use Sattleme\Checkout\Internal\IntegrationKey;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptance;
use Sattleme\Checkout\Internal\Onchain\MerchantAcceptanceKeys;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * The merchant's acceptances made by the merchant's app, and records it is not asked to make
 * (vectors/app/merchant-acceptance.json, SPEC.md §31, "The merchant's acceptance"): their
 * addresses, and each record read as a check reads it for its invoice, through the reading of one
 * record (MerchantAcceptance::open()) and through the count of a read on every relay where each
 * relay of 3 sent it (MerchantAcceptance::of()).
 */
final class MerchantAcceptanceVectorsTest extends TestCase
{
    private const FILE = 'vectors/app/merchant-acceptance.json';

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function invoices(): iterable
    {
        foreach (V::l(V::object(self::FILE)['invoices']) as $index => $invoice) {
            $invoice = V::o($invoice);
            yield V::s($invoice['ref']) . ' at ' . V::i($invoice['expiresAt']) => [$invoice, $index];
        }
    }

    /**
     * The app's records, read with its key, then `made`'s, read with `made`'s key.
     *
     * @return iterable<string, array{0: list<string|int>, 1: string}>
     */
    public static function records(): iterable
    {
        foreach (V::l(V::object(self::FILE)['records']) as $index => $record) {
            yield V::s(V::o($record)['name']) => [['records', $index], 'secretKey'];
        }
        foreach (V::l(V::o(V::object(self::FILE)['made'])['records']) as $index => $record) {
            yield V::s(V::o($record)['name']) => [['made', 'records', $index], 'made'];
        }
    }

    public function testItIsOfTheTeamAndTheChannelsKeyOfItsIntegrationKey(): void
    {
        $vector = V::object(self::FILE);
        $key = IntegrationKey::parse($vector['secretKey']);
        self::assertSame([$vector['team'], $vector['channelEncryptionKey']], [$key->team, bin2hex(Derive::channelEncryptionKey($key->key->reveal()))]);
    }

    public function testMadeIsByATeamOfItsOwnForTheSameIntegration(): void
    {
        $vector = V::object(self::FILE);
        $made = V::o($vector['made']);
        $key = IntegrationKey::parse($vector['secretKey']);
        $madeKey = IntegrationKey::parse($made['secretKey']);
        self::assertSame([$made['team'], bin2hex($key->key->reveal())], [$madeKey->team, bin2hex($madeKey->key->reveal())]);
        self::assertSame($made['team'], Derive::author(V::bytes($made['teamSecret'])));
        self::assertNotSame($key->team, $madeKey->team);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    #[DataProvider('invoices')]
    public function testTheMerchantsAcceptanceAddressOfAnInvoice(array $invoice, int $index): void
    {
        $key = IntegrationKey::parse(V::object(self::FILE)['secretKey']);
        $sale = Sale::of($key, V::s($invoice['ref']));
        self::assertSame([$invoice['saleKey'], $invoice['sale']], [$sale->keyHex(), $sale->author], "invoice {$index}");
        $address = Derive::merchantAcceptanceAddress($key->key->reveal(), V::s($invoice['sale']), V::s($invoice['onchainAddress']), V::i($invoice['expiresAt']));
        self::assertSame($invoice['acceptanceAddress'], $address);
        self::assertSame($address, MerchantAcceptanceKeys::of($key, $sale->author, V::s($invoice['onchainAddress']), V::i($invoice['expiresAt']))->address);
    }

    /**
     * @param list<string|int> $path where the record is
     * @param 'secretKey'|'made' $by   whose key reads it: the vector's, or `made`'s
     */
    #[DataProvider('records')]
    public function testARecordReadAsACheckReadsIt(array $path, string $by): void
    {
        $vector = V::object(self::FILE);
        $record = V::o(self::at($vector, $path));
        $key = IntegrationKey::parse($by === 'made' ? V::o($vector['made'])['secretKey'] : $vector['secretKey']);
        $invoice = V::o(V::l($vector['invoices'])[V::i($record['invoice'])]);
        $sats = V::s($invoice['sats']);
        \assert(is_numeric($sats));
        $expiresAt = V::i($invoice['expiresAt']);
        $keys = MerchantAcceptanceKeys::of($key, V::s($invoice['sale']), V::s($invoice['onchainAddress']), $expiresAt);
        // The event as a relay sends it: its JSON as it is.
        $event = V::json(self::FILE, [...$path, 'event']);

        $opened = MerchantAcceptance::open($event, $keys, $sats, $expiresAt);
        if ($opened === null || $opened[0] === MerchantAcceptance::NEWER_SET_ASIDE) {
            $read = null; // Ignored, or set aside (one of a newer format logged all the same).
        } elseif ($opened[0] === MerchantAcceptance::NEWER) {
            $read = 'newer';
        } else {
            $read = ['received' => (int) $opened[2], 'createdAt' => $opened[1]->createdAt];
        }
        self::assertSame($record['expected'], $read);

        $everyRelay = ['wss://a.example' => [$event], 'wss://b.example' => [$event], 'wss://c.example' => [$event]];
        $expected = \is_array($record['expected']) ? (string) V::i($record['expected']['received']) : null;
        self::assertSame($expected, MerchantAcceptance::of($everyRelay, $keys, $sats, $expiresAt, 3)->received);
    }

    /**
     * @param array<string, mixed> $vector
     * @param list<string|int>     $path
     */
    private static function at(array $vector, array $path): mixed
    {
        $value = $vector;
        foreach ($path as $member) {
            $value = V::a($value)[$member];
        }

        return $value;
    }
}
