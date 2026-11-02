#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for native PHP ZIP legacy codecs. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogNativeZipArchiveReader;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$fixtures = [
    'implode-4k-raw-literals.zip' => [
        'zip' => 'UEsDBBQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAdGVzdC50eHQD9fX19QP19fX1gwD/C1BLAQIUABQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAAAAAAAAAAAAAAAAAAAB0ZXN0LnR4dFBLBQYAAAAAAQABADYAAAA0AAAAAAA=',
        'expected' => 'AAAAA',
        'method' => 6,
    ],
    'implode-8k-coded-literals.zip' => [
        'zip' => 'UEsDBBQABgAGAAAAAAAJUfgZHwAAAAUAAAAIAAAAdGVzdC50eHQP9/f39/f39/f39/f39/f39wP19fX1A/X19fX7AP4PUEsBAhQAFAAGAAYAAAAAAAlR+BkfAAAABQAAAAgAAAAAAAAAAAAAAAAAAAAAAHRlc3QudHh0UEsFBgAAAAABAAEANgAAAEUAAAAAAA==',
        'expected' => 'AAAAA',
        'method' => 6,
    ],
    'deflate64-fixed-extended.zip' => [
        'zip' => 'UEsDBBUAAAAJAAAAAACqTyz9CgAAAASAAAAIAAAAdGVzdC50eHRzHO3/A8AHAAAAUEsBAhQAFQAAAAkAAAAAAKpPLP0KAAAABIAAAAgAAAAAAAAAAAAAAAAAAAAAAHRlc3QudHh0UEsFBgAAAAABAAEANgAAADAAAAAAAA==',
        'expected' => null,
        'method' => 9,
    ],
    'deflate64-dynamic.zip' => [
        'zip' => 'UEsDBBUAAAAJAAAAAADbiEa9fgAAAMgAAAAFAAAAeC5iaW4NjCuSAlAQA6+SySTzuQK1q5CYNetQFA405+e5dHdVYodWT2A1UPO6gH9EgTJJJbaKn43svmg7a9cFjsLdkscy0O864jVe+lai/zCzMy6dA9qZrrMZdexwG8AcSoSDrTQkxpo1QHR0m8/xSZWFvG9u/o6IxfxX7KOtPJXR6S9QSwECFAAVAAAACQAAAAAA24hGvX4AAADIAAAABQAAAAAAAAAAAAAAAAAAAAAAeC5iaW5QSwUGAAAAAAEAAQAzAAAAoQAAAAAA',
        'expected_b64' => 'ISkoIiUkJyghICkkKCAkJyJDKSAgJTwkIiQgIiQlIiIiJCMgKSYmInYpISMnJzokKScjJikpJSYgIigkISUnJyQkJSglJCUgICdsJiQhJWsoJSkiJUsmJCIlUCAoKCkoKCUmJCApJiIlJSMjJSYmJCAiISYpKCgoIiknICAgKCIhJiMgISUhIickIyUgJCQiISklIiYoICAhJyEnJyUiZCglIickJiMmICNcKSMpIz0oJCIgKSAoVSYhKWInJSQjIickIiEnIyU=',
        'method' => 9,
    ],
];
$fixtures['deflate64-fixed-extended.zip']['expected'] = str_repeat('A', 32772);

