<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Client;

/**
 * SPEC.md §27: a key, a secret or the merchant's tolerance (nor the amount it leaves due) is never
 * in a stack trace. Every parameter that takes one as a string or an integer, a record's content
 * before it is sealed or HTTP headers has #[\SensitiveParameter]; one that takes it as an object
 * (Secret, IntegrationKey) shows only its class in a trace. So does every parameter that takes an
 * object holding the tolerance or the merchant's Lightning address (a channel, a sale's records, a
 * lease), a closure's too, and each one of DEFENSE, which takes a secret or a plaintext under a
 * generic name.
 */
final class SensitiveParametersTest extends TestCase
{
    /** Named so, yet public: a WebSocket handshake's random Sec-WebSocket-Key. */
    private const PUBLIC = ['Internal\Net\WebSocket::handshake $key'];

    /** Objects that hold the tolerance or the merchant's Lightning address. */
    private const HOLDERS = ['Internal\Channel', 'Internal\SaleRecords', 'Internal\Onchain\Lease', 'Internal\LightningAddress'];

    /** Parameters of generic names that take a secret (a key's bytes, d, k) or a plaintext. */
    private const DEFENSE = [
        'Internal\Base64::encodeUrl $bytes',
        'Internal\Base64::decodeUrl $text',
        'Internal\BigInt::fromBytes $bytes',
        'Internal\BigInt::toBytes $value',
        'Internal\BigInt::add $a',
        'Internal\BigInt::add $b',
        'Internal\BigInt::sub $a',
        'Internal\BigInt::sub $b',
        'Internal\BigInt::mul $a',
        'Internal\BigInt::mul $b',
        'Internal\BigInt::mod $a',
        'Internal\Crypto\Nip44::encrypt $plaintext',
        'Internal\Crypto\Nip44::chacha20 $data',
        'Internal\Json::decode $text',
    ];

    public function testSecretsNeverReachAStackTrace(): void
    {
        $file = (new \ReflectionClass(Client::class))->getFileName();
        self::assertIsString($file);
        $source = \dirname($file);
        $namespace = substr(Client::class, 0, -\strlen('Client'));
        $missing = [];
        $checked = 0;
        $holders = 0;
        $defense = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            \assert($path instanceof \SplFileInfo);
            $relative = str_replace('/', '\\', substr($path->getPathname(), \strlen($source) + 1, -\strlen('.php')));
            $class = $namespace . $relative;
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $name = $parameter->getName();
                    $type = $parameter->getType();
                    $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                    $where = "{$relative}::{$method->getName()} \${$name}";
                    $scalar = \in_array($typeName, ['string', 'int'], true)
                        && preg_match('/secret|key|seed|private|plaintext|^ik$|toleran|^due$/i', $name) === 1 && preg_match('/public|pubkey/i', $name) !== 1;
                    // What a record holds before it is sealed, and an HTTP request's headers.
                    $structure = \in_array($typeName, ['array', 'mixed'], true) && preg_match('/^(inside|headers)$/', $name) === 1;
                    $holder = $typeName !== null && \in_array(substr($typeName, \strlen($namespace)), self::HOLDERS, true);
                    if ($holder) {
                        ++$holders;
                    }
                    if (\in_array($where, self::DEFENSE, true)) {
                        $defense[] = $where;
                    } elseif (!$scalar && !$structure && !$holder) {
                        continue;
                    }
                    ++$checked;
                    if ($parameter->getAttributes(\SensitiveParameter::class) === [] && !\in_array($where, self::PUBLIC, true)) {
                        $missing[] = $where;
                    }
                }
            }
        }
        self::assertGreaterThan(50, $checked);
        self::assertGreaterThan(10, $holders);
        sort($defense);
        $listed = self::DEFENSE;
        sort($listed);
        self::assertSame($listed, $defense, 'Each parameter of DEFENSE exists');
        self::assertSame([], $missing, 'Parameters without #[\SensitiveParameter]');
    }

    /**
     * The closures and arrow functions of the SDK, which reflection on its classes does not see:
     * each parameter typed as an object of HOLDERS has #[\SensitiveParameter] too.
     */
    public function testEveryClosureThatTakesAHolderMarksIt(): void
    {
        $file = (new \ReflectionClass(Client::class))->getFileName();
        self::assertIsString($file);
        $source = \dirname($file);
        $holders = array_map(static fn(string $class): string => substr($class, (int) strrpos('\\' . $class, '\\')), self::HOLDERS);
        $missing = [];
        $checked = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            \assert($path instanceof \SplFileInfo);
            $text = file_get_contents($path->getPathname());
            self::assertIsString($text);
            $tokens = \PhpToken::tokenize($text);
            foreach ($tokens as $i => $token) {
                if (!$token->is([\T_FN, \T_FUNCTION])) {
                    continue;
                }
                // A closure: its parameters right after the keyword (a method or a function is named there).
                $open = $i + 1;
                while (isset($tokens[$open]) && $tokens[$open]->isIgnorable()) {
                    ++$open;
                }
                if (!isset($tokens[$open]) || $tokens[$open]->text !== '(') {
                    continue;
                }
                foreach (self::parameters($tokens, $open) as $parameter) {
                    $names = [];
                    $sensitive = false;
                    foreach ($parameter as $part) {
                        if ($part->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                            $name = substr($part->text, (int) strrpos('\\' . $part->text, '\\'));
                            $names[] = $name;
                            $sensitive = $sensitive || $name === 'SensitiveParameter';
                        }
                    }
                    if (array_intersect($names, $holders) === []) {
                        continue;
                    }
                    ++$checked;
                    if (!$sensitive) {
                        $missing[] = substr($path->getPathname(), \strlen($source) + 1) . ':' . $token->line;
                    }
                }
            }
        }
        self::assertGreaterThan(0, $checked);
        self::assertSame([], $missing, 'Closures whose parameter takes a holder without #[\SensitiveParameter]');
    }

    /**
     * The parameters of the list that opens at $tokens[$open], each its tokens but whitespace and
     * comments.
     *
     * @param array<\PhpToken> $tokens as PhpToken::tokenize() gives them
     *
     * @return list<list<\PhpToken>>
     */
    private static function parameters(array $tokens, int $open): array
    {
        $parameters = [];
        $current = [];
        $depth = 0;
        for ($i = $open; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];
            if (\in_array($token->text, ['(', '[', '#['], true)) {
                ++$depth;
                if ($depth === 1) {
                    continue;
                }
            } elseif (\in_array($token->text, [')', ']'], true)) {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            } elseif ($token->text === ',' && $depth === 1) {
                $parameters[] = $current;
                $current = [];

                continue;
            }
            if (!$token->isIgnorable()) {
                $current[] = $token;
            }
        }
        if ($current !== []) {
            $parameters[] = $current;
        }

        return $parameters;
    }
}
