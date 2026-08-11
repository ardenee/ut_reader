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
    'planner_fans_out_explicit_file_batches' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => 'array_chunk($remainingIds, $batchSize)',
        'present' => true,
    ],
    'child_payload_carries_explicit_affected_ids' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => "'affected_file_ids' => array_values(array_map('intval', \$chunk))",
        'present' => true,
    ],
    'affected_batches_use_targeted_package_rebuild' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => 'rebuildForPackages($affectedFileId, [$packageName], false)',
        'present' => true,
    ],
    'affected_batches_bulk_refresh_summaries' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => 'rebuildFiles($processedIds)',
        'present' => true,
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
    'affected_batch_default_parallelism_is_two' => [
        'path' => $root . '/src/Domain/Jobs/JobResourcePolicy.php',
        'needle' => 'self::configuredLimit(self::AFFECTED_DEPENDENCY_BATCH, 2)',
        'present' => true,
    ],
    'affected_chain_tracks_batch_children' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshCoordinator.php',
        'needle' => "return [\$dedupeKey, \$dedupeKey . ':%'];",
        'present' => true,
    ],
    'game_stats_support_bounded_lock_wait' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php',
        'needle' => 'public function rebuildGame(int $gameId, int $lockWaitSeconds = 0): ?array',
        'present' => true,
    ],
    'affected_batch_waits_for_game_stats_lock' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
        'needle' => '$stats->rebuildGame($gameId, 5)',
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
