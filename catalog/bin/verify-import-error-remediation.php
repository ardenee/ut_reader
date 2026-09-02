#!/usr/bin/env php
<?php
/**
 * Regression gate for 2026-09-02 import-error remediation:
 * - duplicate download suffixes attached directly to Unreal extensions
 * - no archive entry-count policy cap
 * - unsupported Unreal reader diagnostics retain serialized version values
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'lib/CatalogSupportCore.php',
    'src/Infrastructure/Archive/CatalogArchiveExtractor.php',
    'src/Infrastructure/Archive/CatalogExternalArchiveReader.php',
    'src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php',
    'src/Infrastructure/Archive/CatalogSequentialArchiveReader.php',
    'src/Infrastructure/Archive/CatalogUmodArchiveReader.php',
    'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

require_once $root . '/lib/CatalogSupportCore.php';
$filenameCases = [
    'HaloMasterchief_purpleMod.u(2)' => 'HaloMasterchief_purpleMod.u',
    'HaloMasterchief_purpleMod.u (2)' => 'HaloMasterchief_purpleMod.u',
    'HaloGhostSM.usx(1)' => 'HaloGhostSM.usx',
    'HaloGhostSM.usx (1)' => 'HaloGhostSM.usx',
];
foreach ($filenameCases as $input => $expected) {
    $record(
        'duplicate_suffix_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($input)),
        catalog_clean_unreal_filename($input) === $expected,
        $input . ' must normalize to ' . $expected
    );
}

$archiveFiles = [
    'src/Infrastructure/Archive/CatalogArchiveExtractor.php',
    'src/Infrastructure/Archive/CatalogExternalArchiveReader.php',
    'src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php',
    'src/Infrastructure/Archive/CatalogSequentialArchiveReader.php',
    'src/Infrastructure/Archive/CatalogUmodArchiveReader.php',
    'assets/public-upload-archive-worker.js',
    'assets/public-upload-umod-worker.js',
];
$entryCapLeaks = [];
foreach ($archiveFiles as $relative) {
    $content = $read($relative);
    if (
        str_contains($content, 'Archive contains too many entries')
        || str_contains($content, 'Archive contains too many file entries')
        || str_contains($content, 'maxEntries()')
        || str_contains($content, 'maximumEntries')
    ) {
        $entryCapLeaks[] = $relative;
    }
}
$record(
    'archive_entry_count_is_unbounded',
    $entryCapLeaks === [],
    $entryCapLeaks === [] ? 'Entry count is not a rejection criterion; byte/path/member safety bounds remain.' : implode(', ', $entryCapLeaks)
);

$indexer = $read('src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$record(
    'unsupported_reader_reports_serialized_values',
    str_contains($indexer, "'package_version' => \$packageVersion")
        && str_contains($indexer, "'licensee_version' => \$licenseeVersion")
        && str_contains($indexer, "'engine_hint' => \$engineHint")
        && str_contains($indexer, "package_version=' . \$packageVersion")
        && str_contains($indexer, "licensee_version=' . (\$licenseeVersion === null ? 'null'"),
    'Unsupported-reader failures must expose package_version, licensee_version and engine_hint in both message and structured values.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
