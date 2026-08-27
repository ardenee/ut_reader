#!/usr/bin/env php
<?php
/** Read-only contract: invalid extracted UE files are not archive extraction failures. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$outcome = $read('src/Infrastructure/Import/CatalogImportOutcome.php');
$policy = $read('src/Application/Jobs/JobFailureRetryPolicy.php');
$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$bucketMember = $read('src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php');
$children = $read('src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php');
$workflow = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$projector = $read('src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$fileTreeQuery = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');
$fileTreeProjector = $read('src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$repair = $read('src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'invalid_ue_outcomes_are_distinct',
    str_contains($outcome, "INVALID_UE_PACKAGE = 'invalid_ue_package'")
        && str_contains($outcome, "ARCHIVE_INVALID_FILES = 'invalid_files'"),
    'Invalid package bytes and an archive containing invalid package bytes need distinct durable statuses.'
);

$record(
    'package_structure_failure_is_content_failure',
    str_contains($policy, 'isInvalidPackageContentText')
        && str_contains($policy, "'invalid exports table offset:'")
        && str_contains($policy, "'invalid imports table offset:'")
        && str_contains($policy, "'invalid compact package index length'"),
    'UE table/index contradictions such as the truncated M1.utx case must be classified as invalid package content.'
);

$record(
    'profiled_import_marks_invalid_package_not_archive_failure',
    str_contains($staged, '$error instanceof CatalogInvalidPackageException')
        && str_contains($staged, 'CatalogImportOutcome::INVALID_UE_PACKAGE')
        && str_contains($staged, '\'outcome_class\' => $outcomeClass'),
    'A package parser validation exception after successful extraction must produce invalid_ue_package.'
);

$record(
    'bucket_archive_member_marks_invalid_package',
    str_contains($bucketMember, 'isReaderValidationFailure($error)')
        && str_contains($bucketMember, 'CatalogImportOutcome::INVALID_UE_PACKAGE')
        && str_contains($bucketMember, 'archive extraction completed successfully')
        && str_contains($bucketMember, "'source_retained' => true"),
    'The bucket/archive-member path must preserve invalid UE bytes for diagnostics without making extraction retryable.'
);

$record(
    'archive_child_rollup_separates_invalid_ue',
    str_contains($children, "'invalid_ue' => 0")
        && str_contains($children, "['invalid_ue_package', 'rejected']")
        && str_contains($children, '$state[\'invalid_ue\'] += $count')
        && str_contains($children, "['failed', 'unverified', 'partial', 'error']"),
    'Invalid UE children must not increment the archive failed counter.'
);

$record(
    'archive_parent_invalid_files_is_not_partial',
    str_contains($workflow, '$invalidUe = max(0, (int)($children[\'invalid_ue\'] ?? 0));')
        && str_contains($workflow, '$partial = $totalFailed > 0 || $cancelled > 0;')
        && str_contains($workflow, 'CatalogImportOutcome::ARCHIVE_INVALID_FILES')
        && str_contains($workflow, '\'invalid_ue_files\' => $invalidUe'),
    'An otherwise healthy archive with invalid UE members must finish invalid_files rather than partial.'
);

$record(
    'archive_read_model_labels_invalid_members_separately',
    str_contains($projector, '$resultStatus === \'invalid_ue_package\'')
        && str_contains($projector, '$summary[\'invalid_ue\']++')
        && str_contains($projector, 'Invalid UE file(s): ')
        && str_contains($projector, "['invalid_ue_package']")
        && str_contains($projector, 'CatalogImportOutcome::ARCHIVE_INVALID_FILES'),
    'Operator reporting must say invalid UE file instead of Failed archive member for package-content failures.'
);

$record(
    'file_tree_keeps_invalid_ue_visible_as_issue',
    str_contains($fileTreeQuery, '"invalid_ue_package","invalid_files"')
        && str_contains($fileTreeProjector, "'invalid_ue_package' => 'Invalid UE file'")
        && str_contains($fileTreeProjector, "'invalid_files' => 'Contains invalid UE file'")
        && str_contains($fileTreeProjector, "return 'Invalid UE file';")
        && str_contains($fileTreeProjector, "return 'Contains invalid UE file';"),
    'Invalid UE files must stay visible in Issues without labelling the archive extraction as failed.'
);

$record(
    'archive_retry_scope_remains_extraction_partial_only',
    str_contains($bulk, 'j.display_status="partial"')
        && str_contains($bulk, "status === 'partial_archive'")
        && !str_contains($bulk, 'j.display_status="invalid_files"'),
    'Retry retryable archives must never select an archive whose only problem is invalid UE member content.'
);

$record(
    'historical_invalid_package_repair_is_ledger_only',
    str_contains($repair, 'JobFailureRetryPolicy::isInvalidPackageContentText($jobType, $message)')
        && str_contains($repair, 'CatalogImportOutcome::INVALID_UE_PACKAGE')
        && str_contains($repair, '$afterId')
        && str_contains($repair, 'archive_wait_children')
        && !str_contains($repair, 'CatalogArchiveExtractor')
        && !str_contains($repair, 'CatalogIncomingFileStore'),
    'Historical invalid-package children must be reclassified from stored outcomes without re-extracting archives.'
);

$record(
    'worker_fingerprint_tracks_changed_runtime',
    str_contains($fingerprint, '/src/Application/Jobs/JobFailureRetryPolicy.php')
        && str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php')
        && str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php')
        && str_contains($fingerprint, '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php')
        && str_contains($fingerprint, '/src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php')
        && str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php'),
    'Detached workers must restart when invalid-UE/archive-separation runtime changes.'
);

$syntaxFailures = [];
foreach ([
    $root . '/src/Infrastructure/Import/CatalogImportOutcome.php',
    $root . '/src/Application/Jobs/JobFailureRetryPolicy.php',
    $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    $root . '/src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
    $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
