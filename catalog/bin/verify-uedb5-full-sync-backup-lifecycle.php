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
    str_contains($projection, 'DELETE e FROM ue_uedb5_dependency_edges e')
        && str_contains($projection, 'DELETE p FROM ue_uedb5_dependency_packages p')
        && str_contains($projection, 'DELETE p FROM ue_uedb5_provider_keys p'),
    'Full Sync reset must clear UEDB5 dependency edges, package summaries and provider keys'
);

$record(
    'full_sync_documents_uedb5_publication',
    str_contains($projection, 'UEDB5 metadata')
        && !str_contains($projection, 'format-4 metadata'),
    'Full Sync documentation must describe UEDB5 publication'
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
