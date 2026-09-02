#!/usr/bin/env php
<?php
/**
 * Regression gate for UE1/UE2 compact serialization and table diagnostics.
 *
 * UT2004 serializes FString lengths, FName references, export class/super
 * indices and export serial size/offset with AR_INDEX (FCompactIndex), including
 * package versions >= 178. Ordinary PackageIndex/outer fields remain int32.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$readerPath = $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
$pipes = [];
$process = @proc_open([PHP_BINARY, '-l', $readerPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$syntaxOk = false;
$syntaxDetail = '';
if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $syntaxOk = $exit === 0;
    $syntaxDetail = trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxOk, $syntaxDetail);

$compact = static function (int $value): string {
    $negative = $value < 0;
    $v = abs($value);
    $bytes = [];
    $b0 = ($negative ? 0x80 : 0x00) | ($v < 0x40 ? $v : (($v & 0x3f) | 0x40));
    $bytes[] = $b0;
    if (($b0 & 0x40) !== 0) {
        $v >>= 6;
        $b1 = $v < 0x80 ? $v : (($v & 0x7f) | 0x80);
        $bytes[] = $b1;
        if (($b1 & 0x80) !== 0) {
            $v >>= 7;
            $b2 = $v < 0x80 ? $v : (($v & 0x7f) | 0x80);
            $bytes[] = $b2;
            if (($b2 & 0x80) !== 0) {
                $v >>= 7;
                $b3 = $v < 0x80 ? $v : (($v & 0x7f) | 0x80);
                $bytes[] = $b3;
                if (($b3 & 0x80) !== 0) {
                    $v >>= 7;
                    $bytes[] = $v & 0xff;
                }
            }
        }
    }
    return pack('C*', ...$bytes);
};

$temporary = tempnam(sys_get_temp_dir(), 'ue2_ar_index_');
if (!is_string($temporary)) {
    throw new RuntimeException('Could not create temporary UE2 serialization fixture.');
}

try {
    $nameOffset = 56;
    $nameEntry = $compact(5) . "Test\0" . pack('V', 1);
    $importOffset = $nameOffset + strlen($nameEntry);
    $importEntry = $compact(0) . $compact(0) . pack('V', 0) . $compact(0);
    $exportOffset = $importOffset + strlen($importEntry);
    $exportEntry = $compact(0)
        . $compact(0)
        . pack('V', 0)
        . $compact(0)
        . pack('V', 1)
        . $compact(0);

    $header = pack('Vvv', 0x9E2A83C1, 178, 29)
        . pack('V', 1)
        . pack('V2', 1, $nameOffset)
        . pack('V2', 1, $exportOffset)
        . pack('V2', 1, $importOffset)
        . pack('V4', 0x11223344, 0x55667788, 0x99AABBCC, 0xDDEEFF00)
        . pack('V', 0);

    $fixture = $header . $nameEntry . $importEntry . $exportEntry;
    file_put_contents($temporary, $fixture);

    $reader = new CatalogUE2PackageReader($temporary);
    $issues = $reader->validatePackage();
    $headerResult = $reader->getHeader();
    $names = $reader->getNames();
    $imports = $reader->getImports();
    $exports = $reader->getExports();

    $record(
        'version_178_still_uses_compact_fstring_and_fname_indices',
        $issues === []
            && ($headerResult['version'] ?? null) === 178
            && count($names) === 1
            && ($names[0]['name'] ?? null) === 'Test'
            && count($imports) === 1
            && count($exports) === 1,
        'A UE2 version-178 fixture must parse FString/FName/export AR_INDEX fields as compact integers, not int32.'
    );

    $record(
        'ordinary_package_index_fields_remain_int32',
        ($imports[0]['outerIndex'] ?? null) === 0
            && ($exports[0]['outerIndex'] ?? null) === 0,
        'FObjectImport.PackageIndex and FObjectExport.PackageIndex remain ordinary serialized INT fields.'
    );
} finally {
    @unlink($temporary);
}

$source = @file_get_contents($readerPath);
$source = is_string($source) ? $source : '';

$record(
    'package_index_helper_never_switches_to_i32',
    str_contains($source, 'return $this->compactIndex();')
        && !str_contains($source, '$version < 178 ? $this->compactIndex() : $this->i32()'),
    'The legacy AR_INDEX helper must not reintroduce a package-version threshold.'
);

$record(
    'reader_reports_table_entry_offsets',
    str_contains($source, 'Name table entry parse failed')
        && str_contains($source, 'Import table entry parse failed')
        && str_contains($source, 'Export table entry parse failed')
        && str_contains($source, 'entry_head_hex='),
    'Remaining malformed or game-specific files must report the exact failing entry and nearby bytes.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
