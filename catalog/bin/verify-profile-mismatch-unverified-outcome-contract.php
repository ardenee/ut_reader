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
$profileException = $read('src/Infrastructure/Import/CatalogProfileMismatchException.php');
$verifiedInspector = $read('src/Infrastructure/Import/CatalogVerifiedPackageInspector.php');
$promotion = $read('src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php');
$bulk = $read('src/Infrastructure/Jobs/CatalogUnverifiedBulkActionJobHandler.php');
$singleAction = $read('unverified-files-action.php');
$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$children = $read('src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php');
$workflow = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$projector = $read('src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$fileTree = $read('src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php');
$repair = $read('src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$invalidBackfill = $read('src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'profile_mismatch_is_typed_before_job_handling',
    str_contains($profileException, 'final class CatalogProfileMismatchException extends RuntimeException')
        && str_contains($verifiedInspector, 'throw new CatalogProfileMismatchException(')
        && str_contains($staged, '$error instanceof CatalogProfileMismatchException'),
    'Known valid package/profile mismatches must use a typed non-error path rather than CatalogInvalidPackageException.'
);

$record(
    'invalid_header_is_not_mislabeled_profile_mismatch',
    str_contains($verifiedInspector, "if (empty(\$classification['header_ok']))")
        && str_contains($verifiedInspector, "'unreal.magic_not_found' => 'Magic not found'")
        && str_contains($verifiedInspector, 'throw new CatalogInvalidPackageException($headerReason, $headerCode, $headerArguments)')
        && strpos($verifiedInspector, "if (empty(\$classification['header_ok']))")
            < strpos($verifiedInspector, "if (\$strictProfile && empty(\$classification['ok_for_selected_game']))"),
    'Missing/corrupt package headers must be classified before profile comparison.'
);

$record(
    'profile_mismatch_has_distinct_non_error_outcome',
    str_contains($outcome, "UNVERIFIED_PROFILE_MISMATCH = 'unverified_profile_mismatch'")
        && str_contains($outcome, 'str_starts_with(trim($message), \'Game/profile mismatch.\')'),
    'A valid package rejected only by the selected game/profile must have a stable non-error job outcome.'
);

$record(
    'unverified_override_is_the_only_profile_bypass',
    str_contains($promotion, 'if (!$allowProfileOverride && empty($classification[\'ok_for_selected_game\']))')
        && str_contains($promotion, 'throw new CatalogProfileMismatchException(')
        && str_contains($bulk, "'allow_profile_override' => !empty(\$payload['allow_profile_override'])"),
    'A valid wrong-profile file stays Unverified unless the existing profile override is explicitly enabled.'
);

$record(
    'profile_mismatch_is_non_error_for_single_and_bulk_actions',
    str_contains($bulk, 'catch (CatalogProfileMismatchException)')
        && str_contains($bulk, '$skipped++')
        && str_contains($singleAction, 'catch (CatalogProfileMismatchException $error)')
        && str_contains($singleAction, "'status' => 'unverified_profile_mismatch'")
        && str_contains($singleAction, "'ok' => true"),
    'Manual and bulk Unverified imports must treat profile mismatch as skipped/non-error, not a failed action.'
);

$record(
    'wrong_profile_package_stays_in_unverified_storage',
    str_contains($staged, '$error instanceof CatalogProfileMismatchException')
        && str_contains($staged, 'CatalogImportOutcome::isProfileMismatchMessage($shortError)')
        && str_contains($staged, '$staged !== null')
        && str_contains($staged, 'CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH')
        && str_contains($staged, '\'unverified\' => $staged'),
    'Profile mismatch is non-error only after the package was successfully retained/indexed in Unverified Files.'
);

$record(
    'invalid_or_unreadable_packages_remain_actionable',
    str_contains($staged, 'CatalogImportOutcome::INVALID_UE_PACKAGE')
        && str_contains($staged, 'CatalogInvalidUeFileReporter::record([')
        && str_contains($children, "['invalid_ue_package', 'invalid_files', 'rejected']")
        && str_contains($children, "['failed', 'unverified', 'partial', 'error']"),
    'Invalid UE content must remain actionable through System Errors while staying separate from archive extraction failures and retries.'
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
    'historical_system_errors_drop_profile_mismatch_only_rows',
    str_contains($invalidBackfill, "\$profileMismatch = str_contains(\$lowerMessage, 'game/profile mismatch.')")
        && str_contains($invalidBackfill, 'if ($profileMismatch && !$hasHeaderFailure)')
        && str_contains($invalidBackfill, '$delete->execute([$id])')
        && str_contains($invalidBackfill, "str_contains(\$lowerMessage, 'magic not found')"),
    'Historical valid profile mismatches must be removed from System Errors while real header failures remain.'
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
        && str_contains($fingerprint, 'CatalogImportOutcome.php')
        && str_contains($fingerprint, 'CatalogProfileMismatchException.php')
        && str_contains($fingerprint, '/lib/GameProfiles.php')
        && str_contains($fingerprint, 'CatalogUnverifiedPromotion.php')
        && str_contains($fingerprint, 'CatalogUnverifiedBulkActionJobHandler.php'),
    'Detached workers must pick up the new semantics and reconcile historical profile mismatches after deployment.'
);

$phpFiles = [
    $root . '/src/Infrastructure/Import/CatalogImportOutcome.php',
    $root . '/src/Infrastructure/Import/CatalogProfileMismatchException.php',
    $root . '/src/Infrastructure/Import/CatalogVerifiedPackageInspector.php',
    $root . '/src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php',
    $root . '/src/Infrastructure/Jobs/CatalogUnverifiedBulkActionJobHandler.php',
    $root . '/unverified-files-action.php',
    $root . '/lib/GameProfiles.php',
    $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php',
    $root . '/src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php',
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
