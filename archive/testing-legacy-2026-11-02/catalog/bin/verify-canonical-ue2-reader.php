#!/usr/bin/env php
<?php
/**
 * Regression gate: UE2 package parsing must have one canonical production
 * implementation and every catalog path must resolve through it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogScanner.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$expectedClass = 'UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogUE2PackageReader';

$resolved = CatalogReaderResolver::resolve(
    [],
    'UE2',
    'Reader not found for package engine',
    'Reader file loaded for package engine ',
    ['UE4', 'UE5']
);
$record(
    'resolver_selects_canonical_ue2_reader',
    $resolved === $expectedClass,
    'CatalogReaderResolver must return the single CatalogUE2PackageReader implementation.'
);

$scannerResolved = scanner_load_reader_class([], 'UE2');
$record(
    'scanner_api_uses_same_ue2_reader',
    $scannerResolved === $expectedClass,
    'The scanner compatibility API must delegate UE2 selection to the same canonical reader.'
);

$readerFile = realpath($root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php');
$record(
    'canonical_reader_file_exists',
    is_string($readerFile) && is_file($readerFile),
    'UE1/UE2 package parsing lives in CatalogLegacyPackageReader.php.'
);

$definitionFiles = [];
$directProductionInstantiations = [];
$roots = [
    $root . '/src',
    $root . '/lib',
    $root . '/parsers',
];

foreach ($roots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
            continue;
        }
        $path = $item->getPathname();
        $source = @file_get_contents($path);
        if (!is_string($source)) {
            continue;
        }

        if (preg_match('/class\s+[A-Za-z0-9_]*UE2[A-Za-z0-9_]*PackageReader\b/', $source) === 1) {
            $definitionFiles[] = str_replace('\\', '/', $path);
        }

        if ($readerFile !== false
            && realpath($path) !== $readerFile
            && preg_match('/new\s+(?:\\\\)?(?:[A-Za-z0-9_\\\\]+\\\\)?CatalogUE2PackageReader\b/', $source) === 1) {
            $directProductionInstantiations[] = str_replace('\\', '/', $path);
        }
    }
}

$record(
    'only_one_production_ue2_reader_definition',
    count($definitionFiles) === 1
        && $readerFile !== false
        && realpath($definitionFiles[0]) === $readerFile,
    'Found: ' . implode(', ', $definitionFiles)
);

$record(
    'no_production_path_bypasses_reader_resolver',
    $directProductionInstantiations === [],
    $directProductionInstantiations === []
        ? 'No src/lib/parsers path directly instantiates CatalogUE2PackageReader.'
        : 'Direct instantiations: ' . implode(', ', $directProductionInstantiations)
);

$verifiedPath = $root . '/src/Infrastructure/Import/CatalogVerifiedPackageInspector.php';
$unverifiedPath = $root . '/src/Infrastructure/Import/CatalogUnverifiedPackageRuntime.php';
$cliPath = $root . '/bin/inspect-unreal-file.php';
$verified = (string)@file_get_contents($verifiedPath);
$unverified = (string)@file_get_contents($unverifiedPath);
$cli = (string)@file_get_contents($cliPath);

$record(
    'verified_import_resolves_reader_centrally',
    str_contains($verified, 'scanner_load_reader_class($this->config, $readerEngine)'),
    'Verified import must resolve the header-selected reader through the shared resolver API.'
);

$record(
    'unverified_import_resolves_reader_centrally',
    str_contains($unverified, 'scanner_load_reader_class($this->config, $engine)'),
    'Unverified indexing must use the same shared resolver API.'
);

$record(
    'cli_inspector_resolves_reader_centrally',
    str_contains($cli, 'scanner_load_reader_class($config, $engine)'),
    'Diagnostic CLI must exercise the same UE2 reader used by catalog import paths.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
