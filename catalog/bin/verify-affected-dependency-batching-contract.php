#!/usr/bin/env php
<?php
/**
 * Static regression contract for targeted/fanned-out affected dependency refreshes.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$checks = [
    'planner_fans_out_bounded_file_batches' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => 'array_chunk($remaining, CatalogAffectedDependencyBatchService::MAX_BATCH_SIZE)',
        'present' => true,
    ],
    'child_payload_carries_explicit_affected_ids' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => "'affected_file_ids' => \$ids",
        'present' => true,
    ],
    'affected_batches_use_targeted_package_rebuild' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyBatchService.php',
        'needle' => 'rebuildForPackages($fileId, [$packageName], false)',
        'present' => true,
    ],
    'affected_batches_track_exact_changed_files' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyBatchService.php',
        'needle' => "'changed_file_ids' => array_map('intval', array_keys(\$changedIds))",
        'present' => true,
    ],
    'affected_parent_bulk_refreshes_changed_summaries_only' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => "rebuildFiles(\$aggregate['changed_file_ids'])",
        'present' => true,
    ],
    'affected_parent_does_not_bulk_refresh_all_processed_summaries' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => "rebuildFiles(\$aggregate['processed_file_ids'])",
        'present' => false,
    ],
    'affected_parent_coalesces_game_stats' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => 'CatalogGameStatsRefreshCoordinator::request(',
        'present' => true,
    ],
    'affected_parent_no_longer_rebuilds_game_stats_inline' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => '$stats->rebuildGame($gameId, 5)',
        'present' => false,
    ],
    'affected_handler_no_longer_calls_scanner_full_rebuild' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => 'scanner_rebuild_dependencies(',
        'present' => false,
    ],
    'affected_batches_have_dedicated_resource_class' => [
        'path' => $root . '/src/Domain/Jobs/JobResourcePolicy.php',
        'needle' => "public const AFFECTED_DEPENDENCY_BATCH = 'affected-dependency-batch';",
        'present' => true,
    ],
    'affected_batch_default_parallelism_is_four' => [
        'path' => $root . '/src/Domain/Jobs/JobResourcePolicy.php',
        'needle' => 'self::defaultLimit(4)',
        'present' => true,
    ],
    'affected_chain_tracks_batch_children' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshCoordinator.php',
        'needle' => "return [\$dedupeKey, \$dedupeKey . ':%'];",
        'present' => true,
    ],
    'targeted_rebuild_uses_dependency_only_snapshot' => [
        'path' => $root . '/src/Infrastructure/Metadata/CompactDependencyRebuilder.php',
        'needle' => '$loader->loadDependencySnapshot($fileId)',
        'present' => true,
    ],
    'dependency_loader_exposes_dependency_only_path' => [
        'path' => $root . '/src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotLoader.php',
        'needle' => 'public function loadDependencySnapshot(int $fileId): array',
        'present' => true,
    ],
    'summary_projection_reads_compact_links_directly' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoDependencyPackageSummary.php',
        'needle' => 'FROM ue_dependency_links l ',
        'present' => true,
    ],
    'summary_projection_avoids_generic_read_source' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoDependencyPackageSummary.php',
        'needle' => 'PdoDependencyReadSource::sql(',
        'present' => false,
    ],
    'game_stats_use_materialized_dependency_keys' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php',
        'needle' => 'dependency_package_key=s.required_package',
        'present' => true,
    ],
    'performance_migration_adds_required_file_index' => [
        'path' => $root . '/migrations/202608190001_dependency_refresh_performance.php',
        'needle' => 'idx_ue_dependency_required_file',
        'present' => true,
    ],
    'performance_migration_adds_resolved_file_index' => [
        'path' => $root . '/migrations/202608190001_dependency_refresh_performance.php',
        'needle' => 'idx_ue_dependency_resolved_file',
        'present' => true,
    ],
    'game_stats_support_bounded_lock_wait' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php',
        'needle' => 'public function rebuildGame(int $gameId, int $lockWaitSeconds = 0): ?array',
        'present' => true,
    ],
    'job_hydrator_preserves_affected_batch_identity' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php',
        'needle' => "'batch_number',",
        'present' => true,
    ],
    'job_hydrator_labels_provider_and_batch_range' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php',
        'needle' => "' · affected positions ' . \$batchStart . '-' . \$batchEnd",
        'present' => true,
    ],
];

$failed = [];
foreach ($checks as $label => $check) {
    $content = @file_get_contents((string)$check['path']);
    $present = is_string($content) && str_contains($content, (string)$check['needle']);
    if (!is_string($content) || $present !== (bool)$check['present']) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Affected dependency batching contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo 'Affected dependency batching contract passed (' . count($checks) . " checks).\n";
