#!/usr/bin/env php
<?php
/** Read-only contract for valid wrong-profile packages vs actionable archive failures. */
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
$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$children = $read('src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php');
$workflow = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$projector = $read('src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$fileTree = $read('src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php');
$repair = $read('src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
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
    'profile_mismatch_has_distinct_non_error_outcome',
    str_contains($outcome, "UNVERIFIED_PROFILE_MISMATCH = 'unverified_profile_mismatch'")
        && str_contains($outcome, 'str_starts_with(trim($message), \'Game/profile mismatch.\')'),
    'A valid package rejected only by the selected game/profile must have a stable non-error job outcome.'
);

$record(
    'wrong_profile_package_stays_in_unverified_storage',
    str_contains($staged, 'CatalogImportOutcome::isProfileMismatchMessage($shortError)')
        && str_contains($staged, '$staged !== null')
        && str_contains($staged, 'CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH')
        && str_contains($staged, '\'unverified\' => $staged'),
    'Profile mismatch is non-error only after the package was successfully retained/indexed in Unverified Files.'
);

$record(
    'invalid_or_unreadable_packages_remain_actionable',
    str_contains($staged, ": 'unverified'")
        && str_contains($staged, ": 'rejected'")
        && str_contains($children, "['failed', 'rejected', 'unverified', 'partial', 'error']"),
    'Generic unverified parse failures and rejected/non-package bytes must continue to count as actionable failures.'
);

$record(
    'archive_parent_counts_profile_mismatch_separately',
    str_contains($children, "'unverified' => 0")
        && str_contains($children, '$displayStatus === \'unverified_profile_mismatch\'')
        && str_contains($children, '$state[\'unverified\'] += $count')
        && str_contains($workflow, "' unverified/profile mismatch, '")
        && str_contains($workflow, '$partial = $totalFailed > 0 || $cancelled > 0;'),
    'A valid wrong-profile child must not make an otherwise healthy archive partial/retryable.'
);

$record(
    'operator_projection_does_not_call_profile_mismatch_an_error',
    str_contains($projector, '$resultStatus === \'unverified_profile_mismatch\'')
        && str_contains($projector, '$summary[\'unverified\']++')
        && str_contains($fileTree, "'unverified_profile_mismatch' => 'Stored in Unverified'")
        && str_contains($fileTree, "'unverified_profile_mismatch' => 'Unverified · profile mismatch'"),
    'Background Jobs must describe this as an unverified/profile mismatch outcome, not Could not process.'
);

$record(
    'historical_repair_is_metadata_only',
    str_contains($repair, 'JSON_SET(result_json,"$.status",?')
        && str_contains($repair, 'CatalogImportOutcome::isProfileMismatchMessage($message)')
        && str_contains($repair, 'JobFailureRetryPolicy::isInvalidPackageContentText($jobType, $message)')
        && str_contains($repair, 'archive_wait_children')
        && str_contains($repair, 'archive_member_content_wait_child')
        && !str_contains($repair, 'CatalogArchiveExtractor')
        && !str_contains($repair, 'CatalogIncomingFileStore'),
    'Existing rows must be reconciled from durable child outcomes without reopening archive/package source bytes.'
);

$record(
    'historical_repair_preserves_real_errors',
    str_contains($repair, '$nextStatus = \'\';')
        && str_contains($repair, 'if ($nextStatus === \'\')')
        && str_contains($repair, 'continue;')
        && str_contains($repair, 'display_status IN ("unverified","rejected")'),
    'Historical reclassification must leave unrelated worker/database/runtime failures untouched.'
);

$record(
    'worker_startup_runs_bounded_reconciliation',
    str_contains($factory, 'new PdoArchiveProfileMismatchOutcomeRepair($db)')
        && str_contains($factory, 'No archive or')
        && str_contains($factory, 'package source bytes are re-read here.')
        && str_contains($fingerprint, 'PdoArchiveProfileMismatchOutcomeRepair.php')
        && str_contains($fingerprint, 'CatalogStagedImportJobHandler.php')
        && str_contains($fingerprint, 'CatalogImportOutcome.php'),
    'Detached workers must pick up the new semantics and reconcile historical profile mismatches after deployment.'
);

$phpFiles = [
    $root . '/src/Infrastructure/Import/CatalogImportOutcome.php',
    $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php',
    $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    __FILE__,
];
$syntaxFailures = [];
foreach ($phpFiles as $file) {
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
