<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;
use Sattleme\Checkout\DeclaredInvoice;
use Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\Checkout\Exception\InvalidOptionException;
use Sattleme\Checkout\Exception\InvalidOrderException;
use Sattleme\Checkout\Exception\InvoicePendingException;
use Sattleme\Checkout\Exception\OrderPaidException;
use Sattleme\Checkout\Exception\SattlemeException;
use Sattleme\Checkout\Exception\UnsupportedCurrencyException;
use Sattleme\Checkout\Invoice;
use Sattleme\Checkout\PaymentStatus;

/**
 * The README says what the code does: its examples parse and name what exists, and its tables
 * (options, errors, versions, dependencies) are those of the SDK, the spec, composer.json and CI.
 */
final class ReadmeTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function read(string $path): string
    {
        $text = file_get_contents(self::root() . '/' . $path);
        self::assertIsString($text, $path);

        // As written, whatever line endings the checkout gave it (Git for Windows: CRLF).
        return str_replace("\r\n", "\n", $text);
    }

    private static function readme(): string
    {
        return self::read('README.md');
    }

    /**
     * The README's section under $heading, up to the next heading of any level.
     */
    private static function section(string $heading): string
    {
        $readme = self::readme();
        $start = strpos($readme, "\n{$heading}\n");
        self::assertNotFalse($start, $heading);
        $rest = substr($readme, $start + \strlen($heading) + 2);
        $end = preg_match('/^#/m', $rest, $match, \PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : \strlen($rest);

        return substr($rest, 0, $end);
    }

    /**
     * The cells of a section's table rows (its header and rule left out).
     *
     * @return list<list<string>>
     */
    private static function rows(string $heading): array
    {
        $rows = [];
        foreach (explode("\n", self::section($heading)) as $line) {
            if (!str_starts_with($line, '| ') || str_starts_with($line, '| ---')) {
                continue;
            }
            $rows[] = array_map(trim(...), \array_slice(explode('|', $line), 1, -1));
        }
        self::assertNotEmpty($rows, $heading);
        array_shift($rows);

        return $rows;
    }

    /** @return list<string> */
    private static function phpBlocks(): array
    {
        preg_match_all('/^```php\n(.*?)^```$/ms', self::readme(), $matches);
        self::assertNotEmpty($matches[1]);

        return $matches[1];
    }

    /** The SDK's namespace as the README gives it. */
    private static function documented(): string
    {
        self::assertSame(1, preg_match('/namespace `([A-Za-z\\\\]+)`/', self::readme(), $match));

        return $match[1];
    }

    /** The SDK's namespace as this test sees it: the README's, or the prefixed copy's. */
    private static function namespace(): string
    {
        self::assertStringEndsWith(self::documented() . '\Client', Client::class);

        return substr(Client::class, 0, -\strlen('\Client'));
    }

    /**
     * $name as the README writes it (relative to the SDK's namespace or its exceptions', or
     * absolute), resolved; null when no such class or interface exists.
     *
     * @return class-string|null
     */
    private static function resolve(string $name): ?string
    {
        $documented = self::documented() . '\\';
        $relative = str_starts_with($name, $documented) ? substr($name, \strlen($documented)) : $name;
        foreach ([self::namespace() . '\\' . $relative, self::namespace() . '\Exception\\' . $relative] as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The SDK's public classes and interfaces (not Internal), from its files: the prefixed copy's
     * too.
     *
     * @return list<class-string>
     */
    private static function publicClasses(): array
    {
        $file = (new \ReflectionClass(Client::class))->getFileName();
        self::assertIsString($file);
        $source = \dirname($file);
        $classes = [];
        foreach (['', 'Exception/', 'Http/', 'Net/'] as $directory) {
            $files = glob($source . '/' . $directory . '*.php');
            self::assertNotFalse($files);
            foreach ($files as $path) {
                $class = self::namespace() . '\\' . str_replace('/', '\\', $directory) . basename($path, '.php');
                self::assertTrue(class_exists($class) || interface_exists($class), $class);
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function testItsExamplesParse(): void
    {
        foreach (self::phpBlocks() as $index => $code) {
            try {
                \PhpToken::tokenize("<?php\n" . $code, \TOKEN_PARSE);
            } catch (\ParseError $e) {
                self::fail("README, PHP example {$index}: {$e->getMessage()}");
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testItsExamplesImportWhatExists(): void
    {
        $imports = [];
        foreach (self::phpBlocks() as $code) {
            preg_match_all('/^use ([A-Za-z\\\\]+);$/m', $code, $matches);
            $imports = [...$imports, ...$matches[1]];
        }
        self::assertNotEmpty($imports);
        foreach ($imports as $import) {
            self::assertNotNull(self::resolve($import), $import);
        }
    }

    public function testItNamesClassesThatExist(): void
    {
        preg_match_all('/`([A-Z][A-Za-z]*(?:\\\\[A-Z][A-Za-z]*)*)`/', self::readme(), $matches);
        $namespaces = [self::documented(), self::documented() . '\Exception'];
        foreach (array_unique($matches[1]) as $name) {
            if (class_exists('\\' . $name, false) || \in_array($name, $namespaces, true)) {
                continue; // PHP's own (Closure, LogicException), or a namespace.
            }
            self::assertNotNull(self::resolve($name), $name);
        }
    }

    public function testItsMethodsAndPropertiesExist(): void
    {
        $readme = self::readme();
        // Every method it names in backquotes, but PHP's functions, is a public method of the SDK.
        $methods = [];
        foreach (self::publicClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $methods[$method->getName()] = true;
            }
        }
        preg_match_all('/`(?:[A-Za-z]+::|\$[a-z]+->)?([a-z][A-Za-z_]*)\(/', $readme, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $name) {
            if (\function_exists($name) || $name === 'new') {
                continue;
            }
            self::assertArrayHasKey($name, $methods, "{$name}() is no public method of the SDK");
        }
        // $invoice->… and $status->… in its examples are an Invoice's and a PaymentStatus's.
        foreach (['invoice' => Invoice::class, 'status' => PaymentStatus::class] as $variable => $class) {
            preg_match_all('/\$' . $variable . '->([a-zA-Z]+)\b(?!\()/', $readme, $properties);
            self::assertNotEmpty($properties[1], $variable);
            foreach (array_unique($properties[1]) as $property) {
                self::assertTrue(property_exists($class, $property), "{$class}::\${$property}");
            }
        }
    }

    public function testItSaysThatStoredInvoicesAreTheShops(): void
    {
        // SPEC.md §27: getStatus checks that a stored invoice is well formed (§22), never who made
        // it; the shop checks only the invoices it stored itself. Never that every member must be
        // there: a stored invoice may leave out onchainAddress.
        $readme = (string) preg_replace('/\s+/', ' ', self::readme());
        self::assertStringContainsString(
            "**Stored invoices are your shop's.** Check only the invoices your shop stored itself with their orders, where only it writes (its database), never one that comes from the customer (a form field, a URL, a cookie): `getStatus` checks that a stored invoice is well formed (SPEC.md §22), never that this integration made it. An invoice someone else wrote, with a Lightning invoice of their own that they paid, reads as `paid` (SPEC.md §27).",
            $readme,
        );
        self::assertDoesNotMatchRegularExpression('/each of its members|every member/i', $readme);
    }

    public function testItSaysWhatARemovedIntegrationStops(): void
    {
        // SPEC.md §14, as checkout-node's README says it: the calls that read the channel stop;
        // a check still finds a Lightning payment; the merchant settles on-chain ones by hand.
        $key = (string) preg_replace('/\s+/', ' ', self::section('## The integration key is a secret'));
        self::assertStringContainsString(
            'If it leaks, the merchant removes the integration in the app ("Remove the integration") and creates another one: the old key then makes no invoice, finds none and reads no merchant (`RevokedException`). A check of an invoice it made still finds its Lightning payment: an invoice without an address is checked as before, and one with an address is `RevokedException` unless its Lightning invoice was paid (SPEC.md §14). The merchant settles by hand the orders still being paid on-chain.',
            $key,
        );
    }

    public function testItSaysWhetherSigningAndEcdhAreConstantTime(): void
    {
        // SPEC.md §27: the README says whether the SDK's secp256k1 signs (BIP-340) and computes
        // ECDH (NIP-44) in constant time, naming the library and what it does there (its source).
        $security = (string) preg_replace('/\s+/', ' ', self::section('## Security'));
        self::assertStringContainsString('**Signing and ECDH are not constant-time.**', $security);
        foreach (['OpenSSL', 'ossl_ec_scalar_mul_ladder', '`crypto/ec/ec_mult.c`', 'ossl_ecdh_simple_compute_key', '`crypto/ec/ecdh_ossl.c`', 'ossl_ec_key_simple_generate_public_key', '`bcmath`'] as $named) {
            self::assertStringContainsString($named, $security);
        }
    }

    public function testItsVersionsAreTheSdks(): void
    {
        self::assertSame(1, preg_match('/^- Version \*\*([0-9.]+)\*\*\. It implements \[checkout-spec\]\([^)]+\)\s+\*\*([0-9.]+)\*\* \(protocol ([0-9]+)\)/m', self::readme(), $match));
        $pin = json_decode(self::read('spec/PIN.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($pin);
        self::assertSame(Client::VERSION, $match[1]);
        self::assertSame(Client::SPEC_VERSION, $match[2]);
        self::assertSame($pin['version'], $match[2]);
        self::assertSame($pin['protocol'], (int) $match[3]);
    }

    public function testItsOptionsAreTheClients(): void
    {
        $documented = array_map(static fn(array $row): string => trim($row[0], '`'), self::rows('### Options of the client'));
        $options = (new \ReflectionClassConstant(Client::class, 'OPTIONS'))->getValue();
        self::assertIsArray($options);
        // now and random are for tests only.
        self::assertSame(array_values(array_filter($options, static fn(mixed $option): bool => $option !== 'now' && $option !== 'random')), $documented);
    }

    public function testItsErrorsAreTheSpecsAndTheSdks(): void
    {
        $spec = [];
        $inSpec = false;
        foreach (explode("\n", self::read('spec/SPEC.md')) as $line) {
            $inSpec = match (true) {
                $line === '## 5. Errors' => true,
                str_starts_with($line, '## ') => false,
                default => $inSpec,
            };
            if ($inSpec && preg_match('/^\| `([a-z_]+)` +\| ([a-z ]+?) +\|/', $line, $match) === 1) {
                $spec[$match[1]] = $match[2];
            }
        }
        self::assertCount(13, $spec);
        $documented = [];
        foreach (self::rows('## Errors') as [$code, $class, $retryable]) {
            $code = trim($code, '`');
            $documented[$code] = $retryable;
            $resolved = self::resolve(trim($class, '`'));
            self::assertNotNull($resolved, $class);
            foreach ($retryable === 'unless newer' ? [false, true] : [null] as $newer) {
                $error = self::instance($resolved, $newer);
                self::assertSame($code, $error->getErrorCode(), $class);
                self::assertSame($retryable === 'yes' || $newer === false, $error->isRetryable(), $class);
            }
        }
        self::assertSame($spec, $documented);
    }

    /** @param class-string $class */
    private static function instance(string $class, ?bool $newer): SattlemeException
    {
        $invoice = new DeclaredInvoice('ORD-1', null, null, null, null, 'lightning:lnbc1', '1000', null, 'ORD-1', 0, 0, 0);
        $error = match ($class) {
            ChannelNotFoundException::class => new ChannelNotFoundException((bool) $newer),
            InvalidOptionException::class => new InvalidOptionException('options'),
            InvalidOrderException::class => new InvalidOrderException('order'),
            UnsupportedCurrencyException::class => new UnsupportedCurrencyException('XTS'),
            InvoicePendingException::class => new InvoicePendingException($invoice, null),
            OrderPaidException::class => new OrderPaidException($invoice, PaymentStatus::paidByLightning(str_repeat('00', 32))),
            default => (new \ReflectionClass($class))->newInstance(),
        };
        self::assertInstanceOf(SattlemeException::class, $error);

        return $error;
    }

    public function testItsRequirementsAreComposers(): void
    {
        $composer = json_decode(self::read('composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        self::assertIsArray($composer['require-dev']);
        $extensions = [];
        foreach (array_keys($composer['require']) as $package) {
            if (str_starts_with((string) $package, 'ext-')) {
                $extensions[] = substr((string) $package, 4);
            }
        }
        self::assertSame(1, preg_match('/^- Extensions (.+?) \(Composer checks them\)/m', self::section('## Requirements'), $match));
        preg_match_all('/`([a-z0-9]+)`/', $match[1], $named);
        sort($extensions);
        $documented = $named[1];
        sort($documented);
        self::assertSame($extensions, $documented);
        self::assertSame('^8.2', $composer['require']['php']);
        self::assertStringContainsString('PHP **8.2** or later', self::section('## Requirements'));

        // Development packages: exactly composer.json's, at its versions.
        $packages = [];
        foreach (self::rows('## Dependencies') as $row) {
            if (preg_match('/^`([a-z0-9\/._-]+)` ([0-9.]+)$/', $row[0], $match) === 1) {
                $packages[$match[1]] = $match[2];
            }
        }
        ksort($packages);
        $required = $composer['require-dev'];
        ksort($required);
        self::assertSame($required, $packages);
    }

    public function testItsCiToolsAreTheOnesCiRuns(): void
    {
        $dependencies = self::section('## Dependencies');
        self::assertSame(1, preg_match('/Strauss ([0-9.]+) and\s+PHP-Scoper ([0-9.]+)/', $dependencies, $tools));
        self::assertSame(1, preg_match('/\[coincurve\]\([^)]+\) ([0-9.]+)/', $dependencies, $coincurve));
        $ci = self::read('.github/workflows/ci.yml');
        self::assertStringContainsString("strauss/releases/download/{$tools[1]}/strauss.phar", $ci);
        self::assertStringContainsString("php-scoper/releases/download/{$tools[2]}/php-scoper.phar", $ci);
        self::assertMatchesRegularExpression('/^coincurve==' . preg_quote($coincurve[1], '/') . ' /m', self::read('tools/differential/requirements.txt'));
    }
}
