<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies dependency refresh job behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function dependency_refresh_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../dependency-refresh.php');
$client = file_get_contents(__DIR__ . '/../assets/dependency-refresh-jobs.js');
$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
$exactHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$affectedHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');
$service = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
$compatibility = file_get_contents(__DIR__ . '/../lib/CatalogCompactMetadataCompatibility.php');
$action = file_get_contents(__DIR__ . '/../api/v1/job-action.php');
$status = file_get_contents(__DIR__ . '/../api/v1/job-status.php');
foreach (compact('page', 'client', 'types', 'exactHandler', 'affectedHandler', 'service', 'compatibility', 'action', 'status') as $name => $source) {
    dependency_refresh_contract_expect(is_string($source) && $source !== '', $name . ' could not be read.');
}

dependency_refresh_contract_expect(str_contains($page, 'dependency-refresh-jobs.js'), 'Dependency Refresh no longer loads its durable job client.');
foreach (['scanner_rebuild_dependencies(', "action === 'refresh_file'", "action === 'list_game'"] as $inlineBoundary) {
    dependency_refresh_contract_expect(!str_contains($page, $inlineBoundary), 'Dependency Refresh returned to browser-driven inline work: ' . $inlineBoundary);
}
foreach (['enqueue_rebuild_game', 'enqueue_rebuild_file', 'job_id=', "action: 'cancel'", 'catalog.rebuild_file_dependencies'] as $fragment) {
    dependency_refresh_contract_expect(str_contains($client, $fragment), 'Dependency Refresh job client is missing ' . $fragment);
}
dependency_refresh_contract_expect(
    str_contains($types, 'REBUILD_FILE_DEPENDENCIES') && str_contains($types, 'REBUILD_AFFECTED_DEPENDENCIES'),
    'Dependency job types are missing.'
);

dependency_refresh_contract_expect(
    str_contains($exactHandler, 'scanner_rebuild_dependencies(')
        && str_contains($exactHandler, 'PdoPackageProviderRepository')
        && str_contains($exactHandler, 'PdoDependencyPackageSummary')
        && str_contains($exactHandler, 'CatalogAffectedDependencyRefreshService::enqueueIfNeeded(')
        && str_contains($exactHandler, "'affected_job_id' => \$affectedJobId")
        && str_contains($exactHandler, "'game_stats_refreshed' => \$gameStats !== null"),
    'Exact dependency jobs do not own the ordered post-import projection pipeline.'
);

foreach ([
    "job->payload['resume_offset']",
    'affected_dependency_chunk_size',
    'array_slice($affectedIds, $resumeOffset, $chunkSize)',
    'new PdoJobQueue($this->db)',
    "'rebuild-affected-file:' . \$fileId . ':offset:' . \$nextOffset",
    "'source_summary_ready' => true",
    "'continuation_job_id'",
    'gc_collect_cycles()',
    "'skip_reason' => 'source_file_missing'",
] as $fragment) {
    dependency_refresh_contract_expect(str_contains($affectedHandler, $fragment), 'Affected refresh is not bounded/resumable/stale-safe: ' . $fragment);
}

dependency_refresh_contract_expect(
    !str_contains($affectedHandler, "throw new \\RuntimeException('Verified source file no longer exists:"),
    'A deleted source file still burns retry attempts instead of completing as a stale no-op.'
);

foreach ([
    'public static function enqueueIfNeeded(',
    'new PdoJobQueue($db)',
    'JobType::REBUILD_AFFECTED_DEPENDENCIES',
    "'rebuild-affected-file:' . max(1, \$fileId)",
    'isActiveRefreshJob(',
    'existingRefreshJobId(',
    'hasAffectedFiles(',
    'dedupe_key=?',
    'new CatalogDetachedWorker($config)',
    'queued job remains durable',
] as $fragment) {
    dependency_refresh_contract_expect(str_contains($service, $fragment), 'Affected dependency service is missing ' . $fragment);
}
dependency_refresh_contract_expect(
    !str_contains($service, 'CatalogSearchIndexQueue::enqueueFile('),
    'Affected refresh still spawns a racing legacy search job.'
);

dependency_refresh_contract_expect(
    str_contains($compatibility, 'unset($cache[$connectionId])')
        && str_contains($compatibility, "'file_id' => \$fileId, 'snapshot' => \$result")
        && !str_contains($compatibility, "spl_object_id(\$db) . ':' . \$fileId"),
    'Compact metadata compatibility snapshots are still retained without a worker-safe bound.'
);
dependency_refresh_contract_expect(str_contains($action, 'JobType::REBUILD_FILE_DEPENDENCIES'), 'enqueue_rebuild_file does not use the exact file job type.');
dependency_refresh_contract_expect(str_contains($action, 'enqueue_rebuild_affected'), 'Explicit affected-dependants maintenance is unavailable.');
dependency_refresh_contract_expect(str_contains($status, "\$_GET['job_id']") && str_contains($status, 'result_json'), 'Job status cannot expose one durable result.');

echo "Dependency refresh durable-job contract tests passed.\n";
