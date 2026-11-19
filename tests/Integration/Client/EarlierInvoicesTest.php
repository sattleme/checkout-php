<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Integration\Client;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\InvalidOptionException;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\Internal\Nostr\Event;
use Sattleme\Checkout\Internal\Sale;
use Sattleme\Checkout\PaymentStatus;
use Sattleme\Checkout\Tests\Support\Net\HttpRequest;
use Sattleme\Checkout\Tests\Support\Net\HttpResponseSpec;
use Sattleme\Checkout\Tests\Support\TestTime;
use Sattleme\Checkout\Tests\Support\World;
use Sattleme\Checkout\TimeoutCancellation;

/**
 * One order, one invoice at a time (SPEC.md §21), and the payment states of a Lightning invoice
 * (§22, §23), end to end.
 */
final class EarlierInvoicesTest extends TestCase
{
    private World $world;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->channel();
    }

    protected function tearDown(): void
    {
        $this->world->stop();
    }

    public function testASalesDeclarationsArePagedOnEachEventsRawIdAndDate(): void
    {
        // Every relay sends, with the sale's declarations, an older entry with a string `id` and a
        // safe integer `created_at` and nothing else that checks out: it pages the read all the
        // same (SPEC.md §13, "Paged reads"), and is no invoice.
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-9', 'sats' => '500']);
        $author = Sale::of($this->world->integration(), 'ORD-9')->author;
        foreach ([0, 1, 2] as $index) {
            $relay = $this->world->relay($index);
            $relay->extraEvents = static fn(): array => ['{"id":"' . str_repeat('a', 64) . '","created_at":1700000000,"kind":30078}'];
            $relay->queries = [];
        }
        self::assertSame([$invoice->bolt11], array_map(static fn(DeclaredInvoice $found): ?string => $found->bolt11, $client->findInvoices('ORD-9')));
        // The second page of each relay, until the oldest date of the first, the entry's; on a
        // majority at least (a relay past it may have been dropped before its second page).
        $seconds = [];
        foreach ([0, 1, 2] as $index) {
            $pages = array_values(array_filter($this->world->relay($index)->queries, static fn(\stdClass $filter): bool => ($filter->authors ?? null) === [$author]));
            if (\count($pages) > 1) {
                $seconds[] = $pages[1]->until ?? null;
            }
        }
        self::assertGreaterThanOrEqual(2, \count($seconds));
        self::assertSame(array_fill(0, \count($seconds), 1700000000), $seconds);
    }

    public function testAPendingInvoice(): void
    {
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-1', 'sats' => '500']);
        // Until its end and its grace period: pending, even for another amount.
        $this->world->now = $first->expiresAt + 119;
        try {
            $client->createInvoice(['ref' => 'ORD-1', 'sats' => '700']);
            self::fail('The first invoice may still be paid');
        } catch (InvoicePendingException $e) {
            self::assertSame($first->bolt11, $e->getInvoice()->bolt11);
            self::assertSame($first->toArray()['sats'], $e->getInvoice()->sats);
            self::assertFalse($e->isRetryable());
        }
        // Then a new invoice: the first expired unpaid.
        $this->world->now = $first->expiresAt + 120;
        $second = $client->createInvoice(['ref' => 'ORD-1', 'sats' => '700']);
        self::assertNotSame($first->bolt11, $second->bolt11);
        self::assertCount(2, $client->findInvoices('ORD-1'));
    }

    public function testAPaidOrder(): void
    {
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-2', 'sats' => '500']);
        $this->world->settle((string) $first->paymentHash);
        $this->world->now = $first->expiresAt + 500;
        try {
            $client->createInvoice(['ref' => 'ORD-2', 'sats' => '500']);
            self::fail('The order was paid');
        } catch (OrderPaidException $e) {
            self::assertSame($first->bolt11, $e->getInvoice()->bolt11);
            self::assertSame(PaymentStatus::PAID, $e->getStatus()->state);
            self::assertSame($this->world->invoices[(string) $first->paymentHash]['preimage'], $e->getPreimage());
        }
    }

    public function testAPendingInvoiceAlreadyPaidIsNeverHandedOutAgain(): void
    {
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-11', 'sats' => '500']);
        // Paid while it may still be paid: its payment is checked, even of one alone (SPEC.md §21).
        $this->world->settle((string) $first->paymentHash);
        try {
            $client->createInvoice(['ref' => 'ORD-11', 'sats' => '500', 'checkout' => true]);
            self::fail('The order was paid');
        } catch (OrderPaidException $e) {
            self::assertSame($first->bolt11, $e->getInvoice()->bolt11);
            self::assertSame(PaymentStatus::PAID, $e->getStatus()->state);
        }
    }

    public function testAPendingInvoiceWhosePaymentCannotBeCheckedIsNotHandedOut(): void
    {
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-12', 'sats' => '500']);
        // Its payment check fails: it may have been paid, the error is the error (SPEC.md §21).
        $this->world->http->on('breez.tips', static fn(HttpRequest $request): HttpResponseSpec => new HttpResponseSpec(503, 'Unavailable'));
        $before = \count($this->world->http->requests);
        try {
            $client->createInvoice(['ref' => 'ORD-12', 'sats' => '500']);
            self::fail('The payment of the pending invoice is unknown');
        } catch (NetworkException $e) {
            self::assertStringContainsString('HTTP 503', $e->getMessage());
        }
        // Its payment checked, the pay request asked at the same time (SPEC.md §17, step 3): no invoice asked.
        $asked = array_map(static fn(HttpRequest $r): string => $r->host() . $r->path(), \array_slice($this->world->http->requests, $before));
        sort($asked);
        self::assertSame(['breez.tips/.well-known/lnurlp/shop', 'breez.tips/lnurlp/verify/' . $first->paymentHash], $asked);
    }

    public function testAnInvoiceDeclaredOnAMinorityIsDeclaredAgain(): void
    {
        $sale = Sale::of($this->world->integration(), 'ORD-3');
        // The first createInvoice: its declaration on one relay only (do not show the invoice).
        foreach ([0, 1] as $index) {
            $this->world->relay($index)->refuseEvent = static fn(Event $event): ?string => $event->pubkey === $sale->author ? 'blocked: later' : null;
        }
        $client = $this->world->client();
        try {
            $client->createInvoice(['ref' => 'ORD-3', 'sats' => '500']);
            self::fail('Declared on a minority');
        } catch (NetworkException) {
            // Expected.
        }
        foreach ([0, 1] as $index) {
            $this->world->relay($index)->refuseEvent = null;
        }
        // The next one reads it (relay 1 answering last) and publishes it again before it fails.
        $this->world->relay(0)->queryDelayMs = 300;
        try {
            $client->createInvoice(['ref' => 'ORD-3', 'sats' => '500']);
            self::fail('The declared invoice may still be paid');
        } catch (InvoicePendingException $e) {
            self::assertNotNull($e->getInvoice()->bolt11);
        }
        // Published again on a majority (relay 2 took it; relay 1, the slow one, may be closed first: SPEC.md §13).
        $holding = 0;
        foreach ([0, 1, 2] as $index) {
            $holding += \count(array_filter($this->world->relay($index)->events, static fn(\stdClass $value): bool => $value->pubkey === $sale->author));
        }
        self::assertCount(1, array_filter($this->world->relay(1)->events, static fn(\stdClass $value): bool => $value->pubkey === $sale->author), 'declared again on relay 2');
        self::assertGreaterThanOrEqual(2, $holding);
    }

    public function testTheFirstErrorWhenNoInvoiceIsPaidNorPending(): void
    {
        $client = $this->world->client();
        $first = $client->createInvoice(['ref' => 'ORD-4', 'sats' => '500']);
        $this->world->now = $first->expiresAt + 500;
        $this->world->http->on('breez.tips', static fn(HttpRequest $request): HttpResponseSpec => new HttpResponseSpec(503, 'Unavailable'));
        $this->expectException(NetworkException::class);
        $client->createInvoice(['ref' => 'ORD-4', 'sats' => '500']);
    }

    public function testThePaymentStatesOfALightningInvoice(): void
    {
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-5', 'sats' => '500']);
        $hash = (string) $invoice->paymentHash;
        self::assertSame(PaymentStatus::OPEN, $client->getStatus($invoice)->state);
        // Reported open until its end and its grace period, then expired.
        $this->world->now = $invoice->expiresAt + 119;
        self::assertSame(PaymentStatus::OPEN, $client->getStatus($invoice)->state);
        $this->world->now = $invoice->expiresAt + 120;
        self::assertSame(['state' => 'expired', 'received' => '0'], $client->getStatus($invoice)->toArray());
        // A service that forgot it: expired too.
        unset($this->world->invoices[$hash]);
        self::assertSame(PaymentStatus::EXPIRED, $client->getStatus($invoice)->state);
    }

    public function testAnswersAboutAnotherInvoiceOrWithoutProof(): void
    {
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-6', 'sats' => '500']);
        $hash = (string) $invoice->paymentHash;
        $this->world->settle($hash);
        $this->world->tamper($hash, 'preimage', str_repeat('00', 32));
        try {
            $client->getStatus($invoice);
            self::fail('A preimage of another payment hash');
        } catch (InvalidInvoiceException $e) {
            self::assertFalse($e->isRetryable());
        }
        $other = $client->createInvoice(['ref' => 'ORD-7', 'sats' => '500']);
        $this->world->tamper($hash, 'pr', (string) $other->bolt11);
        $this->expectException(InvalidInvoiceException::class);
        $client->getStatus($invoice);
    }

    public function testWaitingForALightningPayment(): void
    {
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-8', 'sats' => '500']);
        $statuses = [];
        $status = $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => function (PaymentStatus $status) use (&$statuses, $invoice): void {
            $statuses[] = $status->state;
            if (\count($statuses) === 2) {
                $this->world->settle((string) $invoice->paymentHash);
            }
        }], new TimeoutCancellation(TestTime::seconds(10)));
        self::assertSame(PaymentStatus::PAID, $status->state);
        self::assertSame(['open', 'open', 'paid'], $statuses);

        // An expired invoice ends the wait too; a failure of the service is retried.
        $expired = $client->createInvoice(['ref' => 'ORD-9', 'sats' => '500']);
        $this->world->now = $expired->expiresAt + 120;
        $failures = 1;
        $this->world->http->on('breez.tips', function (HttpRequest $request) use (&$failures): HttpResponseSpec {
            return $failures-- > 0 ? new HttpResponseSpec(503, 'Unavailable') : HttpResponseSpec::json(['status' => 'ERROR', 'reason' => 'Not found']);
        });
        self::assertSame(PaymentStatus::EXPIRED, $client->waitForPayment($expired, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(10)))->state);
        self::assertNotSame([], $this->world->warnings, 'the failure was logged');
    }

    public function testAFailureMetAgainByEachCheckIsLoggedOnceAndAgainOnlyAfterItStopped(): void
    {
        $client = $this->world->client();
        $invoice = $client->createInvoice(['ref' => 'ORD-10', 'sats' => '500']);
        $hash = (string) $invoice->paymentHash;
        $paid = ['status' => 'OK', 'settled' => true, 'preimage' => $this->world->invoices[$hash]['preimage'], 'pr' => $invoice->bolt11];
        // The verify URL fails at three checks (with two statuses: one failure, but for its
        // number), answers, fails at two checks, then says paid (SPEC.md §23).
        $answers = [503, 502, 503, ['settled' => false, 'preimage' => null] + $paid, 503, 503, $paid];
        $this->world->http->on('breez.tips', static function (HttpRequest $request) use (&$answers): HttpResponseSpec {
            $answer = array_shift($answers) ?? new HttpResponseSpec(500, 'Asked too often');
            if (\is_int($answer)) {
                return new HttpResponseSpec($answer, 'Unavailable');
            }

            return $answer instanceof HttpResponseSpec ? $answer : HttpResponseSpec::json($answer);
        });
        $this->world->warnings = [];
        $statuses = [];
        $status = $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250), 'onStatus' => static function (PaymentStatus $status) use (&$statuses): void {
            $statuses[] = $status->state;
        }], new TimeoutCancellation(TestTime::seconds(10)));
        self::assertSame(PaymentStatus::PAID, $status->state);
        self::assertSame(['open', 'paid'], $statuses);
        self::assertSame([], $answers, 'every check asked the verify URL');
        $warnings = $this->world->warned('A payment check failed');
        self::assertCount(2, $warnings, 'once, and once again after it stopped');
        self::assertStringContainsString('HTTP 503', $warnings[0]);

        // Each wait logs its own.
        $answers = [503, $paid];
        $this->world->warnings = [];
        self::assertSame(PaymentStatus::PAID, $client->waitForPayment($invoice, ['intervalMs' => TestTime::ms(250)], new TimeoutCancellation(TestTime::seconds(10)))->state);
        self::assertCount(1, $this->world->warned(''));
    }

    public function testWaitingOptionsAreCheckedFirst(): void
    {
        $client = $this->world->client();
        foreach ([['intervalMs' => 249], ['intervalMs' => 60001], ['intervalMs' => '2000'], ['onStatus' => 'not callable'], ['interval' => 1000]] as $options) {
            try {
                $client->waitForPayment(['sats' => '1', 'expiresAt' => 1, 'bolt11' => null, 'onchainAddress' => null], $options);
                self::fail('Invalid options: ' . json_encode($options));
            } catch (InvalidOptionException) {
                // Expected.
            }
        }
        self::assertSame([], $this->world->connector->asked);
    }
}
