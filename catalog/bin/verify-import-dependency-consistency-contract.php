#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies that every verified-file ingress/mutation path uses the same dependency publication contract.
 * Role: Read-only regression gate for upload, backup, rename, copy/reassignment and Full Sync consistency.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name;
    }
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$source = $read('src/Infrastructure/Source/CatalogSourceProfiledImportService.php');
$promotion = $read('src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php');
$backup = $read('src/Infrastructure/Jobs/GameBackupImportJobHandler.php');
$pak = $read('src/Infrastructure/Jobs/CatalogPakImportJobHandler.php');
$rename = $read('src/Infrastructure/Maintenance/CatalogVerifiedFileRenameService.php');
$move = $read('src/Infrastructure/Games/CatalogVerifiedFileReassignmentService.php');
$cross = $read('src/Infrastructure/Unverified/CatalogCrossGamePackageCopyService.php');
$crossQuery = $read('src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php');
$fullSync = $read('src/Infrastructure/Jobs/CatalogFullSyncUnitJobHandler.php');
$fullSyncParent = $read('src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php');

$record(
    'staged_upload_uses_canonical_importer',
    str_contains($staged, 'new PdoCatalogPackageImporter')
        && str_contains($staged, '->importUploadedFile('),
    'Profiled/direct staged uploads must enter through PdoCatalogPackageImporter.'
);
$record(
    'local_source_scan_uses_canonical_scanner_adapter',
    str_contains($source, '\\scanner_scan_uploaded_file(')
        && !str_contains($source, 'INSERT INTO ue_files'),
    'Local Source Scan must use the shared scanner/import adapter rather than writing verified rows itself.'
);
$record(
    'unverified_alias_promotion_refreshes_affected_dependencies',
    str_contains($promotion, 'catalog_package_alias_add(')
        && str_contains($promotion, 'CatalogVerifiedPackageDependencyCoordinator')
        && str_contains($promotion, '->refreshAlias(')
        && strpos($promotion, '->refreshAlias(') < strpos($promotion, '$this->queueMutations->discard($source)'),
    'Adding/reusing an alias while promoting from Unverified must secure the same affected-dependency refresh before discarding staging.'
);
$record(
    'backup_restore_has_final_game_dependency_workflow',
    str_contains($backup, "'defer_dependency_rebuild' => true")
        && str_contains($backup, "JobType::REBUILD_GAME_DEPENDENCIES")
        && str_contains($backup, "'backup_import_dependency_wait'")
        && str_contains($backup, "'dependencies'"),
    'Backup entries may defer per-file refresh only because the parent now owns one authoritative final game dependency workflow.'
);
$record(
    'pak_import_has_final_dependency_workflow',
    str_contains($pak, "'defer_dependency_rebuild' => true")
        && str_contains($pak, 'JobType::REBUILD_GAME_DEPENDENCIES')
        && str_contains($pak, "'pak_dependency_wait'"),
    'PAK imports defer entry refresh only behind their durable dependency workflow.'
);
$record(
    'verified_rename_is_rename_aware',
    str_contains($rename, "'rename_refresh' => true")
        && str_contains($rename, "'old_package_name' => $oldPackageName")
        && str_contains($rename, 'JobType::REBUILD_FILE_DEPENDENCIES'),
    'Verified rename must refresh both the corrected provider identity and dependants of the old identity.'
);
$record(
    'game_reassignment_always_uses_canonical_importer',
    str_contains($move, 'new PdoCatalogPackageImporter')
        && str_contains($move, '->importUploadedFile(')
        && !str_contains($move, 'private function targetVerifiedFile'),
    'Same-MD5 destination moves must not bypass profile verification, alias publication or dependency refresh.'
);
$record(
    'cross_game_copy_preserves_alias_repair',
    str_contains($cross, "'already_in_target' => \$targetProvidesPackageIdentity")
        && str_contains($crossQuery, 'target_existing.package_name=f.package_name OR EXISTS (')
        && str_contains($crossQuery, 'catalog_package_alias_row_exists('),
    'Same bytes in the target are only complete when the required logical package identity is also present.'
);
$record(
    'full_sync_validation_is_non_destructive',
    str_contains($fullSync, "execute('sync_reimport'")
        && !str_contains($fullSync, 'CatalogFileMaintenanceRemovalService')
        && !str_contains($fullSync, "'status' => 'removed_invalid'"),
    'Full Sync must fail a bad revalidation unit visibly instead of deleting a present verified package.'
);
$record(
    'full_sync_owns_authoritative_dependency_phase',
    str_contains($fullSyncParent, 'JobType::FULL_SYNC_DEPENDENCY_FILE')
        && str_contains($fullSyncParent, "'full_sync_prepare_providers'")
        && str_contains($fullSyncParent, "'full_sync_finalize'"),
    'Full Sync must reconcile providers, rebuild every file dependency unit, then publish final summaries/counters.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
