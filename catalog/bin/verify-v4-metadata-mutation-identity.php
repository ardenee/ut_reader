#!/usr/bin/env php
<?php
/**
 * Static regression gate for current-format package identity mutation.
 *
 * Renaming/rebasing a verified package must recompute the v4 identity fields
 * consumed by UE1/UE2 VerifyImport projections before republishing .uedb4.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$mutationPath = $root . '/src/Infrastructure/Metadata/CatalogCompactMetadataMutationService.php';
$converterPath = $root . '/src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php';

$mutation = (string)@file_get_contents($mutationPath);
$converter = (string)@file_get_contents($converterPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'mutation_loads_current_engine_profile',
    str_contains($mutation, 'COALESCE(p.engine_key,"")')
        && str_contains($mutation, 'LEFT JOIN ue_game_profiles p'),
    'package identity republishing must use the file\'s current active engine profile'
);

$record(
    'mutation_recomputes_current_identity_fields',
    str_contains($mutation, 'CatalogCompactIdentityEnricher::enrich($snapshot, $engineKey)')
        && str_contains($mutation, 'verify_class_package/verify_identity_hash'),
    'package rename must recompute v4 VerifyImport identity fields before publication'
);

$enrich = strpos($mutation, 'CatalogCompactIdentityEnricher::enrich($snapshot, $engineKey)');
$publish = strpos($mutation, 'new BlockedCompressedMetadataSnapshotWriter');
$record(
    'identity_recomputed_before_publication',
    $enrich !== false && $publish !== false && $enrich < $publish,
    'identity enrichment must occur before the v4 writer is invoked'
);

foreach ([
    'ue_export_path_lookup',
    'ue_dependency_identity_lookup',
    'ue_legacy_export_identity_lookup',
] as $table) {
    $record(
        'projection_maintenance_requires:' . $table,
        str_contains($converter, "'" . $table . "'"),
        'projection rebuild maintenance must require the complete current v4 schema'
    );
}

$syntaxFailures = [];
foreach ([$mutationPath, $converterPath] as $path) {
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
        continue;
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim($stdout . ' ' . $stderr);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
