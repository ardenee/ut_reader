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
$reader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php');
$handler = $read('src/Infrastructure/Jobs/CatalogCompactMetadataRepairJobHandler.php');
$recorder = $read('src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php');
$jobType = $read('src/Domain/Jobs/JobType.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$policy = $read('src/Domain/Jobs/JobResourcePolicy.php');
$cli = $read('bin/verify-compact-metadata-files.php');

$check(
    'provider_corruption_is_isolated',
    str_contains($resolver, 'catch (Throwable $error)')
        && str_contains($resolver, 'reportUnreadableProvider($fileId, $error)')
        && str_contains($resolver, 'CatalogSystemErrorRecorder::record')
        && str_contains($resolver, 'clearstatcache();'),
    'Unreadable provider metadata must not abort every consumer; it must be skipped and reported once.'
);
$check(
    'self_provider_repair_is_not_operator_error',
    str_contains($resolver, 'if ($fileId !== $preferredFileId)')
        && str_contains($resolver, 'self::reportUnreadableProvider($fileId, $error)')
        && str_contains($resolver, 'previous container may legitimately')
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
        && str_contains($reader, 'Blocked metadata file is missing: ')
        && str_contains($reader, '(expected=')
        && str_contains($reader, ', actual='),
    'Storage diagnostics must distinguish a missing metadata file from a real DB/on-disk size disagreement.'
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
    'targeted_cli_only_queues_invalid_files',
    str_contains($cli, "'status' => 'valid'")
        && str_contains($cli, "'status' => \$queueRepair ? 'repair_queued' : 'invalid'")
        && str_contains($cli, "'compact-metadata-repair:' . \$fileId")
        && str_contains($cli, "array_key_exists('queue-repair', \$options)"),
    'The diagnostic command must verify first and only queue repair for containers that actually fail verification.'
);
$check(
    'targeted_cli_makes_storage_root_explicit',
    str_contains($cli, "'storage-root:'")
        && str_contains($cli, "'storage_root' => \$storageRoot")
        && str_contains($cli, "'storage_root_source'")
        && str_contains($cli, "'configured_storage_root'"),
    'A source checkout and deployed runtime can have different storage trees, so diagnostics must expose and allow overriding the storage root.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
