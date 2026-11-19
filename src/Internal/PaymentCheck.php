<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

use Sattleme\Checkout\CancelledException;
use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Internal\Lightning\Lnurl;
use Sattleme\Checkout\Internal\Loop\Locks;
use Sattleme\Checkout\Internal\Loop\Parallel;
use Sattleme\Checkout\Internal\Nostr\RelayPool;
use Sattleme\Checkout\Internal\Onchain\Acceptance;
use Sattleme\Checkout\Internal\Onchain\Combined;
use Sattleme\Checkout\Internal\Onchain\Explorer;
use Sattleme\Checkout\Internal\Onchain\LiveSource;
use Sattleme\Checkout\Internal\Onchain\OnchainPart;
use Sattleme\Checkout\Internal\Onchain\OnchainResult;
use Sattleme\Checkout\Internal\Onchain\RestAsking;
use Sattleme\Checkout\Internal\Onchain\RestInvoice;
use Sattleme\Checkout\Internal\Onchain\RestKeys;
use Sattleme\Checkout\Internal\Onchain\RestPart;
use Sattleme\Checkout\Internal\Onchain\RestRecord;
use Sattleme\Checkout\PaymentStatus;

/**
 * A payment check (SPEC.md §22, §31): the Lightning part of an invoice; with an address, its
 * on-chain part and its rest part too, asked at once and combined; then, as a check may, an
 * invoice of the rest asked and an acceptance record published.
 *
 * @internal
 */
final class PaymentCheck
{
    /**
     * @param list<string> $relays the merchant's
     */
    public function __construct(
        private readonly Context $context,
        private readonly RelayPool $pool,
        private readonly IntegrationKey $key,
        #[\SensitiveParameter]
        private readonly Channel $channel,
        private readonly array $relays,
        private readonly Locks $locks,
        public readonly Explorer $explorer,
    ) {}

    /**
     * The status of an invoice without an address: its Lightning part (SPEC.md §22).
     *
     * @throws NetworkException|InvalidInvoiceException
     */
    public static function lightningOnly(Context $context, StoredInvoice $invoice): PaymentStatus
    {
        \assert($invoice->bolt11 !== null && $invoice->paymentHash !== null && $invoice->verifyUrl !== null);
        return self::lightningStatus(Lnurl::verify($context, $invoice->bolt11, $invoice->paymentHash, $invoice->verifyUrl, $invoice->expiresAt, $context->now()), $invoice->expiresAt);
    }

    /**
     * The status of an invoice without an address from the state of its Lightning invoice (SPEC.md
     * §22): `received` "0", no rest.
     *
     * @param array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string} $state
     */
    public static function lightningStatus(array $state, int $expiresAt): PaymentStatus
    {
        return match ($state[0]) {
            'paid' => PaymentStatus::paidByLightning($state[1]),
            'open' => PaymentStatus::open($expiresAt, '0', null),
            'expired' => PaymentStatus::expired('0'),
        };
    }

