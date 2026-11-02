#!/usr/bin/env php
<?php
/** Read-only/no-database contract verifier for retained archive error reporting. */
declare(strict_types=1);

$root = dirname(__DIR__);
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$backfillPath = __DIR__ . '/backfill-retained-archive-errors.php';
$handler = @file_get_contents($handlerPath);
$backfill = @file_get_contents($backfillPath);
if (!is_string($handler) || !is_string($backfill)) {
    fwrite(STDERR, "[FAIL] source_files — Could not read retained archive reporting sources.\n");
    exit(1);
}

$checks = [
    'system_error_recorder_imported' => [
        str_contains($handler, 'use UnrealDb\\Catalog\\Infrastructure\\Telemetry\\CatalogSystemErrorRecorder;'),
        'Retained archive failures must use the existing durable system-error recorder.',
    ],
    'partial_archive_failure_recorded' => [
        str_contains($handler, 'recordRetainedArchiveFailure(')
            && str_contains($handler, "'error_type' => 'ArchivePartialFailure'")
            && str_contains($handler, "'disposition' => 'partial_archive'"),
        'A retained/partial archive must create a background-job system error without throwing the parent job.',
    ],
    'member_failures_preserved_in_context' => [
        str_contains($handler, "'failed_files' => \$failed")
            && str_contains($handler, "'errors' => \$errors")
            && str_contains($handler, "'result_message' => \$resultMessage"),
        'The error record must retain failed-member details and the operator-facing archive result.',
    ],
    'terminal_decoder_failure_recorded' => [
        preg_match('/terminalArchiveCapabilityResult\([\s\S]*?recordRetainedArchiveFailure\(/', $handler) === 1,
        'Terminal PHP archive-decoder capability failures must also be written to System Errors.',
    ],
    'control_character_metadata_is_skipped' => [
        str_contains($handler, 'isIgnorableUnsafeArchivePath')
            && str_contains($handler, "=== 'empty/control-character path'")
            && str_contains($handler, 'Skipped unrepresentable archive metadata path'),
        'Unrepresentable classic Mac/Finder metadata must be skipped instead of forcing partial retention.',
    ],
    'partial_job_behavior_preserved' => [
        str_contains($handler, "\$status = \$failed > 0 ? 'partial' : 'completed';")
            && str_contains($handler, "'source_retained' => true"),
        'Logging must not convert the intended partial archive job into a failed/dead-letter job.',
    ],
    'existing_partial_jobs_can_be_backfilled' => [
        str_contains($backfill, 'ArchivePartialFailure')
            && str_contains($backfill, 'catalog.process_bucket_archive')
            && str_contains($backfill, 'catalog.import_staged_archive')
            && str_contains($backfill, "\$displayStatus !== 'partial'"),
        'Already-completed retained archive jobs must be backfillable into System Errors.',
    ],
    'backfill_ignores_metadata_only_false_positives' => [
        str_contains($backfill, 'retained_archive_is_ignorable_metadata_error')
            && str_contains($backfill, 'Unsafe archive path: empty/control-character path')
            && str_contains($backfill, '[IGNORED-METADATA]'),
        'Existing metadata-only retained jobs must not create false System Errors during backfill.',
    ],
];

$failed = 0;
foreach ($checks as $name => [$ok, $detail]) {
    echo '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $name . ' — ' . $detail . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

echo PHP_EOL . 'Retained archive error reporting: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . (count($checks) - $failed) . '/' . count($checks) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
