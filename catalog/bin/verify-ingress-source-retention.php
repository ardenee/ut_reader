#!/usr/bin/env php
<?php
/**
 * Regression gate: ingress failures must retain recoverable source bytes so a
 * parser/runtime fix can be retried without another browser upload.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$bucket = $read('src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$public = $read('src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php');
$cleanup = $read('src/Infrastructure/Jobs/CatalogJobStorageCleanup.php');
$repair = $read('src/Infrastructure/Jobs/CatalogUnverifiedMetadataRepairJobHandler.php');

$finalizePos = strpos($bucket, 'private function finalizeStagedCheckpoint(');
$chunkDeletePos = strpos($bucket, '(new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);');
$preparedClearPos = strpos($bucket, '$preparedStore->clear();', $finalizePos !== false ? $finalizePos : 0);

$record(
    'admin_upload_source_cleanup_happens_after_staged_checkpoint',
    $finalizePos !== false
        && $chunkDeletePos !== false
        && $preparedClearPos !== false
        && $chunkDeletePos > $finalizePos
        && $preparedClearPos > $finalizePos
        && str_contains($bucket, 'if ((string)($resume[\'stage\'] ?? \'\') === \'bucket_staged\'')
        && str_contains($bucket, 'return $this->finalizeStagedCheckpoint('),
    'Browser upload/prepared bytes must survive parser/storage exceptions and be deleted only after durable bucket staging succeeds.'
);

$record(
    'admin_upload_exception_path_does_not_cleanup_recovery_source',
    str_contains($bucket, 'progress_json must remain the recovery checkpoint')
        && !str_contains(
            substr(
                $bucket,
                (int)(strpos($bucket, '} catch (Throwable $error) {') ?: 0),
                max(0, (int)(strpos($bucket, '} finally {', (int)(strpos($bucket, '} catch (Throwable $error) {') ?: 0))
                    - (int)(strpos($bucket, '} catch (Throwable $error) {') ?: 0)))
            ),
            'CatalogChunkedUploadCleanup'
        ),
    'A failed admin package handler must leave its completed browser upload/prepared checkpoint recoverable.'
);

$publicCatch = '';
$publicCatchStart = strpos($public, '} catch (\Throwable $error) {');
if ($publicCatchStart !== false) {
    $publicCatchEnd = strpos($public, "\n    }\n\n    /**", $publicCatchStart);
    $publicCatch = substr(
        $public,
        $publicCatchStart,
        $publicCatchEnd !== false ? $publicCatchEnd - $publicCatchStart : 2200
    );
}
$record(
    'public_upload_failure_retains_quarantine_source',
    str_contains($publicCatch, 'original contribution should remain staged for diagnosis/retry')
        && str_contains($publicCatch, "'status' => 'failed'")
        && !str_contains($publicCatch, 'removeQuarantine('),
    'Public Upload failures must retain the original quarantine source for diagnosis/retry.'
);

$record(
    'job_storage_cleanup_retains_problem_owners',
    str_contains($cleanup, "private const RESTARTABLE_STATUSES = ['queued', 'running', 'failed', 'dead_letter', 'cancelled'];")
        && str_contains($cleanup, 'status="completed" AND result_json LIKE "%source_retained%"')
        && str_contains($cleanup, 'if (in_array($status, self::RESTARTABLE_STATUSES, true))'),
    'Storage cleanup must not reclaim staged/prepared files owned by retryable/problem jobs.'
);

$record(
    'retained_unverified_file_can_be_reparsed_with_current_reader',
    str_contains($repair, '$this->staging->indexPath(')
        && str_contains($repair, 'true')
        && str_contains($repair, "'stage' => 'repair_header'")
        && str_contains($repair, "'read_names'")
        && str_contains($repair, "'read_imports'")
        && str_contains($repair, "'read_exports'"),
    'Existing Unverified storage must support a fresh current-code metadata parse without retransferring the source.'
);

$syntaxTargets = [
    'bin/verify-ingress-source-retention.php',
    'src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php',
    'src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php',
    'src/Infrastructure/Jobs/CatalogJobStorageCleanup.php',
    'src/Infrastructure/Jobs/CatalogUnverifiedMetadataRepairJobHandler.php',
];
$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ': could not run php -l';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
