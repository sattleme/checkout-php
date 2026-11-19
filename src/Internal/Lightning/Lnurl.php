<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal\Lightning;

use Sattleme\Checkout\Exception\InvalidInvoiceException;
use Sattleme\Checkout\Exception\NetworkException;
use Sattleme\Checkout\Http\HttpResponse;
use Sattleme\Checkout\Internal\BigInt;
use Sattleme\Checkout\Internal\Context;
use Sattleme\Checkout\Internal\HttpGet;
use Sattleme\Checkout\Internal\Json;
use Sattleme\Checkout\Internal\LightningAddress;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Internal\Text;
use Sattleme\Checkout\Internal\Url;

/**
 * The merchant's Lightning address (SPEC.md §16–§18, §22): LUD-16, LUD-06, LUD-12, LUD-21.
 *
 * @internal
 */
final class Lnurl
{
    /** LUD-21's answer for an invoice the service no longer knows. */
    public const NOT_FOUND = 'Not found';

    private function __construct() {}

    /**
     * An LNURL answer (SPEC.md §16): a refusal (its reason), or an object; NetworkException else.
     *
     * @return array{0: 'refusal', 1: string}|array{0: 'object', 1: \stdClass}
     *
     * @throws NetworkException
     */
    public static function classify(HttpResponse $response, string $host): array
    {
        $status = $response->status;
        $body = HttpGet::json($response);
        $ok = $status >= 200 && $status < 300;
        $clientError = $status >= 400 && $status < 500 && $status !== 408 && $status !== 429;
        $serviceStatus = Json::get($body, 'status');
        if (($ok || $clientError) && $body instanceof \stdClass && \is_string($serviceStatus) && Text::asciiLower($serviceStatus) === 'error') {
            $reason = Json::get($body, 'reason');

            return ['refusal', \is_string($reason) ? $reason : ''];
        }
        if (!$ok || !$body instanceof \stdClass) {
            throw new NetworkException("{$host} answered HTTP {$status} without an LNURL answer");
        }

        return ['object', $body];
    }

