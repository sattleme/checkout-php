<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * A checkout link's redirect URL (SPEC.md §30, "Redirect URLs"): an https URL of a host, or an
 * app's link of a scheme that is no browser's, file's, script's, transport's, payment's, call's
 * or installer's. Refused, never repaired.
 *
 * @internal
 */
final class RedirectUrl
{
    /** @var list<string> */
    private const REFUSED_SCHEMES = [
        // The browser's own, files and scripts.
        'about', 'blob', 'chrome', 'chrome-extension', 'content', 'data', 'file', 'filesystem', 'javascript',
        'moz-extension', 'resource', 'safari-extension', 'vbscript', 'view-source',
        // Other browsers, which open any web page given in the rest of the link.
        'brave', 'com-apple-mobilesafari-tab', 'ddgquicklink', 'firefox', 'firefox-focus', 'googlechrome',
        'googlechrome-x-callback', 'googlechromes', 'microsoft-edge', 'microsoft-edge-http',
        'microsoft-edge-https', 'onionbrowser', 'onionbrowsers', 'opera-http', 'opera-https',
        'x-safari-http', 'x-safari-https',
        // Other transports, unencrypted ones.
        'ftp', 'http', 'ws', 'wss',
        // Any Android activity, with its extras.
        'android-app', 'intent',
        // Payments.
        'bitcoin', 'keyauth', 'lightning', 'liquidnetwork', 'lnurl', 'lnurlc', 'lnurlp', 'lnurlw',
        // Calls and messages.
        'callto', 'facetime', 'facetime-audio', 'mailto', 'sip', 'sms', 'tel',
        // Installing software, running system tools.
        'itms-services', 'ms-msdt', 'search-ms',
    ];

    private function __construct() {}

    /** What the checkout page shows of a valid redirect URL, or null when it is none. */
    public static function shown(mixed $url): ?string
    {
        if (!\is_string($url) || $url === '' || \strlen($url) > Protocol::MAX_REDIRECT_URL
            || preg_match(Url::CHARACTERS, $url) !== 1) {
            return null;
        }
        if (preg_match('~^https://(([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?::([0-9]{1,5}))?)(?:[/?#].*)?$~sD', $url, $match) === 1) {
            $port = $match[3] ?? '';
            if (!Url::isHost($match[2]) || ($port !== '' && ((int) $port < 1 || (int) $port > 65535))) {
                return null;
            }

            return $match[1];
        }
        if (preg_match('~^([a-z][a-z0-9+.\-]{0,31})://~D', $url, $match) === 1) {
            $scheme = $match[1];
            if ($scheme === 'https' || \in_array($scheme, self::REFUSED_SCHEMES, true)) {
                return null;
            }

            return $scheme . '://';
        }

        return null;
    }

    public static function isValid(mixed $url): bool
    {
        return self::shown($url) !== null;
    }
}
