#!/usr/bin/env php
<?php
/** Read-only source contract for compact metadata provider isolation/repair. */
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
$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$resolver = $read('src/Infrastructure/Persistence/PdoCompactCaseInsensitiveExportResolver.php');
$reader = $read('src/Infrastructure/Metadata/Uedb5MetadataReader.php');
$container = $read('src/Infrastructure/Metadata/Uedb5MetadataContainer.php');
$health = $read('src/Infrastructure/Metadata/VerifiedCompactMetadataHealth.php');
$handler = $read('src/Infrastructure/Jobs/CatalogCompactMetadataRepairJobHandler.php');
$recorder = $read('src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php');
$jobType = $read('src/Domain/Jobs/JobType.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$policy = $read('src/Domain/Jobs/JobResourcePolicy.php');
$recoveryFixture = $read('bin/verify-uedb5-isolated-recovery.php');
$dependencyHandler = $read('src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');

$check(
    'provider_corruption_is_isolated',
    str_contains($resolver, 'catch (Throwable $error)')
        && str_contains($resolver, 'reportUnreadableProvider($db, $fileId, $error)')
        && str_contains($resolver, 'CatalogSystemErrorRecorder::record')
        && str_contains($resolver, 'self::$reportedUnreadableProviders[$fileId] = true')
        && str_contains($resolver, 'continue;'),
    'Unreadable provider metadata must not abort every consumer; it must be skipped and reported once.'
);
$check(
    'self_provider_repair_is_not_operator_error',
    str_contains($resolver, 'if ($fileId !== $preferredFileId)')
        && str_contains($resolver, 'self::reportUnreadableProvider($db, $fileId, $error)')
        && str_contains($resolver, 'continue;')
        && str_contains($resolver, '$preferredFileId'),
    'The file currently being finalized/repaired may have no previous container; that transient self-provider miss must not create an open System Error.'
);
$check(
    'repaired_provider_error_is_auto_resolved',
    str_contains($handler, 'CatalogSystemErrorRecorder::resolveCompactMetadataProvider($fileId)')
        && str_contains($recorder, 'public static function resolveCompactMetadataProvider(int $fileId)')
        && str_contains($recorder, 'source_kind="compact-metadata-provider"')
        && str_contains($recorder, 'error_type="UnreadableCompactMetadataProvider"')
        && str_contains($recorder, 'JSON_EXTRACT(context_json,"$.provider_file_id")'),
    'Once a targeted repair positively verifies the provider, its matching operator error must stop appearing as an open fault.'
);
$check(
    'reader_distinguishes_missing_from_size_mismatch',
    str_contains($reader, 'clearstatcache(true, $path)')
        && str_contains($reader, 'UEDB5 container is missing: ')
        && str_contains($container, 'UEDB5 container is missing or too small: ')
        && str_contains($container, 'UEDB5 container has unexpected trailing or missing bytes.')
        && str_contains($health, 'UEDB5 registration payload does not match the container.'),
    'V5 health checks must distinguish missing, structurally damaged and registration-mismatched containers.'
);
$check(
    'repair_job_is_registered',
    str_contains($jobType, "REPAIR_COMPACT_METADATA_FILE = 'catalog.repair_compact_metadata_file'")
        && str_contains(
            $factory,
            'JobType::REPAIR_COMPACT_METADATA_FILE => static fn() => new CatalogCompactMetadataRepairJobHandler'
        ),
    'The one-file compact metadata repair job must be lazily executable by detached workers.'
);
$check(
    'repair_reuses_one_file_import_lock',
    str_contains($policy, 'JobType::REPAIR_COMPACT_METADATA_FILE')
        && str_contains($policy, "self::positiveKey('import:file-id:', \$payload['file_id'] ?? null)"),
    'Repair and Full Sync reimport for the same file must share the same concurrency identity.'
);
$check(
    'repair_is_phase_resumable',
    str_contains($handler, 'compact_repair_verify')
        && str_contains($handler, 'compact_repair_reimport')
        && str_contains($handler, 'compact_repair_dependency_plan')
        && str_contains($handler, 'compact_repair_dependency_wait')
        && str_contains($handler, 'JobType::REBUILD_AFFECTED_DEPENDENCIES')
        && str_contains($handler, "'dependencies'"),
    'Successful metadata repair must survive a later affected-dependency failure/restart.'
);
$check(
    'v5_health_verifies_before_scheduling_repair',
    str_contains($health, 'public static function verifyOrQueueRepair(')
        && str_contains($health, 'return self::verify($db, $config, $fileId);')
        && str_contains($health, 'self::queueRepair($db, $config, $fileId, $requestedBy, $error);')
        && str_contains($health, "'compact-metadata-repair:' . \$fileId"),
    'V5 health check must verify first, scheduling repair only on verification failure.'
);
$check(
    'v5_recovery_uses_isolated_storage',
    str_contains($recoveryFixture, 'sys_get_temp_dir()')
        && str_contains($recoveryFixture, 'Uedb5MetadataSnapshotWriter')
        && str_contains($recoveryFixture, 'missing_file_rejected')
        && str_contains($recoveryFixture, 'truncated_file_rejected')
        && str_contains($recoveryFixture, 'damaged_payload_rejected')
        && str_contains($recoveryFixture, 'restored_file_revalidated'),
    'V5 file-recovery regression must use disposable storage and cover missing, damaged and restored containers.'
);


$check(
    'repair_is_globally_deduplicated_by_file',
    str_contains($health, "'compact-metadata-repair:' . \$fileId")
        && str_contains($health, 'JobType::REPAIR_COMPACT_METADATA_FILE')
        && str_contains($dependencyHandler, "'compact-metadata-repair:' . \$fileId"),
    'Every runtime detector and dependency waiter must converge on one active v3 repair identity per file.'
);
$check(
    'terminal_repair_does_not_defer_forever',
    str_contains($dependencyHandler, "['failed', 'dead_letter', 'cancelled']")
        && str_contains($dependencyHandler, 'throw new RuntimeException')
        && !str_contains($dependencyHandler, 'Restart that repair child'),
    'Terminal repair failures must fail the dependent job instead of entering a permanent defer loop.'
);
$check(
    'completed_old_repair_does_not_mask_new_corruption',
    str_contains($dependencyHandler, "\$repair === null || \$status === 'completed'")
        && str_contains($dependencyHandler, 'VerifiedCompactMetadataHealth::healthy'),
    'A later corruption event must be able to enqueue a fresh repair after an older repair completed.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
