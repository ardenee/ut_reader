<?php
/**
 * Static regression contract for exact dependency evidence, multi-game import and cross-game dependency repair.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'unverified page exposes all exact compatible target' => [
        'path' => $root . '/unverified-files.php',
        'needle' => 'All exact compatible games',
        'present' => true,
    ],
    'unverified page explains package-name-only evidence' => [
        'path' => $root . '/unverified-files.php',
        'needle' => 'Package-name-only suggestions are never auto-imported.',
        'present' => true,
    ],
    'unverified page reads cached game matches' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php',
        'needle' => '$this->gameMatchCache->read($fileIds)',
        'present' => true,
    ],
    'action endpoint preserves -1 multi-game target sentinel' => [
        'path' => $root . '/unverified-files-action.php',
        'needle' => '$targetGameId !== -1',
        'present' => true,
    ],
    'action service dispatches exact multi-game import' => [
        'path' => $root . '/src/Application/Unverified/CatalogUnverifiedActionService.php',
        'needle' => 'importExactCompatibleGames($source, $userId, $emit)',
        'present' => true,
    ],
    'multi-game importer requires exact object matches' => [
        'path' => $root . '/src/Infrastructure/Unverified/CatalogUnverifiedImportService.php',
        'needle' => "(int)(\$match['exact_object_matches'] ?? 0) < 1",
        'present' => true,
    ],
    'multi-game importer queues secondary verified imports' => [
        'path' => $root . '/src/Infrastructure/Unverified/CatalogUnverifiedImportService.php',
        'needle' => 'enqueueStaged(',
        'present' => true,
    ],
    'cross-examine starts from current missing dependency links' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'WHERE owner.game_id=? AND l.status=0',
        'present' => true,
    ],
    'cross-examine uses current export lookup projection' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'ue_export_lookup exports',
        'present' => true,
    ],
    'cross-examine joins required and exported path hashes' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'exports.path_hash=l.required_path_hash',
        'present' => true,
    ],
    'cross-examine counts each missing dependency once' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'COUNT(DISTINCT l.file_id,l.import_index) exact_object_matches',
        'present' => true,
    ],
    'cross-examine groups missing totals by logical package name' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'GROUP BY CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci',
        'present' => true,
    ],
    'cross-examine excludes same bytes only when target already provides the logical package identity' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'target_existing.package_name=f.package_name OR EXISTS (',
        'present' => true,
    ],
    'cross-examine target identity check still requires verified target md5' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'target_existing.md5=f.md5',
        'present' => true,
    ],
    'single cross-examine revalidation accepts same bytes when an alias still needs publication' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'catalog_package_alias_row_exists(',
        'present' => true,
    ],
    'cross-examine requires current format2 source metadata' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'source_meta.format_version=2',
        'present' => true,
    ],
    'cross-examine does not use obsolete compressed metadata reader' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'CompressedFileMetadataReader',
        'present' => false,
    ],
    'cross-examine does not use package summary projection' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'ue_dependency_package_summaries',
        'present' => false,
    ],
    'cross-examine does not gate candidates through target profile compatibility' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => 'compatibleWithTarget(',
        'present' => false,
    ],
    'cross-examine exposes scan diagnostics' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'needle' => "'format2_source_files'",
        'present' => true,
    ],
    'cross-examine page exposes row batch checkboxes' => [
        'path' => $root . '/dependency-cross-examine.php',
        'needle' => 'name="source_file_ids[]"',
        'present' => true,
    ],
    'cross-examine page exposes destination game selector' => [
        'path' => $root . '/dependency-cross-examine.php',
        'needle' => 'name="destination_game_id"',
        'present' => true,
    ],
    'cross-examine page uses asynchronous batch submission' => [
        'path' => $root . '/dependency-cross-examine.php',
        'needle' => "fetch(form.action",
        'present' => true,
    ],
    'cross-examine page polls durable batch progress' => [
        'path' => $root . '/dependency-cross-examine.php',
        'needle' => 'dependency-cross-examine-job.php?job_id=',
        'present' => true,
    ],
    'cross-examine HTTP action queues one parent job' => [
        'path' => $root . '/dependency-cross-examine-action.php',
        'needle' => 'JobType::CROSS_GAME_COPY_BATCH',
        'present' => true,
    ],
    'cross-examine HTTP action does not perform package copy work' => [
        'path' => $root . '/dependency-cross-examine-action.php',
        'needle' => 'CatalogCrossGamePackageCopyService',
        'present' => false,
    ],
    'cross-game parent creates durable per-source preparation units' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogCrossGameCopyBatchJobHandler.php',
        'needle' => "'source:' . \$sourceFileId",
        'present' => true,
    ],
    'cross-game source unit revalidates before queueing destination import' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogCrossGameCopyBatchJobHandler.php',
        'needle' => 'new CatalogCrossGamePackageCopyService',
        'present' => true,
    ],
    'cross-game progress projection reports ETA' => [
        'path' => $root . '/dependency-cross-examine-job.php',
        'needle' => "'eta_seconds'",
        'present' => true,
    ],
    'cross-game progress projection reports real child completion counts' => [
        'path' => $root . '/dependency-cross-examine-job.php',
        'needle' => 'cross_game_child_progress(',
        'present' => true,
    ],
    'cross-game copy uses read-only catalog-local source' => [
        'path' => $root . '/src/Infrastructure/Unverified/CatalogCrossGamePackageCopyService.php',
        'needle' => "'local-catalog:'",
        'present' => true,
    ],
    'cross-game copy does not skip same bytes when the logical package alias is missing' => [
        'path' => $root . '/src/Infrastructure/Unverified/CatalogCrossGamePackageCopyService.php',
        'needle' => "'already_in_target' => \$targetProvidesPackageIdentity",
        'present' => true,
    ],
    'cross-game copy does not pre-stage a full duplicate' => [
        'path' => $root . '/src/Infrastructure/Unverified/CatalogCrossGamePackageCopyService.php',
        'needle' => 'stageLocalFile(',
        'present' => false,
    ],
    'catalog-local source is resolved read-only by incoming store' => [
        'path' => $root . '/src/Infrastructure/Import/CatalogIncomingFileStore.php',
        'needle' => 'resolveLocalCatalogReference(',
        'present' => true,
    ],
    'worker factory routes cross-game batch job lazily' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
        'needle' => 'JobType::CROSS_GAME_COPY_BATCH => static fn() => new CatalogCrossGameCopyBatchJobHandler(',
        'present' => true,
    ],
];

$failed = [];
foreach ($checks as $label => $check) {
    $path = (string)$check['path'];
    $needle = (string)$check['needle'];
    $expectedPresent = !empty($check['present']);
    $content = is_file($path) ? file_get_contents($path) : false;
    $actualPresent = is_string($content) && str_contains($content, $needle);
    if (!is_string($content) || $actualPresent !== $expectedPresent) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Exact multi-game contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Exact multi-game contract passed (" . count($checks) . " checks).\n";
