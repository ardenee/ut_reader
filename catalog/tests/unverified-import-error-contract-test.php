<?php
declare(strict_types=1);

function unverified_import_error_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$action = file_get_contents(__DIR__ . '/../unverified-files-action.php');
$queue = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogPostImportDependencyQueue.php');
$searchQueue = file_get_contents(__DIR__ . '/../src/Application/Search/CatalogSearchIndexQueue.php');
$client = file_get_contents(__DIR__ . '/../assets/unverified-file-actions.js');
$timeoutRecovery = file_get_contents(__DIR__ . '/../assets/unverified-import-timeout-recovery.js');
$supportCore = file_get_contents(__DIR__ . '/../lib/CatalogSupportCore.php');
unverified_import_error_expect(is_string($action), 'Unverified action endpoint could not be read.');
unverified_import_error_expect(is_string($queue), 'Post-import dependency queue could not be read.');
unverified_import_error_expect(is_string($searchQueue), 'Search projection queue could not be read.');
unverified_import_error_expect(is_string($client), 'Unverified action client could not be read.');
unverified_import_error_expect(is_string($timeoutRecovery), 'Unverified timeout recovery client could not be read.');
unverified_import_error_expect(is_string($supportCore), 'Catalog support core could not be read.');

unverified_import_error_expect(
    str_contains($action, 'JSON_INVALID_UTF8_SUBSTITUTE')
        && str_contains($action, "'request_id' => \$requestId"),
    'Unverified action errors are not guaranteed to return valid JSON with a request reference.'
);
unverified_import_error_expect(
    str_contains($action, 'register_shutdown_function')
        && str_contains($action, 'The server stopped unexpectedly while processing this file.'),
    'Fatal unverified import failures are not converted into a useful JSON response.'
);
unverified_import_error_expect(
    str_contains($action, 'DELETE d FROM ue_dependencies d INNER JOIN ue_imports i ON i.id=d.import_id WHERE i.file_id=?')
        && str_contains($action, 'DELETE FROM ue_dependencies WHERE import_id=?')
        && str_contains($action, 'uq_ue_deps_import'),
    'Stale dependency/import collision recovery is unavailable.'
);
unverified_import_error_expect(
    str_contains($action, 'unverified_action_queue_dependency_refresh(')
        && str_contains($action, 'CatalogPostImportDependencyQueue::enqueue(')
        && str_contains($action, "'dependency_jobs'")
        && str_contains($action, 'Import complete; background scans queued')
        && !str_contains($action, 'scanner_rebuild_dependencies($db, $config, (int)$row[\'id\']')
        && !str_contains($action, 'scanner_rebuild_affected_dependencies($db, $config, (int)$row[\'id\']'),
    'Unverified import still performs dependency-heavy work in the foreground request.'
);
unverified_import_error_expect(
    str_contains($queue, "defer_worker_start")
        && str_contains($queue, 'CatalogSearchIndexQueue::enqueueFile($db, $fileId, $searchConfig, $createdBy)')
        && str_contains($queue, 'JobType::REBUILD_FILE_DEPENDENCIES')
        && str_contains($queue, 'JobType::REBUILD_AFFECTED_DEPENDENCIES')
        && str_contains($queue, "'search_job_id'")
        && str_contains($queue, "'rebuild-file-dependencies:' . \$fileId")
        && str_contains($queue, "'rebuild-affected-file:' . \$fileId")
        && str_contains($queue, "active_count")
        && str_contains($queue, "launching_count")
        && str_contains($queue, 'The jobs are already durable.'),
    'Post-import projection/dependency work is not durably queued with one bounded worker-pool check.'
);
unverified_import_error_expect(
    str_contains($searchQueue, "defer_worker_start")
        && str_contains($searchQueue, "active_count")
        && str_contains($searchQueue, "launching_count")
        && str_contains($searchQueue, 'queued job remains durable')
        && !str_contains($searchQueue, '(new CatalogDetachedWorker($config))->start('),
    'Search-index enqueue still reconciles an already active detached worker pool.'
);

