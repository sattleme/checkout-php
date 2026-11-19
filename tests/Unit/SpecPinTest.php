<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\Checkout\Internal\Protocol;
use Sattleme\Checkout\Tests\Support\Vectors;

/**
 * The pinned copy of checkout-spec (PORTING.md, "The pinned copy of the specification"): every
 * file PIN.json lists is in spec/ with its SHA-256, and spec/ holds nothing else but PIN.json.
 */
final class SpecPinTest extends TestCase
{
    public function testThePinnedCopyIsExactlyTheFilesOfItsManifest(): void
    {
        $pin = Vectors::object('PIN.json');
        self::assertSame(['repository', 'commit', 'version', 'protocol', 'files'], array_keys($pin));
        self::assertSame('https://github.com/sattleme/checkout-spec', $pin['repository']);
        self::assertIsString($pin['commit']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $pin['commit']);
        self::assertSame(Protocol::SPEC_VERSION, $pin['version']);
        self::assertSame(Protocol::PROTOCOL, $pin['protocol']);
        self::assertIsArray($pin['files']);
        self::assertNotEmpty($pin['files']);

        $present = [];
        $root = Vectors::root();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
            if ($relative !== 'PIN.json') {
                $present[] = $relative;
            }
        }
        sort($present);
        $listed = array_keys($pin['files']);
        sort($listed);
        self::assertSame($listed, $present, 'spec/ holds exactly the files of PIN.json');

        foreach ($pin['files'] as $relative => $sha256) {
            self::assertIsString($relative);
            self::assertSame($sha256, hash_file('sha256', Vectors::path($relative)), "SHA-256 of spec/{$relative}");
        }
    }

    public function testEveryVectorFileHasARunner(): void
    {
        // PORTING.md: a runner reads each vector file of the pinned copy and checks the port.
        $runners = '';
        $files = glob(\dirname(__DIR__) . '/Vectors/*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            $runners .= (string) file_get_contents($file);
        }
        $pin = Vectors::object('PIN.json');
        self::assertIsArray($pin['files']);
        $vectors = array_filter(array_keys($pin['files']), static fn(int|string $file): bool => str_starts_with((string) $file, 'vectors/'));
        self::assertNotEmpty($vectors);
        foreach ($vectors as $file) {
            self::assertStringContainsString("'{$file}'", $runners, "spec/{$file} is read by a runner");
        }
    }
}