    /**
     * The pay request of the Lightning address (LUD-16, LUD-06).
     *
     * @throws NetworkException|InvalidInvoiceException
     */
    public static function payRequest(Context $context, #[\SensitiveParameter] LightningAddress $address): PayRequest
    {
        $url = $address->payUrl();
        $answer = self::classify(HttpGet::get($context, $url), $address->domain);
        if ($answer[0] === 'refusal') {
            throw new NetworkException("{$address->domain} refused the Lightning address: {$answer[1]}");
        }
        $json = $answer[1];
        $callback = Json::get($json, 'callback');
        $callbackParts = Url::https($callback);
        $min = Json::safeInteger(Json::get($json, 'minSendable'));
        $max = Json::safeInteger(Json::get($json, 'maxSendable'));
        $metadata = Json::get($json, 'metadata');
        if (Json::get($json, 'tag') !== 'payRequest' || $callbackParts === null || $min === null || $max === null
            || $min < 0 || $max < 0 || $min > $max || !\is_string($metadata)) {
            throw new InvalidInvoiceException("{$address->domain} gave no valid LNURL-pay request");
        }
        \assert(\is_string($callback));
        $commentAllowed = Json::safeInteger(Json::get($json, 'commentAllowed'));

        return new PayRequest($callback, $callbackParts, $min, $max, $metadata, $commentAllowed !== null && $commentAllowed >= 0 ? $commentAllowed : 0);
    }

    /** Whether a label is sent as the invoice's comment: its UTF-8 bytes fit commentAllowed. */
    public static function commentFits(string $label, int $commentAllowed): bool
    {
        return \strlen($label) <= $commentAllowed;
    }

    /**
     * Asks the callback for an invoice of $sats (LUD-06): amount in millisatoshis, expiry in
     * seconds, and the comment when it fits (LUD-12). Not checked here.
     *
     * @param numeric-string $sats
     *
     * @return array{pr: string, verify: string}
     *
     * @throws NetworkException|InvalidInvoiceException
     */
    public static function requestInvoice(Context $context, PayRequest $pay, string $sats, int $expiry, string $label): array
    {
        $parameters = ['amount' => BigInt::mul($sats, '1000'), 'expiry' => (string) $expiry];
        if (self::commentFits($label, $pay->commentAllowed)) {
            $parameters['comment'] = $label;
        }
        $answer = self::classify(HttpGet::get($context, self::withParameters($pay->callback, $parameters)), $pay->callbackParts['host']);
        if ($answer[0] === 'refusal') {
            throw new NetworkException("The Lightning address refused the invoice: {$answer[1]}");
        }
        $pr = Json::get($answer[1], 'pr');
        $verify = Json::get($answer[1], 'verify');
        if (!\is_string($pr) || !\is_string($verify)) {
            throw new InvalidInvoiceException('The Lightning address gave no invoice or no verify URL (LUD-21)');
        }

        return ['pr' => $pr, 'verify' => $verify];
    }

    /**
     * The invoice checks (SPEC.md §18), in their order: the name of the first that fails, or
     * null when the invoice passes them all.
     *
     * @param numeric-string $sats
     */
    public static function failedCheck(string $pr, string $verify, PayRequest $pay, string $sats, int $durationSeconds, int $now): ?string
    {
        $invoice = Bolt11::decode($pr);
        if ($invoice === null) {
            return 'bolt11';
        }
        if ($invoice->network !== 'bc') {
            return 'network';
        }
        if ($invoice->amountMsat !== BigInt::mul($sats, '1000')) {
            return 'amount';
        }
        if ($invoice->descriptionHash !== $pay->descriptionHash()) {
            return 'description_hash';
        }
        $verifyParts = Url::https($verify);
        if ($verifyParts === null || !Url::sameHost($verifyParts, $pay->callbackParts)) {
            return 'verify_url';
        }
        if ($invoice->expirySeconds > $durationSeconds) {
            return 'duration';
        }
        if ($invoice->expirySeconds <= 0) {
            return 'expired';
        }
        if (abs($invoice->timestamp - $now) > Protocol::CLOCK_TOLERANCE) {
            return 'clock';
        }
        if ($invoice->expiresAt() <= $now) {
            return 'expired';
        }

        return null;
    }

    /**
     * The invoice checks: the decoded invoice, or InvalidInvoiceException naming the first check
     * that failed.
     *
     * @param numeric-string $sats
     *
     * @throws InvalidInvoiceException
     */
    public static function checkInvoice(string $pr, string $verify, PayRequest $pay, string $sats, int $durationSeconds, int $now): Bolt11
    {
        $failed = self::failedCheck($pr, $verify, $pay, $sats, $durationSeconds, $now);
        if ($failed !== null) {
            throw new InvalidInvoiceException("The invoice failed its {$failed} check");
        }
        $invoice = Bolt11::decode($pr);
        \assert($invoice !== null);

        return $invoice;
    }

    /**
     * The Lightning part of an invoice (SPEC.md §22, LUD-21): ['open'], ['expired'] or ['paid',
     * preimage]. "Final" is now ≥ expiresAt + 120.
     *
     * @return array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}
     *
     * @throws NetworkException|InvalidInvoiceException
     */
    public static function verify(Context $context, string $bolt11, string $paymentHash, string $verifyUrl, int $expiresAt, int $now): array
    {
        $final = $now >= $expiresAt + Protocol::GRACE_SECONDS;
        $answer = self::classify(HttpGet::get($context, $verifyUrl), HttpGet::host($verifyUrl));

        return self::paymentState($answer, $bolt11, $paymentHash, $final);
    }

    /**
     * What an LNURL answer to the verify URL says of the invoice (SPEC.md §22's table).
     *
     * @param array{0: 'refusal', 1: string}|array{0: 'object', 1: \stdClass} $answer
     *
     * @return array{0: 'open'}|array{0: 'expired'}|array{0: 'paid', 1: string}
     *
     * @throws NetworkException|InvalidInvoiceException
     */
    public static function paymentState(array $answer, string $bolt11, string $paymentHash, bool $final): array
    {
        if ($answer[0] === 'refusal') {
            if (!$final) {
                return ['open'];
            }
            if ($answer[1] === self::NOT_FOUND) {
                return ['expired'];
            }
            throw new NetworkException("The payment check was refused: {$answer[1]}");
        }
        $json = $answer[1];
        $pr = Json::get($json, 'pr');
        if (!\is_string($pr)) {
            throw new InvalidInvoiceException('The payment check names no invoice (LUD-21 pr)');
        }
        if (Text::asciiLower($pr) !== Text::asciiLower($bolt11)) {
            throw new InvalidInvoiceException('The payment check is about another invoice');
        }
        if (Json::get($json, 'settled') !== true) {
            return $final ? ['expired'] : ['open'];
        }
        $preimage = Json::get($json, 'preimage');
        if (!\is_string($preimage) || preg_match('/^[0-9a-fA-F]{64}$/D', $preimage) !== 1) {
            throw new InvalidInvoiceException('Paid without a valid preimage');
        }
        $preimage = Text::asciiLower($preimage);
        if (!hash_equals($paymentHash, hash('sha256', (string) hex2bin($preimage)))) {
            throw new InvalidInvoiceException('The preimage does not match the payment hash');
        }

        return ['paid', $preimage];
    }

    /**
     * $url with these query parameters set, as URLSearchParams.set() does: the first of a name
     * replaced, the others removed, else appended; values form-urlencoded. Its fragment (from its
     * first "#") is dropped: parameters added after it would never reach the server (SPEC.md §17,
     * step 5).
     *
     * @param array<string, string> $parameters
     */
    public static function withParameters(string $url, array $parameters): string
    {
        $hash = strpos($url, '#');
        $url = $hash === false ? $url : substr($url, 0, $hash);
        $question = strpos($url, '?');
        $base = $question === false ? $url : substr($url, 0, $question);
        $pairs = $question === false || $question === \strlen($url) - 1 ? [] : explode('&', substr($url, $question + 1));
        $result = [];
        $set = [];
        foreach ($pairs as $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (\array_key_exists($name, $parameters)) {
                if (isset($set[$name])) {
                    continue;
                }
                $set[$name] = true;
                $result[] = self::formEncode($name) . '=' . self::formEncode($parameters[$name]);
            } elseif ($pair !== '') {
                $result[] = $pair;
            }
        }
        foreach ($parameters as $name => $value) {
            if (!isset($set[$name])) {
                $result[] = self::formEncode($name) . '=' . self::formEncode($value);
            }
        }

        return $base . '?' . implode('&', $result);
    }

    /** application/x-www-form-urlencoded, as browsers write it. */
    private static function formEncode(string $value): string
    {
        $encoded = preg_replace_callback('/[^A-Za-z0-9*\-._ ]/', static fn(array $m): string => sprintf('%%%02X', \ord($m[0])), $value);

        return str_replace(' ', '+', (string) $encoded);
    }
}