$sessionClose = strpos($action, 'session_write_close();', strpos($action, "catalog_check_csrf('unverified-files')"));
$configLoad = strpos($action, '$config = catalog_config();', strpos($action, "catalog_check_csrf('unverified-files')"));
unverified_import_error_expect(
    $sessionClose !== false && $configLoad !== false && $sessionClose < $configLoad,
    'The import request still holds the administrator session during database or filesystem work.'
);
unverified_import_error_expect(
    str_contains($action, 'SET SESSION innodb_lock_wait_timeout=5')
        && str_contains($action, 'SET SESSION lock_wait_timeout=5')
        && str_contains($action, 'TRANSACTION ISOLATION LEVEL READ COMMITTED'),
    'Interactive unverified actions can still wait indefinitely or use an unnecessarily lock-retaining isolation level.'
);
unverified_import_error_expect(
    !str_contains($action, "catalog_unverified_schema_ensure(\$db);")
        && !str_contains($action, "\$staged = catalog_unverified_find(")
        && !str_contains($action, "'dependency_cleanup'")
        && str_contains($action, "'row' => is_array(\$row) ? \$row : null")
        && str_contains($action, "\$row = is_array(\$source['row'] ?? null) ? \$source['row'] : null"),
    'The import hot path still repeats schema inspection, staged-row lookup or dependency cleanup.'
);
unverified_import_error_expect(
    str_contains($action, 'function unverified_action_package_identity(')
        && str_contains($action, '$storedSize === $size && $validMd5 && $validSha1')
        && str_contains($action, "'reused' => true")
        && str_contains($action, "'identity_reused'")
        && str_contains($action, "'elapsed_ms'"),
    'The import hot path does not reuse durable staged hashes or expose useful elapsed-time diagnostics.'
);
unverified_import_error_expect(
    str_contains($action, 'FROM ue_files WHERE game_id=? AND md5=? AND scan_status="verified" LIMIT 1')
        && !str_contains($action, 'scan_status="verified" AND package_guid=? AND md5=?'),
    'Duplicate detection is not using the existing game/MD5 identity key directly.'
);
unverified_import_error_expect(
    str_contains($action, "require_once __DIR__ . '/lib/UploadProgress.php';")
        && str_contains($action, "\$_GET['progress']")
        && str_contains($action, "\$_POST['progress_token']")
        && str_contains($action, 'upload_progress_write'),
    'Unverified import does not expose live per-file progress.'
);
unverified_import_error_expect(
    str_contains($client, 'Overall 0%')
        && str_contains($client, 'File 0%')
        && str_contains($client, 'data-status')
        && str_contains($client, 'pollProgress')
        && str_contains($client, 'progress_token')
        && str_contains($client, 'updateProgressDisplay'),
    'The progress overlay does not show overall progress, file progress and current status together.'
);
unverified_import_error_expect(
    str_contains($client, 'compactServerText')
        && str_contains($client, 'the server returned a non-JSON progress response')
        && str_contains($client, 'payload.request_id'),
    'The progress client still hides the HTTP response and request reference behind a generic parse error.'
);
unverified_import_error_expect(
    str_contains($timeoutRecovery, 'window.fetch = async function')
        && str_contains($timeoutRecovery, "body.get('progress_token')")
        && str_contains($timeoutRecovery, '[502, 503, 504]')
        && str_contains($timeoutRecovery, 'recoverFromProgress')
        && str_contains($timeoutRecovery, 'while (true)')
        && str_contains($timeoutRecovery, "stage === 'done'")
        && str_contains($timeoutRecovery, "stage === 'failed'")
        && str_contains($timeoutRecovery, 'recovered_after_timeout'),
    'A proxy timeout still ends an unverified import instead of following its progress token to a terminal result.'
);
$recoveryPosition = strpos($supportCore, 'assets/unverified-import-timeout-recovery.js');
$actionPosition = strpos($supportCore, 'assets/unverified-file-actions.js');
unverified_import_error_expect(
    $recoveryPosition !== false
        && $actionPosition !== false
        && $recoveryPosition < $actionPosition,
    'The timeout recovery client is not loaded before the unverified action client.'
);

echo "Unverified import error contract tests passed.\n";
