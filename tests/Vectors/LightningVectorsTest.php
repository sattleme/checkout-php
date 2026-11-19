<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Http\HttpClient;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\Lightning\Bolt11;
use Sattleme\Checkout\Internal\Lightning\Lnurl;
use Sattleme\Checkout\Internal\Lightning\PayRequest;
use Sattleme\Checkout\Internal\LightningAddress;
use Sattleme\Checkout\Internal\PaymentCheck;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\StoredInvoice;
use Sattleme\Checkout\Internal\Url;
use Sattleme\Checkout\Tests\Support\Net\TestConnector;
use Sattleme\Checkout\Tests\Support\TestContext;
use Sattleme\Checkout\Tests\Support\Vectors as V;

/** bolt11.json (SPEC.md §19), pay-requests.json (§16), invoice-checks.json (§18), payments.json (§22, its stored invoices too), order-text.json's comments (§17). */
final class LightningVectorsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function bolt11(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/bolt11.json')['cases']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('bolt11')]
    public function testBolt11(array $case): void
    {
        $decoded = Bolt11::decode($case['invoice']);
        if ($case['expected'] === null) {
            self::assertNull($decoded);

            return;
        }
        self::assertNotNull($decoded);
        self::assertSame($case['expected'], [
            'network' => $decoded->network,
            'amountMsat' => $decoded->amountMsat,
            'timestamp' => $decoded->timestamp,
            'expirySeconds' => $decoded->expirySeconds,
            'paymentHash' => $decoded->paymentHash,
            'descriptionHash' => $decoded->descriptionHash,
        ]);
    }

    public function testBolt11HasNoLengthLimit(): void
    {
        // An invoice longer than 1023 characters (the vectors stop at 449): a long description.
        $real = V::s(V::a(V::object('vectors/app/app.json')['realInvoice'])['invoice']);
        self::assertNotNull(Bolt11::decode($real));
        $long = self::withExtraField($real, 13, str_repeat('q', 700));
        self::assertGreaterThan(1023, \strlen($long));
        $decoded = Bolt11::decode($long);
        self::assertNotNull($decoded);
        self::assertSame('21000', $decoded->amountMsat);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: int}> */
    public static function payRequests(): iterable
    {
        return V::indexed('vectors/pay-requests.json', ['cases']);
    }

    /**
     * pay-requests.json (SPEC.md §16): the pay request of shop@breez.tips read from the answer of
     * its pay URL, as createInvoice reads it (Lnurl::payRequest(), over an HttpClient answering
     * it): its callback, its sendable sats, its commentAllowed and the description hash the
     * invoice checks compare (PayRequest::descriptionHash()); or the error.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('payRequests')]
    public function testAPayRequest(array $case, int $index): void
    {
        $http = V::a($case['http']);
        // The body as the vector writes it: its JSON value written again, or its text as it is.
        $body = \array_key_exists('body', $http) ? Json::encode(V::json('vectors/pay-requests.json', ['cases', $index, 'http', 'body'])) : V::s($http['bodyText']);
        $client = new class (V::i($http['status']), $body) implements HttpClient {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private readonly int $status, private readonly string $body) {}

            public function get(string $url, #[\SensitiveParameter] array $headers, int $timeoutMs): HttpResponse
            {
                $this->asked[] = $url;

                return new HttpResponse($this->status, $this->body);
            }
        };
        $address = LightningAddress::parse('shop@breez.tips');
        self::assertNotNull($address);
        $context = TestContext::create(new TestConnector(), 1791378000, $client);
        try {
            $pay = Lnurl::payRequest($context, $address);
            [$min, $max] = $pay->sendable();
            $result = ['callback' => $pay->callback, 'sendable' => ['min' => $min, 'max' => $max], 'commentAllowed' => $pay->commentAllowed, 'descriptionHash' => $pay->descriptionHash()];
        } catch (SattlemeException $e) {
            $result = ['error' => $e->getErrorCode()];
        }
        self::assertSame($case['expected'], $result);
        self::assertSame([$address->payUrl()], $client->asked, 'its pay URL alone');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invoiceChecks(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/invoice-checks.json')['cases']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('invoiceChecks')]
    public function testInvoiceCheck(array $case): void
    {
        $payRequest = V::a(V::object('vectors/invoice-checks.json')['payRequest']);
        $callback = V::s($payRequest['callback']);
        $parts = Url::https($callback);
        self::assertNotNull($parts);
        $pay = new PayRequest($callback, $parts, 1000, 100000000000, V::s($payRequest['metadata']), 0);
        $answer = V::a($case['answer']);
        $sats = V::s($case['sats']);
        \assert(is_numeric($sats));
        $failed = Lnurl::failedCheck(V::s($answer['pr']), V::s($answer['verify']), $pay, $sats, V::i($case['durationSeconds']), V::i($case['now']));
        $expected = V::a($case['expected']);
        if (($expected['valid'] ?? false) === true) {
            self::assertNull($failed);
        } else {
            self::assertSame('invalid_invoice', $expected['error']);
            self::assertSame($expected['check'], $failed);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function payments(): array
    {
        /** @var list<mixed> $cases */
        $cases = V::a(V::object('vectors/payments.json')['cases']);

        return V::named($cases);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('payments')]
    public function testPaymentCheck(array $case): void
    {
        $invoice = V::a(V::object('vectors/payments.json')['invoice']);
        $http = V::a($case['http']);
        $body = \array_key_exists('body', $http) ? Json::encode(self::objects($http['body'])) : V::s($http['bodyText']);
        $expiresAt = V::i($invoice['expiresAt']);
        try {
            $answer = Lnurl::classify(new HttpResponse(V::i($http['status']), $body), 'breez.tips');
            $state = Lnurl::paymentState($answer, V::s($invoice['bolt11']), V::s($invoice['paymentHash']), V::i($case['now']) >= $expiresAt + Protocol::GRACE_SECONDS);
            $result = PaymentCheck::lightningStatus($state, $expiresAt)->toArray();
        } catch (SattlemeException $e) {
            $result = ['error' => $e->getErrorCode()];
        }
        self::assertSame($case['expected'], $result);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: int}> */
    public static function stored(): iterable
    {
        return V::indexed('vectors/payments.json', ['stored']);
    }

    /**
     * A stored invoice (SPEC.md §22), as the integrator's JSON decoder gives it: objects, or
     * arrays.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('stored')]
    public function testStoredInvoice(array $case, int $index): void
    {
        $outcome = static function (mixed $invoice): array {
            try {
                StoredInvoice::check($invoice);

                return ['valid' => true];
            } catch (InvalidOrderException $e) {
                return ['error' => $e->getErrorCode(), 'param' => $e->getParam()];
            }
        };
        $object = V::json('vectors/payments.json', ['stored', $index, 'invoice']);
        self::assertInstanceOf(\stdClass::class, $object);
        self::assertSame($case['expected'], $outcome($object));
        self::assertSame($case['expected'], $outcome($case['invoice']));
    }

    public function testCommentsAreSentWhenTheirBytesFit(): void
    {
        $cases = V::a(V::object('vectors/order-text.json')['comments']);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = V::a($case);
            self::assertSame($case['sent'], Lnurl::commentFits(V::s($case['label']), V::i($case['commentAllowed'])), V::s($case['label']));
        }
    }

    public function testParametersAreAddedToTheCallbacksOwn(): void
    {
        self::assertSame('https://a.example/cb?amount=1000&expiry=900', Lnurl::withParameters('https://a.example/cb', ['amount' => '1000', 'expiry' => '900']));
        self::assertSame('https://a.example/cb?k=v&amount=1000', Lnurl::withParameters('https://a.example/cb?k=v', ['amount' => '1000']));
        self::assertSame('https://a.example/cb?amount=1000&k=v', Lnurl::withParameters('https://a.example/cb?amount=5&k=v&amount=6', ['amount' => '1000']));
        self::assertSame('https://a.example/cb?comment=Order+%C3%A9*%7E%26', Lnurl::withParameters('https://a.example/cb?', ['comment' => 'Order é*~&']));
    }

    /** JSON objects of a vector as objects again (the vectors are read as arrays). */
    private static function objects(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (array_is_list($value) && $value !== []) {
                return array_map(self::objects(...), $value);
            }

            return $value === [] ? [] : (object) array_map(self::objects(...), $value);
        }

        return $value;
    }

    /** $invoice with a field of tag $tag and data $words (bech32 characters) added, checksum made again. */
    private static function withExtraField(string $invoice, int $tag, string $words): string
    {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $separator = (int) strrpos($invoice, '1');
        $hrp = substr($invoice, 0, $separator);
        $data = substr($invoice, $separator + 1, -6);
        $length = \strlen($words);
        $field = $charset[$tag] . $charset[intdiv($length, 32)] . $charset[$length % 32] . $words;
        // After the 7 words of the timestamp, before the 104 of the signature.
        $data = substr($data, 0, 7) . $field . substr($data, 7);
        $values = [];
        foreach (str_split($data) as $char) {
            $values[] = (int) strpos($charset, $char);
        }
        $expanded = [];
        foreach (str_split($hrp) as $char) {
            $expanded[] = \ord($char) >> 5;
        }
        $expanded[] = 0;
        foreach (str_split($hrp) as $char) {
            $expanded[] = \ord($char) & 31;
        }
        $chk = 1;
        foreach ([...$expanded, ...$values, 0, 0, 0, 0, 0, 0] as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $value;
            foreach ([0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3] as $i => $generator) {
                if ((($top >> $i) & 1) === 1) {
                    $chk ^= $generator;
                }
            }
        }
        $chk ^= 1;
        $checksum = '';
        for ($i = 0; $i < 6; ++$i) {
            $checksum .= $charset[($chk >> (5 * (5 - $i))) & 31];
        }

        return $hrp . '1' . $data . $checksum;
    }
}
