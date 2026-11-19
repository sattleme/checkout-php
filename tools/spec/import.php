<?php

/*
 * The pinned copy of the specification (sattleme/checkout-spec, its PORTING.md: "The pinned copy
 * of the specification"): spec/ holds the files its manifest.json lists, unchanged, and
 * spec/PIN.json says which commit they come from, with the SHA-256 of each, as checkout-node's
 * scripts/spec.mjs writes it. tests/Unit/SpecPinTest.php checks the copy.
 *
 *   php tools/spec/import.php <checkout-spec clone>
 *       Replaces spec/ with the files of a clean clone, at the commit it is on, and writes
 *       PIN.json. Its manifest.json must be its files'. Clone and check out the version yourself:
 *       no network.
 */

declare(strict_types=1);

const REPOSITORY = 'https://github.com/sattleme/checkout-spec';

/** @param list<string> $arguments */
function git(string $clone, array $arguments): string
{
    $command = array_merge(['git', '-C', $clone], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('git cannot run');
    }
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . trim($errors));
    }

    return trim($output);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        assert($entry instanceof SplFileInfo);
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$clone = $argv[1] ?? null;
if ($clone === null || !is_dir($clone)) {
    fwrite(STDERR, "Usage: php tools/spec/import.php <checkout-spec clone>\n");
    exit(2);
}
if (git($clone, ['status', '--porcelain']) !== '') {
    fwrite(STDERR, "{$clone} is not clean\n");
    exit(1);
}
$commit = git($clone, ['rev-parse', 'HEAD']);
$manifest = json_decode((string) file_get_contents($clone . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest) || !is_array($manifest['files'] ?? null) || !is_string($manifest['version'] ?? null) || !is_int($manifest['protocol'] ?? null)) {
    fwrite(STDERR, "{$clone}/manifest.json is not a manifest\n");
    exit(1);
}
foreach ($manifest['files'] as $file => $sha256) {
    if (!is_string($file) || str_contains($file, '..') || hash_file('sha256', $clone . '/' . $file) !== $sha256) {
        fwrite(STDERR, "{$file} is not the manifest's\n");
        exit(1);
    }
}
$copy = dirname(__DIR__, 2) . '/spec';
removeTree($copy);
foreach (array_keys($manifest['files']) as $file) {
    $target = $copy . '/' . $file;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
        throw new RuntimeException("Cannot make the directory of {$file}");
    }
    if (!copy($clone . '/' . $file, $target)) {
        throw new RuntimeException("Cannot copy {$file}");
    }
}
$pin = [
    'repository' => REPOSITORY,
    'commit' => $commit,
    'version' => $manifest['version'],
    'protocol' => $manifest['protocol'],
    'files' => $manifest['files'],
];
// As JSON.stringify(pin, null, 2) writes it, then a newline.
$json = json_encode($pin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$json = (string) preg_replace_callback('/^(?: {4})+/m', static fn(array $indent): string => str_repeat(' ', intdiv(strlen($indent[0]), 2)), $json);
file_put_contents($copy . '/PIN.json', $json . "\n");
echo "spec/ is checkout-spec {$pin['version']} at {$commit}\n";
