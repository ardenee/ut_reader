#!/usr/bin/env php
<?php
/**
 * Read-only archive diagnostic for ZIP/7z/RAR/UMOD-family ingestion.
 *
 * Uses only UnrealDB's in-process PHP archive backends. No shell command,
 * external executable, WinRAR or 7-Zip process is launched.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;

function diagnose_usage(): never
{
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-archive.php [--list-only] <archive-path>\n");
    exit(2);
}

function diagnose_bytes(int $bytes): string
{
    return number_format(max(0, $bytes)) . ' bytes';
}

function diagnose_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    return get_class($error) . ($message !== '' ? ': ' . $message : '');
}

function diagnose_unreal_magic(string $path): string
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return 'unreadable';
    }
    try {
        $prefix = fread($handle, 16);
    } finally {
        fclose($handle);
    }
    if (!is_string($prefix)) {
        return 'unreadable';
    }
    if (strlen($prefix) >= 4 && substr($prefix, 0, 4) === "\xC1\x83\x2A\x9E") {
        return 'UE package magic C1832A9E';
    }
    return $prefix === '' ? 'empty' : 'prefix=' . strtoupper(bin2hex($prefix));
}

$args = array_slice($argv, 1);
$listOnly = false;
$path = '';
foreach ($args as $arg) {
    if ($arg === '--list-only') {
        $listOnly = true;
        continue;
    }
    if (str_starts_with($arg, '--')) {
        diagnose_usage();
    }
    if ($path !== '') {
        diagnose_usage();
    }
    $path = $arg;
}
if ($path === '') {
    diagnose_usage();
}

$resolved = realpath($path);
if (!is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
    fwrite(STDERR, "Archive is not a readable regular file: {$path}\n");
    exit(1);
}

try {
    $application = catalog_bootstrap(false);
    $config = $application->config;
    $name = basename($resolved);
    $size = filesize($resolved);
    $size = $size === false ? 0 : (int)$size;

    echo 'Archive: ' . $resolved . PHP_EOL;
    echo 'Name: ' . $name . PHP_EOL;
    echo 'Size: ' . diagnose_bytes($size) . PHP_EOL;
    echo 'SHA256: ' . hash_file('sha256', $resolved) . PHP_EOL;
    echo 'Capabilities: ' . json_encode(
        CatalogArchiveExtractor::runtimeCapabilities(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
    echo PHP_EOL;

    $extractor = new CatalogArchiveExtractor($config);
    $entries = $extractor->entries($resolved, $name);
    echo 'Entries: ' . number_format(count($entries)) . PHP_EOL;
    echo str_repeat('-', 96) . PHP_EOL;

    $ok = 0;
    $failed = 0;
    $skipped = 0;
    foreach ($entries as $entry) {
        $index = (int)($entry['index'] ?? 0);
        $member = (string)($entry['path'] ?? '');
        $declared = max(0, (int)($entry['size'] ?? 0));
        $backend = (string)($entry['backend'] ?? 'unknown');
        $format = (string)($entry['format'] ?? 'unknown');
        $safe = !empty($entry['safe']);
        $encrypted = !empty($entry['encrypted']);
        $reason = trim((string)($entry['reason'] ?? ''));

        echo '[' . $index . '] ' . $member . PHP_EOL;
        echo '    format=' . $format
            . ' backend=' . $backend
            . ' declared=' . diagnose_bytes($declared)
            . ' safe=' . ($safe ? 'yes' : 'no')
            . ' encrypted=' . ($encrypted ? 'yes' : 'no') . PHP_EOL;

        if (!$safe) {
            echo '    SKIP unsafe: ' . ($reason !== '' ? $reason : 'unspecified') . PHP_EOL;
            $skipped++;
            continue;
        }
        if ($encrypted) {
            echo '    SKIP encrypted/password-protected member.' . PHP_EOL;
            $skipped++;
            continue;
        }
        if ($listOnly) {
            $skipped++;
            continue;
        }

        $temporary = '';
        try {
            $memberLimit = max(
                1024 * 1024,
                $declared > 0 ? $declared + 1024 * 1024 : 1024 * 1024 * 1024
            );
            $temporary = $extractor->extractToTemp($resolved, $name, $entry, $memberLimit);
            $actual = filesize($temporary);
            $actual = $actual === false ? -1 : (int)$actual;
            if ($actual < 0) {
                throw new RuntimeException('Extracted member size could not be read.');
            }
            echo '    OK actual=' . diagnose_bytes($actual)
                . ' sha256=' . hash_file('sha256', $temporary)
                . ' ' . diagnose_unreal_magic($temporary) . PHP_EOL;
            $ok++;
        } catch (Throwable $error) {
            echo '    FAIL ' . diagnose_error($error) . PHP_EOL;
            $failed++;
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    echo str_repeat('-', 96) . PHP_EOL;
    echo 'Summary: ' . number_format($ok) . ' extracted, '
        . number_format($failed) . ' failed, '
        . number_format($skipped) . ' skipped.' . PHP_EOL;

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . diagnose_error($error) . PHP_EOL);
    exit(1);
}
