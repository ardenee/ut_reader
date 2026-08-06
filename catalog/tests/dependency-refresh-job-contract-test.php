<?php
declare(strict_types=1);

function dependency_refresh_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../dependency-refresh.php');
dependency_refresh_contract_expect(is_string($page), 'dependency-refresh.php could not be read.');
dependency_refresh_contract_expect(str_contains($page, 'dependency-refresh-jobs.js'), 'Dependency Refresh no longer loads its durable job client.');
dependency_refresh_contract_expect(str_contains($page, "catalog_csrf('job_action')"), 'Dependency Refresh no longer uses the protected job-action scope.');
foreach (['scanner_rebuild_dependencies(', "action === 'refresh_file'", "action === 'list_game'"] as $inlineBoundary) {
    dependency_refresh_contract_expect(!str_contains($page, $inlineBoundary), 'Dependency Refresh returned to browser-driven inline work: ' . $inlineBoundary);
}

$client = file_get_contents(__DIR__ . '/../assets/dependency-refresh-jobs.js');
dependency_refresh_contract_expect(is_string($client), 'dependency-refresh-jobs.js could not be read.');
foreach (['enqueue_rebuild_game', 'enqueue_rebuild_file', 'job_id=', "action: 'cancel'", 'catalog.rebuild_file_dependencies'] as $fragment) {
    dependency_refresh_contract_expect(str_contains($client, $fragment), 'Dependency Refresh job client is missing ' . $fragment);
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
dependency_refresh_contract_expect(is_string($types), 'JobType.php could not be read.');
dependency_refresh_contract_expect(str_contains($types, 'REBUILD_FILE_DEPENDENCIES'), 'The exact file dependency job type is missing.');
dependency_refresh_contract_expect(str_contains($types, 'REBUILD_AFFECTED_DEPENDENCIES'), 'The affected dependency job type is missing.');

$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogMaintenanceJobHandler.php');
dependency_refresh_contract_expect(is_string($handler), 'CatalogMaintenanceJobHandler.php could not be read.');
dependency_refresh_contract_expect(str_contains($handler, 'JobType::REBUILD_FILE_DEPENDENCIES'), 'The maintenance handler does not dispatch exact file jobs.');
dependency_refresh_contract_expect(str_contains($handler, 'JobType::REBUILD_AFFECTED_DEPENDENCIES'), 'The maintenance handler does not dispatch affected-file jobs.');
dependency_refresh_contract_expect(str_contains($handler, '\\scanner_rebuild_dependencies('), 'The exact file job no longer rebuilds the selected file itself.');
dependency_refresh_contract_expect(str_contains($handler, "job->payload['offset']"), 'The queued game refresh no longer preserves start offsets.');

$affectedHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');
dependency_refresh_contract_expect(is_string($affectedHandler), 'CatalogAffectedDependencyRefreshJobHandler.php could not be read.');
foreach ([
    "job->payload['resume_offset']",
    "affected_dependency_chunk_size",
    'array_slice($affectedIds, $resumeOffset, $chunkSize)',
    'new PdoJobQueue($this->db)',
    "'rebuild-affected-file:' . \$fileId . ':offset:' . \$nextOffset",
    "'continuation_job_id'",
    'gc_collect_cycles()',
] as $fragment) {
    dependency_refresh_contract_expect(
        str_contains($affectedHandler, $fragment),
        'Affected dependency refresh is not bounded/resumable: ' . $fragment
    );
}

$service = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
dependency_refresh_contract_expect(is_string($service), 'CatalogAffectedDependencyRefreshService.php could not be read.');
foreach ([
    'new PdoJobQueue($db)',
    'JobType::REBUILD_AFFECTED_DEPENDENCIES',
    "'rebuild-affected-file:' . \$fileId",
    'isActiveRefreshJob(',
    'existingRefreshJobId(',
    'hasAffectedFiles(',
    'concurrency_key=?',
    'new CatalogDetachedWorker($config)',
    'using synchronous fallback',
    'queued job remains durable',
] as $fragment) {
    dependency_refresh_contract_expect(str_contains($service, $fragment), 'Automatic affected dependency refresh is missing ' . $fragment);
}
dependency_refresh_contract_expect(
    str_contains($service, 'status="running"')
        && str_contains($service, 'status IN ("queued","running")')
        && str_contains($service, 'return [];'),
    'Normal imports do not defer to running refreshes or reuse queued continuation jobs.'
);
dependency_refresh_contract_expect(
    str_contains($service, "if (empty(\$worker['active'])")
        && str_contains($service, "(int)(\$worker['launching_count'] ?? 0) === 0")
        && str_contains($service, 'return $jobId;'),
    'Foreground imports still reconcile an active worker pool or discard a successfully queued refresh after launcher failure.'
);

$compatibility = file_get_contents(__DIR__ . '/../lib/CatalogCompactMetadataCompatibility.php');
dependency_refresh_contract_expect(is_string($compatibility), 'CatalogCompactMetadataCompatibility.php could not be read.');
dependency_refresh_contract_expect(
    str_contains($compatibility, 'unset($cache[$connectionId])')
        && str_contains($compatibility, "'file_id' => \$fileId, 'snapshot' => \$result")
        && !str_contains($compatibility, "spl_object_id(\$db) . ':' . \$fileId"),
    'Compact metadata compatibility snapshots are still retained without a worker-safe bound.'
);

$action = file_get_contents(__DIR__ . '/../api/v1/job-action.php');
dependency_refresh_contract_expect(is_string($action), 'job-action.php could not be read.');
dependency_refresh_contract_expect(str_contains($action, 'JobType::REBUILD_FILE_DEPENDENCIES'), 'enqueue_rebuild_file does not use the exact file job type.');
dependency_refresh_contract_expect(str_contains($action, 'enqueue_rebuild_affected'), 'The affected-dependants job is no longer explicitly available.');
dependency_refresh_contract_expect(str_contains($action, "'offset' => \$offset"), 'Game refresh offsets are not persisted in the job payload.');

$status = file_get_contents(__DIR__ . '/../api/v1/job-status.php');
dependency_refresh_contract_expect(is_string($status), 'job-status.php could not be read.');
dependency_refresh_contract_expect(str_contains($status, "\$_GET['job_id']"), 'Job status cannot poll one durable job by ID.');
dependency_refresh_contract_expect(str_contains($status, 'result_json'), 'Job status no longer exposes completed result data.');

echo "Dependency refresh durable-job contract tests passed.\n";
