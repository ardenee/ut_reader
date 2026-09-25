#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$projection = (string)@file_get_contents(
    $root . '/src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php'
);
$backupImport = (string)@file_get_contents(
    $root . '/src/Infrastructure/Jobs/GameBackupImportJobHandler.php'
);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'full_sync_resets_dependency_identity_projection',
    str_contains($projection, 'DELETE i FROM ue_dependency_identity_lookup i')
        && str_contains($projection, "'dependency_identity_rows'"),
    'Full Sync reset must clear v4 dependency identity rows with dependency links'
);

$record(
    'full_sync_documents_format4_publication',
    str_contains($projection, 'format-4 metadata')
        && !str_contains($projection, 'format-3 metadata'),
    'Full Sync documentation must describe current format-4 publication'
);

$record(
    'game_backup_import_finishes_current_metadata',
    str_contains($backupImport, 'PdoCatalogPackageImporter')
        && str_contains($backupImport, 'VerifiedFileCompactMetadataFinalizer::finalize('),
    'Game Backup restore entries must pass through canonical import and current-format finalization'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
