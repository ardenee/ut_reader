#!/usr/bin/env php
<?php
/** Read-only regression verifier for historical ZIP local/central filename mismatches. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogNativeZipArchiveReader;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$paths = [];
try {
    // Known-good PKZIP Implode fixture. Change only the central-directory name
    // from test.txt to best.txt, leaving the local-header filename untouched.
    // Historical ZIP tools commonly rewrote the central directory without
    // rewriting local filename bytes; the central directory is the canonical
    // archive index and the local name is not used as the extraction path.
    $bytes = base64_decode(
        'UEsDBBQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAdGVzdC50eHQD9fX19QP19fX1gwD/C1BLAQIUABQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAAAAAAAAAAAAAAAAAAAB0ZXN0LnR4dFBLBQYAAAAAAQABADYAAAA0AAAAAAA=',
        true
    );
    if (!is_string($bytes)) {
        throw new RuntimeException('Could not decode local/central mismatch fixture.');
    }
    $central = strpos($bytes, "PK\x01\x02");
    if ($central === false) {
        throw new RuntimeException('Could not locate fixture central directory.');
    }
    $nameOffset = $central + 46;
    if (substr($bytes, $nameOffset, 8) !== 'test.txt') {
        throw new RuntimeException('Unexpected fixture central filename.');
    }
    $bytes = substr_replace($bytes, 'best.txt', $nameOffset, 8);

    $path = tempnam(sys_get_temp_dir(), 'unrealdb-zip-local-name-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not allocate mismatch fixture.');
    }
    $paths[] = $path;
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not write mismatch fixture.');
    }

    $reader = new CatalogNativeZipArchiveReader(['archive' => ['max_entries' => 100]]);
    $record(
        'central_name_mismatch_still_detects_legacy_method',
        $reader->hasLegacyCompression($path),
        'Local filename bytes must not prevent central-directory method detection.'
    );

    $actual = '';
    $seenPath = '';
    $reader->walk(
        $path,
        'local-central-name-mismatch.zip',
        1048576,
        static fn(array $entry): array => [
            'extract' => true,
            'max_bytes' => 1048576,
            'state' => null,
        ],
        static function (array $entry, ?string $temporary) use (&$actual, &$seenPath): void {
            $seenPath = (string)($entry['path'] ?? '');
            if ($temporary === null || !is_file($temporary)) {
                throw new RuntimeException('Mismatch verifier did not receive extracted data.');
            }
            $data = file_get_contents($temporary);
            if (!is_string($data)) {
                throw new RuntimeException('Mismatch verifier could not read extracted data.');
            }
            $actual = $data;
        }
    );
    $record(
        'central_name_is_authoritative_path',
        $seenPath === 'best.txt',
        'The safe central-directory filename must remain the catalog/extraction path.'
    );
    $record(
        'mismatched_local_name_payload_still_crc_verified',
        $actual === 'AAAAA',
        'Payload extraction must still complete through normal size/CRC validation.'
    );

    $source = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php');
    $record(
        'reader_does_not_require_local_central_name_equality',
        !str_contains($source, 'local/central member names disagree'),
        'Historical ZIP filename differences must not be treated as payload corruption.'
    );
    $record(
        'reader_still_validates_local_header_and_method',
        str_contains($source, 'local member header signature is invalid')
            && str_contains($source, 'local/central compression methods disagree'),
        'Removing filename equality must not remove local-header identity checks.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, $error->getMessage());
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
}

$failed = 0;
foreach ($results as $result) {
    echo '[' . ($result['ok'] ? 'PASS' : 'FAIL') . '] ' . $result['name'];
    if ($result['detail'] !== '') {
        echo ' — ' . $result['detail'];
    }
    echo PHP_EOL;
    if (!$result['ok']) {
        $failed++;
    }
}

echo PHP_EOL . 'Native ZIP local-header regressions: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
