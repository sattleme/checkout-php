<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\SaleRecords;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/**
 * An order's earlier invoices (SPEC.md §21): from the sale's records as they open, its invoices
 * in their order (SaleRecords::newest() and invoicesOf(), as SaleRecords::fromAnswers() calls
 * them), those that may still be paid (mayStillBePaid()), and of several, the one handed out or
 * the outcome (pendingOutcome(), from whether a majority of the relays sent each one's last
 * record, held()), as Client::checkEarlierInvoices() calls them.
 */
final class EarlierInvoicesVectorsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        yield from V::named(V::l(V::object('vectors/earlier-invoices.json')['cases']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testAnOrdersEarlierInvoices(array $case): void
    {
        $relays = V::i($case['relays'] ?? V::object('vectors/earlier-invoices.json')['relays']);
        $now = V::i($case['now']);
        $expected = V::a($case['expected']);

        // What the sale's records open to; a declaration with `rest` is no invoice of the order.
        /** @var array<string, int> $sentBy */
        $sentBy = [];
        $declarations = [];
        foreach (V::l($case['declarations']) as $declaration) {
            $declaration = V::a($declaration);
            $hash = V::s($declaration['paymentHash']);
            $sentBy[$hash] = V::i($declaration['sentBy'] ?? $relays);
            if (($declaration['rest'] ?? false) === true) {
                continue;
            }
            $declarations[] = [
                'event' => self::event(V::i($declaration['declaredAt']), $hash),
                'invoice' => V::s($declaration['invoice']),
                'paymentHash' => $hash,
                'createdAt' => V::i($declaration['createdAt']),
                'expiresAt' => V::i($declaration['expiresAt']),
            ];
        }
        $records = [];
        foreach (V::l($case['records']) as $record) {
            $record = V::a($record);
            $event = V::a($record['event']);
            $records[] = ['record' => $record, 'event' => self::event(V::i($event['createdAt']), V::s($event['id']))];
            $sentBy[V::s($event['id'])] = V::i($record['sentBy'] ?? $relays);
        }
        // The newest record at each address: a lease there, or none (a withdrawal, a newer format).
        $leases = [];
        foreach (SaleRecords::newest(array_map(static fn(array $record): array => ['d' => V::s($record['record']['address']), 'event' => $record['event']], $records)) as $index) {
            ['record' => $record, 'event' => $event] = $records[$index];
            if (!\array_key_exists('invoice', $record)) {
                continue;
            }
            $invoice = $record['invoice'];
            $leases[] = [
                'event' => $event,
                'address' => V::s($record['address']),
                'invoice' => $invoice === null ? null : V::s($invoice),
                'createdAt' => V::i($record['createdAt']),
                'expiresAt' => V::i($record['expiresAt']),
                // Only true is confirmed, absent is not (Lease::isConfirmed()).
                'confirmed' => ($record['confirmed'] ?? null) === true,
            ];
        }

        // Each invoice as SaleRecords::fromAnswers() makes it: a declaration's, with the address of
        // its lease, or a lease's.
        $invoices = [];
        foreach (SaleRecords::invoicesOf($declarations, $leases) as [$d, $l]) {
            $declaration = $d === null ? null : $declarations[$d];
            $lease = $l === null ? null : $leases[$l];
            if ($declaration !== null) {
                $invoices[] = ['paymentHash' => $declaration['paymentHash'], 'onchainAddress' => $lease['address'] ?? null, 'createdAt' => $declaration['createdAt'], 'declaredAt' => $declaration['event']->createdAt, 'expiresAt' => $declaration['expiresAt'], 'last' => $declaration['event']];
            } else {
                \assert($lease !== null);
                $invoices[] = ['paymentHash' => null, 'onchainAddress' => $lease['address'], 'createdAt' => $lease['createdAt'], 'declaredAt' => $lease['event']->createdAt, 'expiresAt' => $lease['expiresAt'], 'last' => $lease['event']];
            }
        }
        self::assertSame(V::l($expected['invoices']), array_map(static fn(array $invoice): array => [
            'paymentHash' => $invoice['paymentHash'],
            'onchainAddress' => $invoice['onchainAddress'],
            'createdAt' => $invoice['createdAt'],
            'declaredAt' => $invoice['declaredAt'],
        ], $invoices));

        $mayBePaid = SaleRecords::mayStillBePaid(array_map(static fn(array $invoice): int => $invoice['expiresAt'], $invoices), $now);
        self::assertSame(V::l($expected['mayBePaid']), $mayBePaid);
        if ($mayBePaid === []) {
            // None: step 2 follows.
            self::assertSame([], $expected['checked']);
            self::assertNull($expected['outcome']);

            return;
        }
        // Their payments checked, even of one alone (Client::checkEarlierInvoices()).
        self::assertSame(V::l($expected['checked']), $mayBePaid);
        $statuses = V::l($case['statuses']);
        $checked = array_map(static fn(int $index): PaymentStatus|SattlemeException => self::paymentStatus($statuses[$index]), $mayBePaid);
        // Of several, whether a majority of the list sent its last record (all the relays when
        // absent), read again on every relay; null when fewer than a majority answered that read.
        $held = [true];
        if (\count($mayBePaid) > 1) {
            $held = ($case['readFails'] ?? false) === true ? null : SaleRecords::held(array_map(static fn(int $index): string => $invoices[$index]['last']->id, $mayBePaid), $sentBy, $relays);
        }
        $outcome = SaleRecords::pendingOutcome($checked, $held);
        if (isset($outcome['paid'])) {
            $actual = ['orderPaid' => $mayBePaid[$outcome['paid']]];
        } elseif (isset($outcome['failed'])) {
            $error = $checked[$outcome['failed']];
            \assert($error instanceof SattlemeException);
            $actual = ['error' => $error->getErrorCode()];
        } elseif (isset($outcome['readFailed'])) {
            $actual = ['error' => 'network'];
        } else {
            $actual = ['handOut' => $mayBePaid[$outcome['handOut']]];
        }
        self::assertSame($expected['outcome'], $actual);
    }

    /** A record's event as the sale opens it: what the vectors give of it, its created_at and id. */
    private static function event(int $createdAt, string $id): Event
    {
        $event = Event::fromJson((object) [
            'id' => $id,
            'pubkey' => str_repeat('11', 32),
            'created_at' => $createdAt,
            'kind' => 30078,
            'tags' => [],
            'content' => '',
            'sig' => str_repeat('00', 64),
        ]);
        \assert($event instanceof Event);

        return $event;
    }

    private static function paymentStatus(mixed $value): PaymentStatus|SattlemeException
    {
        $value = V::a($value);
        if (isset($value['error'])) {
            return $value['error'] === 'network' ? new NetworkException('the check fails') : new InvalidInvoiceException('the check fails');
        }
        $received = V::s($value['received'] ?? '0');
        \assert(is_numeric($received));

        return match ($value['state']) {
            'open' => PaymentStatus::open(0, $received, null),
            'unconfirmed' => PaymentStatus::unconfirmed($received, 0, []),
            'paid' => PaymentStatus::paidOnchain($received, []),
            default => throw new \RuntimeException('A state expected'),
        };
    }
}
