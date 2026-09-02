#!/usr/bin/env php
<?php
/**
 * Regression gate for retained-source current-code revalidation across all
 * upload ingress paths.
 *
 * The contract is intentionally asymmetric:
 * - automatic retry stops for immutable-source failures;
 * - explicit operator retry/revalidation may replay retained bytes after code changes;
 * - successful revalidation resolves the original invalid-UE operator issue/error.
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
    $value = @file_get_contents(
        $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)
    );
    return is_string($value) ? $value : '';
};

$staged = $read('src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php');
$public = $read('src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php');
$retryPolicy = $read('src/Application/Jobs/JobFailureRetryPolicy.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$action = $read('api/v1/job-action.php');
$repairService = $read('src/Infrastructure/Unverified/CatalogUnverifiedMetadataRepairService.php');
$repairHandler = $read('src/Infrastructure/Jobs/CatalogUnverifiedMetadataRepairJobHandler.php');
$projector = $read('src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php');
$client = $read('assets/background-jobs-files.js');
$statusPolicy = $read('src/Infrastructure/Jobs/CatalogJobDisplayStatus.php');
$errorRecorder = $read('src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php');
$archiveRerun = $read('src/Infrastructure/Persistence/PdoCompletedArchiveRerunSelection.php');

$record(
    'invalid_archive_member_retains_prepared_source',
    str_contains($staged, 'durable member source retained for explicit current-code revalidation')
        && str_contains($staged, "'source_retained' => true")
        && !str_contains(
            substr(
                $staged,
                (int)(strpos($staged, 'if ($this->isDeterministicNonPackage($error)) {') ?: 0),
                max(
                    0,
                    (int)(
                        (strpos($staged, 'if ($this->isReaderValidationFailure($error)) {') ?: strlen($staged))
                        - (strpos($staged, 'if ($this->isDeterministicNonPackage($error)) {') ?: 0)
                    )
                )
            ),
            '$preparedStore->clear()'
        ),
    'Magic/header classification failures must keep durable prepared bytes because later reader/tag support can make them valid.'
);

$record(
    'completed_archive_rerun_replays_descendants',
    str_contains($archiveRerun, '$descendantIds = $this->archiveDescendantIds($queueName, $roots);')
        && str_contains($archiveRerun, '$descendantsRequeued = $this->resetJobs($queueName, $descendantIds, $now);')
        && str_contains($archiveRerun, 'status IN ("completed","failed","dead_letter","cancelled")'),
    'Explicit archive rerun must reactivate retained child jobs after parser/classifier changes.'
);

$record(
    'public_failed_ledger_can_reenter_validation',
    str_contains($public, "['uploaded', 'processing', 'failed']")
        && str_contains($public, 'in_array($status, [\'processing\', \'failed\'], true)')
        && str_contains($public, 'recoverPublishedStage($publicUploadId, $row)')
        && str_contains($public, 'original contribution should remain staged for diagnosis/retry')
        && !str_contains(
            substr(
                $public,
                (int)(strpos($public, '} catch (\\Throwable $error) {') ?: 0),
                1800
            ),
            'removeQuarantine('
        ),
    'A failed Public Upload must be replayable from retained quarantine bytes instead of becoming permanently unusable.'
);

$record(
    'public_immutable_failures_stop_automatic_retry',
    str_contains($retryPolicy, '$jobType === JobType::PROCESS_PUBLIC_UPLOAD')
        && str_contains($retryPolicy, 'self::isDeterministicPackageMessage($message)')
        && str_contains($retryPolicy, "public upload md5 mismatch:")
        && str_contains($retryPolicy, "public upload sha-1 mismatch:"),
    'Immutable public content/integrity failures should terminate automatic retry instead of repeating the same work.'
);

$record(
    'explicit_public_retry_overrides_automatic_deterministic_block',
    str_contains($bulk, '$jobType !== JobType::PROCESS_PUBLIC_UPLOAD && !$sourceRetained')
        && str_contains($bulk, 'Public Upload keeps quarantine bytes'),
    'An administrator must still be able to explicitly retry retained Public Upload bytes after code changes.'
);

$record(
    'completed_invalid_jobs_are_explicitly_restartable_when_source_retained',
    str_contains($bulk, 'j.display_status IN ("failed","rejected","unverified","invalid_ue_package")')
        && str_contains($bulk, '$sourceRetained = is_array($result) && !empty($result[\'source_retained\']);'),
    'Completed invalid UE jobs must be rerunnable only when their durable result proves source retention.'
);

$record(
    'revalidate_action_supports_unverified_and_preunverified_sources',
    str_contains($action, 'if ($action === \'revalidate\')')
        && str_contains($action, 'queueFileRevalidation($fileId, $userId, $jobId)')
        && str_contains($action, "'mode' => 'unverified_file'")
        && str_contains($action, '$sourceRetained = !empty($result[\'source_retained\']);')
        && str_contains($action, "'mode' => 'retained_job'")
        && str_contains($action, "->execute(\n            'restart',\n            'selected'"),
    'Revalidate must use the retained Unverified file when available and exact retained-job replay otherwise.'
);

$record(
    'metadata_revalidation_is_current_code_and_links_original_job',
    str_contains($repairService, 'public function queueFileRevalidation(')
        && str_contains($repairService, '\'revalidation_source_job_id\' => max(0, $sourceJobId)')
        && str_contains($repairService, "hash_file(\n            'sha256'")
        && str_contains($repairService, "CatalogLegacyPackageReader.php")
        && str_contains($repairService, '\'unverified-revalidate:\' . $fileId . \':\' . $version'),
    'Unverified revalidation must use the current reader and a code-versioned dedupe key while preserving source-job provenance.'
);

$record(
    'successful_revalidation_closes_original_issue_only_after_parse_success',
    str_contains($repairHandler, '$sourceJobId > 0 && empty($result[\'parse_error\'])')
        && str_contains($repairHandler, 'resolveSuccessfulRevalidation(')
        && str_contains($repairHandler, '$result[\'status\'] = \'revalidated\';')
        && str_contains($repairHandler, 'CatalogSystemErrorRecorder::resolveInvalidUeJob('),
    'The original invalid outcome must remain open when parsing still fails and be rewritten only after a successful current-code parse.'
);

$record(
    'successful_exact_job_rerun_resolves_old_invalid_error',
    str_contains($staged, 'CatalogSystemErrorRecorder::resolveInvalidUeJob($job->id, $job->id, $md5, $sha1);'),
    'A retained staged-package job that now succeeds must resolve the stale invalid-UE error it previously created.'
);

$record(
    'system_error_resolution_matches_job_or_content_identity',
    str_contains($errorRecorder, 'public static function resolveInvalidUeJob(')
        && str_contains($errorRecorder, 'JSON_EXTRACT(context_json,"$.job_id")')
        && str_contains($errorRecorder, 'JSON_EXTRACT(context_json,"$.md5")')
        && str_contains($errorRecorder, 'JSON_EXTRACT(context_json,"$.sha1")')
        && str_contains($errorRecorder, 'status="resolved"'),
    'Content-deduplicated invalid-UE errors must resolve even if their latest context belongs to another copy of the same bytes.'
);

$record(
    'background_jobs_exposes_revalidate_only_for_retained_invalid_outcomes',
    str_contains($projector, '$row[\'can_revalidate\']')
        && str_contains($projector, '$displayStatus === \'invalid_ue_package\'')
        && str_contains($projector, 'max(0, (int)($result[\'file_id\'] ?? 0)) > 0')
        && str_contains($projector, '!empty($result[\'source_retained\'])')
        && str_contains($client, "action: 'revalidate'")
        && str_contains($client, 'Revalidate with current code')
        && str_contains($client, 'no re-upload is required'),
    'The operator control must appear only when the invalid outcome has a server-side source that can actually be reprocessed.'
);

$record(
    'invalid_ue_is_consistently_an_issue',
    str_contains($statusPolicy, "'invalid_ue_package'")
        && str_contains($statusPolicy, 'display_status IN ("failed","rejected","unverified","invalid_ue_package")')
        && str_contains($projector, "'invalid_ue_package' => 'Invalid UE file · logged in System Errors'")
        && str_contains($projector, "'revalidated' => 'Revalidated with current code'"),
    'Filters, file-tree projection and revalidated outcome labels must agree on operator status.'
);

$syntaxTargets = [
    'bin/verify-ingress-revalidation-contract.php',
    'src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    'src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php',
    'src/Application/Jobs/JobFailureRetryPolicy.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
    'api/v1/job-action.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedMetadataRepairService.php',
    'src/Infrastructure/Jobs/CatalogUnverifiedMetadataRepairJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    'src/Infrastructure/Jobs/CatalogJobDisplayStatus.php',
    'src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php',
    'src/Infrastructure/Persistence/PdoCompletedArchiveRerunSelection.php',
];

$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
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