    /**
     * The status of an invoice with an address, its rest invoices from $records (the sale's
     * records read already, SPEC.md §21) or read now.
     *
     * @throws SattlemeException the error of a part, when no part pays nor is being confirmed
     */
    public function check(StoredInvoice $invoice, #[\SensitiveParameter] ?SaleRecords $records = null, bool $post = true): PaymentStatus
    {
        $status = $this->full($invoice, $records, $post)[0];
        if ($status instanceof SattlemeException) {
            throw $status;
        }

        return $status;
    }

    /**
     * A check of every part at once (SPEC.md §31): the status (or the error of a part) and what
     * each part gave. With $post, as getStatus, waitForPayment's checks of the on-chain part and
     * the check of an order's earlier invoices do: an invoice of the rest asked, an acceptance
     * record published. The on-chain part reads the clock once the explorer answered; the status
     * is made by the clock once every part answered.
     *
     * @return array{0: PaymentStatus|SattlemeException, 1: Snapshot}
     */
    public function full(StoredInvoice $invoice, #[\SensitiveParameter] ?SaleRecords $records = null, bool $post = true): array
    {
        $address = $invoice->onchainAddress;
        $ref = $invoice->ref;
        \assert($address !== null && $ref !== null);
        $sale = Sale::of($this->key, $ref);
        $registry = $this->registry();
        $now = $this->context->now();
        $source = new LiveSource($this->context, $this->pool, $this->key, $this->relays, $this->explorer);

        /** @var array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning */
        $lightning = null;
        /** @var OnchainPart|SattlemeException|null $observed */
        $observed = null;
        /** @var array{0: RestPart, 1: SaleRecords}|SattlemeException|null $rest */
        $rest = null;
        $outcomes = Parallel::settle([
            function () use (&$lightning, $invoice, $now): void {
                $lightning = self::lightning($this->context, $invoice, $now);
            },
            function () use (&$observed, $source, $address, $invoice, $sale): void {
                $observed = self::part(fn(): OnchainPart => OnchainPart::observe($source, $this->context, $address, $invoice->expiresAt, $sale->author, $invoice->sats));
            },
            function () use (&$rest, $invoice, $sale, $registry, $records, $now): void {
                $rest = self::part(fn(): array => $this->restPart($invoice, $sale, $registry, $records, $now));
            },
        ]);
        foreach ($outcomes as [, $error]) {
            if ($error !== null) {
                throw $error; // A bug: the parts' own errors are kept above.
            }
        }
        if ($rest instanceof SattlemeException || $rest === null) {
            $rests = new RestPart([], $rest ?? new NetworkException('The rest part did not end'));
            $records = null;
        } else {
            [$rests, $records] = $rest;
        }
        $now = $this->context->now();
        // The order's tolerance: its lease's, when the sale's records were read and it names the invoice's terms.
        $lease = $records?->leaseOf($address, $invoice->sats, $invoice->expiresAt);
        $onchain = Combined::judge($observed ?? new NetworkException('The on-chain part did not end'), $lightning, $invoice->sats, $lease === null ? 0 : $lease->tolerance(), $rests->paid(), $invoice->expiresAt);
        $status = self::combine($invoice, $now, $lightning, $onchain, $rests);
        if ($onchain === null) {
            return [$status, new Snapshot($lightning, null, $rests)]; // Paid by Lightning: nothing more.
        }
        if ($post && $status instanceof PaymentStatus) {
            // None asked while an invoice of exactly the rest may still be paid, whatever its rest
            // record: one whose record is not known on a majority (its read failed, or publishing
            // it again) is never shown, and another one would be a second invoice of one rest.
            if ($status->state === PaymentStatus::OPEN && $status->rest !== null && $status->rest->bolt11 === null && $lease !== null
                && $rests->invoiceOf($status->rest->sats, $now, false) === null) {
                $asked = $this->askForRest($invoice, $sale, $registry, $status, $rests, $now);
                if ($asked !== null) {
                    [$status, $rests] = $asked;
                }
            }
            $this->accept($invoice, $status, $now);
        }

        return [$status, new Snapshot($lightning, $onchain, $rests)];
    }

    /**
     * Between two checks of the on-chain part (SPEC.md §31, "Waiting for an on-chain payment"):
     * the Lightning part and the rest invoices not final checked again, with the on-chain part's
     * last result. The third value says that a rest invoice was paid since: the on-chain part,
     * judged against what was due before, is then to be checked again at once.
     *
     * @return array{0: PaymentStatus|SattlemeException, 1: Snapshot, 2: bool}
     */
    public static function tick(Context $context, StoredInvoice $invoice, Snapshot $last): array
    {
        $now = $context->now();
        /** @var array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning */
        $lightning = null;
        $tasks = [
            static function () use (&$lightning, $context, $invoice, $now): void {
                $lightning = self::lightning($context, $invoice, $now);
            },
        ];
        $wasPaid = [];
        foreach ($last->rests->invoices as $index => $rest) {
            $wasPaid[$index] = $rest->isPaid();
            if (!$rest->isFinal()) {
                $tasks[] = static fn() => self::checkRest($context, $rest, $now);
            }
        }
        foreach (Parallel::settle($tasks) as [, $error]) {
            if ($error !== null) {
                throw $error;
            }
        }
        $paidSince = false;
        foreach ($last->rests->invoices as $index => $rest) {
            $paidSince = $paidSince || (!$wasPaid[$index] && $rest->isPaid());
        }
        $snapshot = new Snapshot($lightning, $last->onchain, $last->rests);

        return [self::combine($invoice, $now, $lightning, $last->onchain, $last->rests), $snapshot, $paidSince];
    }

    /**
     * The parts combined (SPEC.md §31): the status, or the error of a part.
     *
     * @param array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null $lightning
     */
    private static function combine(StoredInvoice $invoice, int $now, array|SattlemeException|null $lightning, OnchainResult|SattlemeException|null $onchain, RestPart $rests): PaymentStatus|SattlemeException
    {
        try {
            return Combined::status($invoice->sats, $invoice->expiresAt, (string) $invoice->onchainAddress, $now, $lightning, $onchain, $rests);
        } catch (SattlemeException $e) {
            return $e;
        }
    }

    /**
     * The Lightning part of the invoice's own invoice: null without one.
     *
     * @return array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}|SattlemeException|null
     */
    private static function lightning(Context $context, StoredInvoice $invoice, int $now): array|SattlemeException|null
    {
        return self::part(static fn(): ?array => $invoice->bolt11 === null ? null : Lnurl::verify($context, $invoice->bolt11, (string) $invoice->paymentHash, (string) $invoice->verifyUrl, $invoice->expiresAt, $now));
    }

    /** A rest invoice checked as a Lightning part (its end its own). */
    private static function checkRest(Context $context, RestInvoice $rest, int $now): void
    {
        $state = self::part(static fn(): array => Lnurl::verify($context, $rest->bolt11, $rest->paymentHash, $rest->verifyUrl, $rest->expiresAt, $now));
        if ($state instanceof SattlemeException) {
            $rest->error = $state;
        } else {
            $rest->error = null;
            $rest->state = $state;
        }
    }

    /**
     * The rest part (SPEC.md §31): the invoice's rest invoices, their declarations published again
     * when fewer than a majority of the relays sent them, their records known on a majority, each
     * checked as a Lightning part.
     *
     * @return array{0: RestPart, 1: SaleRecords}
     *
     * @throws SattlemeException when the sale's records cannot be read or a declaration published again
     */
    private function restPart(StoredInvoice $invoice, Sale $sale, Registry $registry, #[\SensitiveParameter] ?SaleRecords $records, int $now): array
    {
        $address = (string) $invoice->onchainAddress;
        $records ??= SaleRecords::read($this->pool, $this->context, $sale, $registry, $this->relays);
        $invoices = $records->restsOf($address, $invoice->expiresAt);
        foreach ($invoices as $rest) {
            if (!$rest->declaredOnMajority && $rest->declaration !== null
                && !$this->pool->publish($this->relays, $rest->declaration, static fn(): string => $sale->secret->reveal())) {
                throw new NetworkException('Fewer than a majority of the relays took the declaration of an invoice of the rest again');
            }
        }
        if ($invoices !== [] && $now < $invoice->expiresAt + Protocol::GRACE_SECONDS) {
            $this->restRecords($invoice, $invoices, $now);
        }
        $outcomes = Parallel::settle(array_map(fn(RestInvoice $rest): \Closure => fn() => self::checkRest($this->context, $rest, $now), $invoices));
        foreach ($outcomes as [, $error]) {
            if ($error !== null) {
                throw $error;
            }
        }

        return [new RestPart($invoices), $records];
    }

    /**
     * The rest records of the invoice's rest invoices (SPEC.md §31, "Rest records"): when a
     * majority of the relays answered their read, those fewer than a majority sent are published
     * again (a failure logged), but not one whose rest record address holds a record of a newer
     * format that a relay sent (SPEC.md §12): a newer version recorded that invoice, never written
     * over, and it counts as known. When fewer than a majority answered, none is published again:
     * such a read decides nothing (SPEC.md §13), and what the other relays hold is never written
     * over unseen; none of them counts as known then (an invoice of the rest asked in this check,
     * after this read, is known once a majority took its record).
     *
     * @param list<RestInvoice> $invoices
     */
    private function restRecords(StoredInvoice $invoice, array $invoices, int $now): void
    {
        $keys = RestKeys::of($this->key, (string) $invoice->ref, (string) $invoice->onchainAddress, $invoice->expiresAt);
        $answered = [];
        try {
            $answers = $this->pool->read($this->relays, ['kinds' => [Protocol::RECORD_KIND], 'authors' => [$keys->author]], false, $this->context->randomSecret(...), $keys->opensRest(...), false, $answered);
        } catch (NetworkException) {
            $answers = null;
        }
        // The rest record addresses holding a record of a newer format, from every relay that
        // answered, a majority or not.
        $newer = [];
        foreach ($answered as $events) {
            foreach ($events as $value) {
                $d = RestRecord::newerAt($value, $keys);
                if ($d !== null) {
                    $newer[$d] = true;
                }
            }
        }
        if ($newer !== []) {
            $this->context->warn('A record of an invoice of the rest has a newer format than this version of sattleme/checkout: update it');
        }
        if ($answers === null) {
            $this->context->warn('The rest records of an invoice could not be read on a majority of the relays: none published again');
            foreach ($invoices as $rest) {
                $rest->recordOnMajority = false;
            }

            return;
        }
        $majority = RelayPool::majority(\count($this->relays));
        $publishes = [];
        foreach ($invoices as $rest) {
            if (isset($newer[$keys->recordAddress($rest->paymentHash)])) {
                $rest->recordOnMajority = true; // Known: a newer version recorded it.
                continue;
            }
            $sentBy = 0;
            $copy = null;
            foreach ($answers as $events) {
                foreach ($events as $value) {
                    $event = RestRecord::of($value, $keys, $rest->bolt11, $rest->paymentHash);
                    if ($event !== null) {
                        ++$sentBy;
                        $copy ??= $event;
                        break;
                    }
                }
            }
            if ($sentBy >= $majority) {
                $rest->recordOnMajority = true;
                continue;
            }
            $event = $copy ?? RestRecord::event($keys, $rest->bolt11, $rest->verifyUrl, $rest->paymentHash, $invoice->expiresAt, $now, $this->context);
            // At once: each may wait for the relays that do not answer.
            $publishes[] = function () use ($rest, $event, $keys): void {
                $rest->recordOnMajority = $this->pool->publish($this->relays, $event, static fn(): string => $keys->secret->reveal());
                if (!$rest->recordOnMajority) {
                    $this->context->warn('The rest record of an invoice could not be published again on a majority of the relays');
                }
            };
        }
        foreach (Parallel::settle($publishes) as [, $error]) {
            if ($error !== null) {
                throw $error;
            }
        }
    }

    /**
     * Asks for an invoice of the rest (SPEC.md §31, "Asking for a rest invoice"), one check of the
     * invoice at a time in this client, each after reading the rest invoices again: the status
     * with it and the rest part, or null when none was made (a failure is logged, never the
     * check's error). $now, the status's clock, only spares a check with too little time left the
     * wait for its turn: the decision reads the clock once the turn came and the pay request
     * answered, never earlier (a check that waited for another one has less time left).
     *
     * @return array{0: PaymentStatus, 1: RestPart}|null
     */
    private function askForRest(StoredInvoice $invoice, Sale $sale, Registry $registry, PaymentStatus $status, RestPart $rests, int $now): ?array
    {
        $address = (string) $invoice->onchainAddress;
        $lightningAddress = $this->channel->lightningAddress;
        if ($lightningAddress === null || $now + Protocol::MIN_REST_SECONDS > $invoice->expiresAt) {
            return null;
        }

        return $this->locks->with('rest/' . $address . '/' . $invoice->expiresAt, function () use ($invoice, $sale, $registry, $status, $rests, $address, $lightningAddress): ?array {
            $rest = $status->rest;
            \assert($rest !== null);
            try {
                $records = SaleRecords::read($this->pool, $this->context, $sale, $registry, $this->relays);
                $known = [];
                foreach ($rests->invoices as $checked) {
                    $known[$checked->paymentHash] = true;
                }
                foreach ($records->restsOf($address, $invoice->expiresAt) as $declared) {
                    if (!isset($known[$declared->paymentHash]) && $declared->sats === $rest->sats && $this->context->now() < $declared->expiresAt) {
                        // Declared meanwhile by another check: checked as any rest invoice.
                        [$again, $snapshot] = $this->full($invoice, $records, false);

                        return $again instanceof PaymentStatus ? [$again, $snapshot->rests] : null;
                    }
                }
                $lease = $records->leaseOf($address, $invoice->sats, $invoice->expiresAt);
                $pay = Lnurl::payRequest($this->context, $lightningAddress);
                [$min, $max] = $pay->sendable();
                // The clock of the decision: this check's turn came, the pay request answered.
                $ask = RestAsking::ask($rest, $invoice->sats, $invoice->expiresAt, $this->context->now(), $lease === null ? null : ['sats' => $lease->sats, 'expiresAt' => $lease->expiresAt], $min, $max);
                if ($ask === null || $lease === null) {
                    return null;
                }
                [$sats, $expiry] = $ask;
                $asked = Lnurl::requestInvoice($this->context, $pay, $sats, $expiry, $lease->note);
                $decoded = Lnurl::checkInvoice($asked['pr'], $asked['verify'], $pay, $sats, $expiry, $this->context->now());
                $inside = Declaration::inside($asked['pr'], $asked['verify'], $sale->keyHex(), $lease->fiat, $lease->note, $sale->takerTag, ['address' => $address, 'expiresAt' => $invoice->expiresAt]);
                try {
                    $declaration = Declaration::event($sale, $registry, $decoded->paymentHash, $inside, $this->context->now(), $this->context);
                } catch (\LengthException) {
                    throw new InvalidInvoiceException('The declaration of the invoice of the rest does not fit a NIP-44 plaintext');
                }
                if (!$this->pool->publish($this->relays, $declaration, static fn(): string => $sale->secret->reveal())) {
                    throw new NetworkException('Fewer than a majority of the relays took the declaration of the invoice of the rest');
                }
                $keys = RestKeys::of($this->key, $sale->ref, $address, $invoice->expiresAt);
                $record = RestRecord::event($keys, $asked['pr'], $asked['verify'], $decoded->paymentHash, $invoice->expiresAt, $this->context->now(), $this->context);
                if (!$this->pool->publish($this->relays, $record, static fn(): string => $keys->secret->reveal())) {
                    throw new NetworkException('Fewer than a majority of the relays took the rest record of the invoice of the rest');
                }
            } catch (CancelledException $e) {
                throw $e;
            } catch (SattlemeException $e) {
                $this->context->warn('No invoice of the rest could be made: ' . $e->getMessage(), ['code' => $e->getErrorCode()]);

                return null;
            }
            $made = new RestInvoice($asked['pr'], $decoded->paymentHash, $asked['verify'], $sats, $decoded->timestamp, $decoded->expiresAt());
            $made->state = ['open'];
            $received = $status->received;
            \assert($received !== null);

            $withIt = new RestPart([...$rests->invoices, $made]);

            return [PaymentStatus::open($invoice->expiresAt, $received, Combined::rest($invoice->sats, $invoice->expiresAt, $address, $this->context->now(), $received, $withIt)), $withIt];
        });
    }

    /**
     * Publishes the invoice's acceptance record when its status calls for one (SPEC.md §31,
     * "Publishing it", Acceptance::publish()); any failure is logged, never the check's error.
     */
    private function accept(StoredInvoice $invoice, PaymentStatus $status, int $now): void
    {
        $keys = fn(): RestKeys => RestKeys::of($this->key, (string) $invoice->ref, (string) $invoice->onchainAddress, $invoice->expiresAt);
        Acceptance::publish($this->pool, $this->context, $this->relays, $keys, $status, $invoice->sats, $invoice->expiresAt, $now);
    }

    private function registry(): Registry
    {
        $key = $this->channel->registryKey;
        \assert($key !== null);

        return new Registry($key, $this->channel->registryReader);
    }

    /**
     * Runs a part: its value, or its error when it is the SDK's (a cancellation, or a bug, is thrown).
     *
     * @template T
     *
     * @param \Closure(): T $part
     *
     * @return T|SattlemeException
     */
    private static function part(\Closure $part): mixed
    {
        try {
            return $part();
        } catch (CancelledException $e) {
            throw $e;
        } catch (SattlemeException $e) {
            return $e;
        }
    }
}
