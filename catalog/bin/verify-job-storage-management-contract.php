#!/usr/bin/env php
<?php
/**
 * Source-level verifier for Background Jobs web-managed storage cleanup.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$paths = [
    'cleanup' => $root . '/src/Infrastructure/Jobs/CatalogJobStorageCleanup.php',
    'maintenance' => $root . '/src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php',
    'action' => $root . '/api/v1/job-action.php',
    'page' => $root . '/background-jobs.php',
    'js' => $root . '/assets/background-jobs-files.js',
    'identity' => $root . '/src/Infrastructure/Import/CatalogBucketIdentityProcessor.php',
    'batch_store' => $root . '/src/Infrastructure/Import/CatalogProfiledUploadBatchStore.php',
    'batch_handler' => $root . '/src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
    'chunk_cleanup' => $root . '/src/Infrastructure/Import/CatalogChunkedUploadCleanup.php',
    'fingerprint' => $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
];

$source = [];
foreach ($paths as $key => $path) {
    $value = @file_get_contents($path);
    if (!is_string($value)) {
        fwrite(STDERR, "[FAIL] Could not read {$path}\n");
        exit(1);
    }
    $source[$key] = $value;
}

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'cleanup_uses_runtime_storage_root',
    str_contains($source['cleanup'], '$this->jobsRoot = $storageRoot . DIRECTORY_SEPARATOR . \'jobs\'')
        && str_contains($source['cleanup'], 'public function root(): string')
        && str_contains($source['page'], '$jobStorageRoot = rtrim((string)($config[\'storage_path\'] ?? \'\')'),
    'Website cleanup must target the deployed storage_path/jobs tree, not a source-checkout-relative directory.'
);

foreach ([
    'incoming',
    'prepared',
    'pak_import',
    'chunked_uploads',
    'profiled_upload_batches',
    'events',
    'bucket_working',
    'bucket_pak_publish',
    'identity_locks',
] as $category) {
    $check(
        'cleanup_category_' . $category,
        str_contains($source['cleanup'], "'" . $category . "' =>"),
        'Job storage cleanup must report and reclaim the ' . $category . ' category.'
    );
}

$check(
    'prepared_cleanup_is_set_based',
    !str_contains($source['cleanup'], "SELECT status,result_json FROM ue_background_jobs WHERE id=?")
        && str_contains($source['cleanup'], "'owner_jobs' => []")
        && str_contains($source['cleanup'], 'pruneOwnedDirectories('),
    'Hundreds of thousands of prepared directories must not trigger one database ownership query per directory.'
);

$check(
    'completed_chunked_orphans_are_reclaimed',
    str_contains($source['cleanup'], 'pruneChunkedUploads(')
        && str_contains($source['cleanup'], 'new CatalogChunkedUploadCleanup($this->config)')
        && str_contains($source['chunk_cleanup'], 'Completed/orphaned stores are reclaimed by CatalogJobStorageCleanup'),
    'The general storage cleanup must reclaim completed chunk stores once no live/retryable job owns them.'
);

$check(
    'active_browser_uploads_are_protected',
    str_contains($source['cleanup'], 'collectActiveProfiledBatchReferences(')
        && str_contains($source['cleanup'], '$status === \'uploading\'')
        && str_contains($source['cleanup'], '$this->isLocked($lockPath)')
        && str_contains($source['cleanup'], '$manualCleanup ? $minimumAgeSeconds : $this->uploadStaleSeconds()')
        && str_contains($source['cleanup'], "preg_match('/^chunk-upload:"),
    'Cleanup must preserve active/recent browser uploads; the explicit website cleanup may reclaim old unlocked abandoned uploads.'
);

$check(
    'identity_lock_files_are_ephemeral',
    str_contains($source['identity'], 'stream_get_meta_data($handle)')
        && str_contains($source['identity'], '@unlink($path)')
        && str_contains($source['cleanup'], 'pruneIdentityLocks('),
    'Upload identity locks must be removed after use and old unlocked lock files must be reclaimable.'
);

$check(
    'profiled_batch_manifests_are_ephemeral',
    str_contains($source['batch_store'], 'public function delete(string $batchId): void')
        && str_contains($source['batch_handler'], '$store->delete($batchId);')
        && str_contains($source['batch_handler'], "(string)(\$resume['stage'] ?? '') === 'complete'"),
    'Completed profiled-upload manifests must be deleted behind a durable completion checkpoint.'
);

$check(
    'web_management_control_exists',
    str_contains($source['page'], 'id="jobs-storage-cleanup"')
        && str_contains($source['page'], 'Clean job storage')
        && str_contains($source['page'], 'Job storage:')
        && str_contains($source['js'], "action: 'cleanup_storage'")
        && str_contains($source['action'], "if (\$action === 'cleanup_storage')")
        && str_contains($source['action'], "'prune_unit' => 'job_storage'")
        && str_contains($source['action'], "'manual_cleanup' => true")
        && str_contains($source['action'], "'storage_only' => true"),
    'Background Jobs -> Maintenance must expose a background job-storage cleanup action.'
);

$check(
    'storage_cleanup_runs_in_worker',
    str_contains($source['maintenance'], "\$storageOnly ? ['job_storage'] : ['generated', 'job_storage']")
        && str_contains($source['maintenance'], 'new CatalogJobStorageCleanup($this->db, $this->config)')
        && str_contains($source['maintenance'], '$context->heartbeatIfDue($progress)')
        && str_contains($source['action'], 'CatalogQueueWorkerStarter'),
    'Large filesystem cleanup must run as one worker job and stream progress through job heartbeats.'
);

$check(
    'storage_cleanup_reports_real_progress',
    str_contains($source['cleanup'], 'emitLoopProgress(')
        && str_contains($source['cleanup'], "'category' => \$category")
        && str_contains($source['cleanup'], "'reclaimed_bytes' => \$bytes")
        && str_contains($source['cleanup'], 'countFiles($this->identityLockDirectory)')
        && str_contains($source['cleanup'], 'number_format($done)'),
    'Job storage cleanup must report category/file counts and reclaimed bytes instead of remaining at 1% until completion.'
);

foreach ([
    'CatalogJobStorageCleanup.php',
    'CatalogStorageMaintenanceJobHandler.php',
    'CatalogProfiledUploadBatchJobHandler.php',
    'CatalogProfiledUploadBatchStore.php',
    'CatalogBucketIdentityProcessor.php',
    'CatalogChunkedUploadCleanup.php',
] as $filename) {
    $check(
        'worker_fingerprint_' . $filename,
        str_contains($source['fingerprint'], $filename),
        'Worker code version must include ' . $filename . ' so old workers reload.'
    );
}

$syntaxFailures = [];
foreach ($paths as $key => $path) {
    if (!str_ends_with($path, '.php')) {
        continue;
    }
    $pipes = [];
    $process = @proc_open(
        [PHP_BINARY, '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        $syntaxFailures[] = $key . ': could not start php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $key . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$check('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
