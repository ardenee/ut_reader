#!/usr/bin/env php
<?php
/** Read-only contract for explicitly re-running selected completed archive trees. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$servicePath = $root . '/src/Infrastructure/Persistence/PdoCompletedArchiveRerunSelection.php';
$apiPath = $root . '/api/v1/job-bulk.php';
$service = (string)@file_get_contents($servicePath);
$api = (string)@file_get_contents($apiPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'completed_archive_rerun_is_explicit_selection_only',
    str_contains($api, '$action === \'restart\' && $scope === \'selected\'')
        && str_contains($api, 'new PdoCompletedArchiveRerunSelection($application->db)')
        && str_contains($api, '->rerunSelected($queueName, $jobIds, $now)'),
    'Only an explicit selected Retry request should opt completed archive roots into a fresh replay.'
);

$record(
    'service_accepts_only_completed_retained_top_level_archive_roots',
    str_contains($service, 'parent_job_id IS NULL')
        && str_contains($service, 'status="completed"')
        && str_contains($service, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($service, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($service, 'display_status NOT IN ("partial","failed","rejected","unverified","error")')
        && str_contains($service, 'JSON_VALID(result_json)')
        && str_contains($service, 'JSON_EXTRACT(result_json,"$.source_retained")')
        && str_contains($service, '=true'),
    'A completed archive may be replayed only when its durable result confirms that the original archive source is still retained.'
);

$record(
    'fresh_rerun_resets_complete_archive_descendant_tree',
    str_contains($service, 'WITH RECURSIVE archive_descendants AS')
        && str_contains($service, 'workflow_unit_key LIKE "archive:%"')
        && str_contains($service, 'progress_json=NULL,progress_updated_at=NULL')
        && str_contains($service, 'result_json=NULL')
        && str_contains($service, 'status="queued"'),
    'A selected completed archive replay must clear old workflow checkpoints/results for the root and its archive descendants.'
);

$record(
    'tree_reset_is_transactional',
    str_contains($service, '$this->db->beginTransaction()')
        && str_contains($service, '$this->db->commit()')
        && str_contains($service, '$this->db->rollBack()'),
    'Workers must not observe a half-reset archive tree.'
);

$record(
    'bulk_result_reports_rerun_and_starts_workers',
    str_contains($api, "'completed_archive_source_jobs'")
        && str_contains($api, "'completed_archive_descendant_jobs'")
        && str_contains($api, "'archive_rerun_expanded'")
        && str_contains($api, '$result[\'worker_start_required\']')
        && str_contains($api, '$archiveAffected > 0'),
    'The normal Retry selected feedback/count and worker starter must include completed archive reruns.'
);

$syntaxFailures = [];
foreach ([$servicePath, $apiPath, __FILE__] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
