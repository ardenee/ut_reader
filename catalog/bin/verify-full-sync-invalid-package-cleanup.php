#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies that Full Sync preserves a present verified package when current parser validation fails.
 * Role: Compatibility-named regression gate preventing reconciliation from deleting authoritative package bytes.
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
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'lib/GameProfiles.php',
    'src/Infrastructure/Import/CatalogInvalidPackageException.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageInspector.php',
    'src/Infrastructure/Jobs/CatalogFullSyncUnitJobHandler.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php',
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

try {
    require_once $root . '/lib/GameProfiles.php';
    $record(
        'epic_ue3_detection_is_bounded',
        gp_engine_from_version(334) === 'UE3'
            && gp_engine_from_version(512) === 'UE3'
            && gp_engine_from_version(867) === 'UE3'
            && gp_engine_from_version(868) === null
            && gp_engine_from_version(8261) === null
            && gp_engine_from_version(8320) === null,
        'Only Epic UE3 package versions 334..867 may provide a UE3 engine hint.'
    );
} catch (Throwable $error) {
    $record('epic_ue3_detection_is_bounded', false, get_class($error) . ': ' . $error->getMessage());
}

$exception = $read('src/Infrastructure/Import/CatalogInvalidPackageException.php');
$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$inspector = $read('src/Infrastructure/Import/CatalogVerifiedPackageInspector.php');
$unit = $read('src/Infrastructure/Jobs/CatalogFullSyncUnitJobHandler.php');
$actions = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php');

$record(
    'invalid_package_has_explicit_exception_type',
    str_contains($exception, 'final class CatalogInvalidPackageException extends RuntimeException')
        && str_contains($importer, 'CatalogPackageImporterFactory::create')
        && substr_count($inspector, 'throw new CatalogInvalidPackageException(') >= 3,
    'Parser validation failures remain typed in the canonical inspector, but Full Sync must not translate that type into deletion.'
);
$record(
    'full_sync_validation_failure_preserves_verified_file',
    str_contains($unit, "execute('sync_reimport'")
        && !str_contains($unit, 'CatalogFileMaintenanceRemovalService')
        && !str_contains($unit, "'status' => 'removed_invalid'")
        && !str_contains($unit, 'Removed invalid verified package')
        && str_contains($unit, 'not a destructive validity sweep'),
    'A present verified package must remain intact when a Full Sync parser/reader validation fails.'
);
$record(
    'full_sync_failure_remains_visible',
    !str_contains($unit, 'catch (CatalogInvalidPackageException')
        && str_contains($unit, "private function reimport("),
    'Validation errors must escape the one-file child so the workflow reports the exact file as failed instead of silently completing.'
);
$record(
    'missing_storage_preserves_verified_file',
    !str_contains($actions, "'status' => 'removed_missing'")
        && str_contains($actions, 'throw new RuntimeException($this->missingStorageMessage($file))')
        && str_contains($actions, 'Catalog record preserved; restore the package or remove it explicitly.'),
    'A missing stored package must remain visible as an operator-actionable failure; reconciliation may not delete its catalog identity.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