$tempPaths = [];
try {
    foreach ($fixtures as $name => $fixture) {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-native-zip-test-');
        if (!is_string($path)) {
            throw new RuntimeException('Could not allocate native ZIP verifier fixture.');
        }
        $tempPaths[] = $path;
        $bytes = base64_decode((string)$fixture['zip'], true);
        if (!is_string($bytes) || file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write native ZIP verifier fixture ' . $name . '.');
        }
        $expected = isset($fixture['expected_b64'])
            ? base64_decode((string)$fixture['expected_b64'], true)
            : (string)$fixture['expected'];
        if (!is_string($expected)) {
            throw new RuntimeException('Native ZIP verifier expected payload is invalid for ' . $name . '.');
        }

        $reader = new CatalogNativeZipArchiveReader(['archive' => ['max_entries' => 100]]);
        $record(
            $name . ':legacy-detected',
            $reader->hasLegacyCompression($path),
            'Method ' . (int)$fixture['method'] . ' must route through the native PHP ZIP compatibility reader.'
        );

        $actual = '';
        $result = $reader->walk(
            $path,
            $name,
            max(1048576, strlen($expected) + 1),
            static fn(array $entry): array => [
                'extract' => true,
                'max_bytes' => max(1048576, (int)($entry['size'] ?? 0) + 1),
                'state' => null,
            ],
            static function (array $entry, ?string $temporary) use (&$actual): void {
                if ($temporary === null || !is_file($temporary)) {
                    throw new RuntimeException('Native ZIP verifier did not receive an extracted temporary file.');
                }
                $data = file_get_contents($temporary);
                if (!is_string($data)) {
                    throw new RuntimeException('Native ZIP verifier could not read extracted temporary data.');
                }
                $actual = $data;
            }
        );
        $record(
            $name . ':payload',
            hash_equals($expected, $actual),
            'Decoded payload must match the known-good method ' . (int)$fixture['method'] . ' fixture.'
        );
        $record(
            $name . ':backend',
            (string)($result['format'] ?? '') === 'zip-native-php',
            'Legacy ZIP fixture must report the native PHP backend.'
        );

        $sequential = new CatalogSequentialArchiveReader(['archive' => ['max_entries' => 100]]);
        $record(
            $name . ':sequential-route',
            $sequential->shouldUse($path, $name),
            'Archive coordinator must select sequential/native processing for method 6/9 ZIP files.'
        );
    }

    $nativeSources = [
        $root . '/src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php',
        $root . '/src/Infrastructure/Archive/CatalogZipBitReader.php',
        $root . '/src/Infrastructure/Archive/CatalogZipHuffmanTree.php',
        $root . '/src/Infrastructure/Archive/CatalogZipOutputWriter.php',
        $root . '/src/Infrastructure/Archive/CatalogZipImplodeDecoder.php',
        $root . '/src/Infrastructure/Archive/CatalogZipDeflate64Decoder.php',
    ];
    $externalMarkers = ['proc_open(', 'shell_exec(', 'exec(', 'popen(', 'system(', 'passthru('];
    $externalFound = [];
    foreach ($nativeSources as $sourcePath) {
        $source = (string)@file_get_contents($sourcePath);
        foreach ($externalMarkers as $marker) {
            if (str_contains($source, $marker)) {
                $externalFound[] = basename($sourcePath) . ':' . $marker;
            }
        }
    }
    $record(
        'native_zip_no_external_processes',
        $externalFound === [],
        $externalFound === []
            ? 'Native ZIP compatibility decoding stays entirely inside PHP.'
            : implode(', ', $externalFound)
    );

    $workerVersionSource = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
    $record(
        'worker_fingerprint_tracks_native_zip_codecs',
        str_contains($workerVersionSource, 'CatalogNativeZipArchiveReader.php')
            && str_contains($workerVersionSource, 'CatalogZipImplodeDecoder.php')
            && str_contains($workerVersionSource, 'CatalogZipDeflate64Decoder.php'),
        'Detached workers must restart when native ZIP decoder code changes.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, $error->getMessage());
} finally {
    foreach ($tempPaths as $path) {
        @unlink($path);
    }
}

$failed = 0;
foreach ($results as $result) {
    $status = $result['ok'] ? 'PASS' : 'FAIL';
    echo '[' . $status . '] ' . $result['name'];
    if ($result['detail'] !== '') {
        echo ' — ' . $result['detail'];
    }
    echo PHP_EOL;
    if (!$result['ok']) {
        $failed++;
    }
}

echo PHP_EOL . 'Native ZIP compatibility: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
