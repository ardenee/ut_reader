#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for native ZIP runtime compatibility fixes. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogNativeZipArchiveReader;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$containsFunctionCall = static function (string $source, string $functionName): bool {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || strcasecmp($token[1], $functionName) !== 0) {
            continue;
        }
        for ($next = $index + 1; $next < $count; $next++) {
            $candidate = $tokens[$next];
            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($candidate === '(') {
                return true;
            }
            break;
        }
    }
    return false;
};

$tempPaths = [];
try {
    $reader = new CatalogNativeZipArchiveReader(['archive' => ['max_entries' => 100]]);

    // Private methods are invokable through ReflectionMethod without setAccessible()
    // on supported PHP versions. Avoid the PHP 8.5 deprecation warning entirely.
    $decodeName = new ReflectionMethod(CatalogNativeZipArchiveReader::class, 'decodeName');
    $record(
        'cp437_ascii_name_without_mbstring_alias',
        $decodeName->invoke($reader, 'AS-Pollux/Maps/AS-Pollux.unr', 0) === 'AS-Pollux/Maps/AS-Pollux.unr',
        'ASCII ZIP member names must decode without consulting mb_convert_encoding().'
    );
    $record(
        'cp437_high_byte_decoding',
        $decodeName->invoke($reader, "caf\x82.txt", 0) === 'café.txt',
        'ZIP default CP437 byte 0x82 must decode to U+00E9.'
    );

    // Known-good method-6 fixture from the main native ZIP verifier. Append more
    // than the ZIP-spec EOCD search window to emulate old mirrors that preserve
    // valid ZIP bytes and add transport/readme/padding data after the archive.
    $fixture = base64_decode(
        'UEsDBBQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAdGVzdC50eHQD9fX19QP19fX1gwD/C1BLAQIUABQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAAAAAAAAAAAAAAAAAAAB0ZXN0LnR4dFBLBQYAAAAAAQABADYAAAA0AAAAAAA=',
        true
    );
    if (!is_string($fixture)) {
        throw new RuntimeException('Could not decode trailing-data ZIP regression fixture.');
    }
    $path = tempnam(sys_get_temp_dir(), 'unrealdb-native-zip-runtime-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not allocate trailing-data ZIP regression fixture.');
    }
    $tempPaths[] = $path;
    $fixture .= str_repeat('TRAILING-MIRROR-DATA', 6000);
    if (file_put_contents($path, $fixture) !== strlen($fixture)) {
        throw new RuntimeException('Could not write trailing-data ZIP regression fixture.');
    }

    $record(
        'eocd_with_large_trailing_data_detected',
        $reader->hasLegacyCompression($path),
        'A valid legacy ZIP must still be found when more than 65,557 bytes follow its EOCD.'
    );

    $actual = '';
    $result = $reader->walk(
        $path,
        'legacy-with-trailing-data.zip',
        1048576,
        static fn(array $entry): array => [
            'extract' => true,
            'max_bytes' => 1048576,
            'state' => null,
        ],
        static function (array $entry, ?string $temporary) use (&$actual): void {
            if ($temporary === null || !is_file($temporary)) {
                throw new RuntimeException('Trailing-data verifier did not receive an extracted file.');
            }
            $data = file_get_contents($temporary);
            if (!is_string($data)) {
                throw new RuntimeException('Trailing-data verifier could not read extracted data.');
            }
            $actual = $data;
        }
    );
    $record(
        'eocd_with_large_trailing_data_payload',
        $actual === 'AAAAA' && (string)($result['format'] ?? '') === 'zip-native-php',
        'The recovered EOCD must lead to the same CRC/size-verified method-6 payload.'
    );

    $source = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php');
    $record(
        'native_zip_filename_decode_has_no_mbstring_dependency',
        !$containsFunctionCall($source, 'mb_convert_encoding'),
        'ZIP CP437 filename decoding must remain native PHP and independent of mbstring encoding aliases.'
    );
    $record(
        'native_zip_eocd_candidate_is_validated',
        str_contains($source, 'isPlausibleEocd(')
            && str_contains($source, 'EOCD_SEARCH_BYTES = 16777216'),
        'Extended EOCD scanning must retain central-directory validation and a bounded search window.'
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

echo PHP_EOL . 'Native ZIP runtime regressions: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
