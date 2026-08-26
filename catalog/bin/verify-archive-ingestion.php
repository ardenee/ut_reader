#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for ZIP/7z/RAR/UMOD-family ingestion plumbing. */
declare(strict_types=1);

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobFileTreeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobResultHydrator;

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

$phpFiles = [
    'src/Infrastructure/Archive/CatalogArchiveExtractor.php',
    'src/Infrastructure/Archive/CatalogUmodArchiveReader.php',
    'src/Infrastructure/Downloads/CatalogUmodBinaryCodec.php',
    'src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php',
    'src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php',
    'src/Domain/Jobs/JobType.php',
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Persistence/PdoJobClaimer.php',
    'src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Import/CatalogBucketBatchQueue.php',
    'src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
    'api/v1/job-file-tree.php',
    'api/v1/profiled-upload-batch.php',
    'api/v1/profiled-upload-chunk.php',
    'api/v1/upload-bucket-chunk.php',
    'bin/repair-archive-child-queues.php',
    'profiled-upload.php',
    'upload-bucket-v2.php',
    'background-jobs.php',
    'config.example.php',
];

$syntaxFailures = [];
foreach ($phpFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ' could not be linted';
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

require_once $root . '/bootstrap/autoload.php';

$extractorSource = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php');
$umodReaderSource = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogUmodArchiveReader.php');
$archiveHandlerSource = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php');
$archiveWorkflowSource = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$repairQueueSource = (string)@file_get_contents($root . '/bin/repair-archive-child-queues.php');
$configSource = (string)@file_get_contents($root . '/config.example.php');
$workerVersion = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$resourceLimitStoreSource = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$pageSource = (string)@file_get_contents($root . '/background-jobs.php');
$fileTreeApiSource = (string)@file_get_contents($root . '/api/v1/job-file-tree.php');
$fileTreeClientSource = (string)@file_get_contents($root . '/assets/background-jobs-files.js');

$record(
    'archive_runtime_uses_no_command_line_tools',
    !str_contains($extractorSource, 'proc_open(')
        && !str_contains($extractorSource, 'shell_exec(')
        && !str_contains($extractorSource, 'exec(')
        && !str_contains($extractorSource, 'popen(')
        && !str_contains($umodReaderSource, 'proc_open(')
        && !str_contains($umodReaderSource, 'shell_exec(')
        && !str_contains($umodReaderSource, 'exec(')
        && !str_contains($umodReaderSource, 'popen(')
        && !str_contains($extractorSource, 'sevenZipBinary')
        && !str_contains($extractorSource, 'UNREALDB_7ZIP_BINARY')
        && !str_contains($configSource, 'seven_zip_binary')
        && !str_contains($configSource, 'UNREALDB_7ZIP_BINARY'),
    'Archive runtime decoding must remain entirely in-process through PHP extensions or the native UMOD parser; no 7z/unrar executable configuration or subprocess fallback may remain.'
);

$record(
    'archive_extensions_recognized',
    CatalogArchiveExtractor::isArchiveName('x.zip')
        && CatalogArchiveExtractor::isArchiveName('x.7z')
        && CatalogArchiveExtractor::isArchiveName('x.rar')
        && CatalogArchiveExtractor::isArchiveName('x.umod')
        && CatalogArchiveExtractor::isArchiveName('x.ut2mod')
        && CatalogArchiveExtractor::isArchiveName('x.ut4mod')
        && !CatalogArchiveExtractor::isArchiveName('x.uz3')
        && !CatalogArchiveExtractor::isArchiveName('x.exe'),
    'ZIP, 7z, RAR and UMOD-family files are unpack-only containers; redirect wrappers and EXE files remain separate.'
);

$capabilities = CatalogArchiveExtractor::runtimeCapabilities();
$libarchiveClass = class_exists(\libarchive\Archive::class);
$libarchiveStream = $libarchiveClass && method_exists(\libarchive\Archive::class, 'currentEntryStream');
$libarchiveExtractCurrent = $libarchiveClass && method_exists(\libarchive\Archive::class, 'extractCurrent');
$libarchiveRestrictedApi = $libarchiveClass
    && method_exists(\libarchive\Archive::class, 'supportFormats')
    && defined('libarchive\\FORMAT_RAR')
    && defined('libarchive\\FORMAT_RAR_V5')
    && defined('libarchive\\FORMAT_7ZIP');
$libarchiveAutomaticApi = $libarchiveClass
    && !method_exists(\libarchive\Archive::class, 'supportFormats')
    && !defined('libarchive\\FORMAT_RAR')
    && !defined('libarchive\\FORMAT_RAR_V5')
    && !defined('libarchive\\FORMAT_7ZIP');
$record(
    'libarchive_php_extension_available',
    !empty($capabilities['libarchive']) && $libarchiveStream,
    '7z and the libarchive compatibility paths require PHP ext-archive (cataphract/libarchive). Released 0.2.0 exposes currentEntryStream() and extractCurrent(); newer builds may also expose explicit format restriction.'
);
$record(
    'libarchive_native_extraction_available',
    !$libarchiveClass || $libarchiveExtractCurrent,
    'Released ext-archive 0.2.0 should provide extractCurrent() so one selected RAR/7z member can use libarchive native block extraction.'
);
$record(
    'libarchive_api_generation_is_supported',
    !$libarchiveClass || $libarchiveAutomaticApi || $libarchiveRestrictedApi,
    'Supported APIs are released 0.2.0 automatic format detection or the newer explicit supportFormats()/FORMAT_* API as a complete set.'
);
$record(
    'archive_capabilities_cover_zip_rar_7z_umod',
    !empty($capabilities['zip'])
        && !empty($capabilities['rar'])
        && !empty($capabilities['seven_zip'])
        && !empty($capabilities['umod_family']),
    'The active runtime must provide ZIP/RAR/7z readers and native UMOD-family parsing.'
);

$record(
    'libarchive_backend_uses_controlled_native_member_extraction',
    str_contains($extractorSource, 'new \\libarchive\\Archive($archivePath)')
        && str_contains($extractorSource, "method_exists(\$archive, 'supportFormats')")
        && str_contains($extractorSource, "method_exists(\$archive, 'extractCurrent')")
        && str_contains($extractorSource, '$archiveEntry->pathname = $temporary')
        && str_contains($extractorSource, '$archive->extractCurrent($archiveEntry)')
        && str_contains($extractorSource, 'currentEntryStream()')
        && str_contains($extractorSource, 'libarchiveFailureMessage(')
        && str_contains($extractorSource, "'backend' => 'libarchive'")
        && str_contains($extractorSource, 'FORMAT_RAR_V5')
        && str_contains($extractorSource, 'FORMAT_7ZIP')
        && str_contains($extractorSource, 'safeMemberPath($rawPath)')
        && str_contains($extractorSource, 'verifyExtractedFile('),
    'RAR/RAR5/7z must prefer one-entry native extractCurrent() to a controlled temporary path, retain currentEntryStream() only as a compatibility fallback, and preserve exact-size/path validation.'
);

$record(
    'libarchive_failures_include_actionable_context',
    str_contains($extractorSource, 'could not be extracted by libarchive')
        && str_contains($extractorSource, 'declared ')
        && str_contains($extractorSource, 'bytes_copied=')
        && str_contains($extractorSource, 'expected ')
        && str_contains($extractorSource, 'bytes, got '),
    'A failed archive member must report the archive format/member, exception context and exact size diagnostics instead of a generic stream failure.'
);

$record(
    'released_libarchive_entry_metadata_is_supported',
    str_contains($extractorSource, '$archiveEntry->pathname')
        && str_contains($extractorSource, '$archiveEntry->size')
        && !str_contains($extractorSource, '$archiveEntry->isFile')
        && !str_contains($extractorSource, '$archiveEntry->isDir')
        && !str_contains($extractorSource, '$archiveEntry->isSymlink')
        && !str_contains($extractorSource, '$archiveEntry->hardlink')
        && !str_contains($extractorSource, '$archiveEntry->isEncrypted'),
    'Released ext-archive 0.2.0 exposes pathname/size but not the richer entry-type/link/encryption virtual properties used by development builds.'
);

$archiveIssueRows = (new CatalogBackgroundJobFileTreeProjector())->project([[
    'id' => 501,
    'job_type' => JobType::IMPORT_STAGED_ARCHIVE,
    'status' => 'completed',
    'display_status' => 'partial',
    'payload' => [
        'original_name' => 'fixture.zip',
        'source_relative_path' => 'Unreal/MapPacks/fixture.zip',
        'size' => 1234,
    ],
    'progress' => ['stage' => 'complete', 'percent' => 100],
    'result' => [
        'status' => 'partial',
        'message' => 'Archive processing complete: 0 imported, 0 duplicate, 0 skipped, 1 failed.',
        'errors' => [[
            'file' => 'Maps/Broken.ut2',
            'error' => 'fixture decoder failure',
        ]],
    ],
    'child_count' => 1,
    'child_issue_count' => 1,
    'child_active_count' => 0,
]]);
$archiveIssueReason = (string)($archiveIssueRows[0]['issue_reason'] ?? '');
$record(
    'archive_failures_are_visible_in_background_jobs',
    str_contains($pageSource, 'assets/background-jobs-files.js')
        && str_contains($fileTreeApiSource, 'CatalogBackgroundJobFileTreeProjector')
        && str_contains($fileTreeClientSource, 'file.issue_reason')
        && str_contains($archiveIssueReason, 'Maps/Broken.ut2')
        && str_contains($archiveIssueReason, 'fixture decoder failure'),
    'Partial archive jobs must promote retained member errors into the file-centric Background Jobs issue reason while failed child files remain expandable.'
);

$record(
    'archive_children_inherit_parent_queue',
    str_contains($archiveHandlerSource, '$queueName = trim($job->queue);')
        && str_contains($archiveHandlerSource, '$queue->enqueue(')
        && str_contains($archiveHandlerSource, '$queueName,')
        && !str_contains($archiveHandlerSource, "\$this->config['queue']['name'] ?? 'catalog'"),
    'Extracted archive members must stay on the same queue as their claimed archive parent instead of silently moving from catalog:bucket-processing to catalog.'
);

$record(
    'misplaced_archive_children_have_bounded_repair',
    str_contains($repairQueueSource, "c.status='queued'")
        && str_contains($repairQueueSource, "c.workflow_unit_key LIKE 'archive:%'")
        && str_contains($repairQueueSource, 'c.queue_name<>p.queue_name')
        && str_contains($repairQueueSource, 'SET c.queue_name=p.queue_name')
        && str_contains($repairQueueSource, 'LEFT JOIN ue_background_jobs x')
        && str_contains($repairQueueSource, "in_array('--execute', \$argv, true)"),
    'A dry-run-first repair command must move only still-queued archive children back to their parent queue and leave running/terminal rows untouched.'
);

$record(
    'archive_worker_fingerprint_tracks_backend',
    str_contains($workerVersion, 'CatalogArchiveExtractor.php')
        && str_contains($workerVersion, 'CatalogArchiveImportJobHandler.php')
        && str_contains($workerVersion, 'CatalogUmodArchiveReader.php')
        && str_contains($workerVersion, 'CatalogUmodBinaryCodec.php')
        && str_contains($workerVersion, 'JobResourcePolicy.php')
        && str_contains($workerVersion, 'CatalogJobResourceLimitStore.php')
        && str_contains($workerVersion, 'PdoJobClaimer.php'),
    'Changing archive decoding, source-root scheduling or archive resource policy must invalidate the detached-worker fingerprint so stale workers are reconciled.'
);

$record(
    'archive_job_types_registered',
    in_array(JobType::IMPORT_STAGED_ARCHIVE, JobType::all(), true)
        && in_array(JobType::PROCESS_BUCKET_ARCHIVE, JobType::all(), true)
        && in_array(JobType::PROCESS_BUCKET_STAGED_PACKAGE, JobType::all(), true),
    'Archive coordinator and extracted Upload Bucket member job types must stay in the domain registry.'
);

$archiveProfile = JobResourcePolicy::for(JobType::IMPORT_STAGED_ARCHIVE, [
    'game_id' => 7,
    'staged_path' => 'jobs/incoming/source-archive.zip',
]);
$bucketArchiveProfile = JobResourcePolicy::for(JobType::PROCESS_BUCKET_ARCHIVE, ['staged_path' => 'jobs/incoming/archive.umod']);
$bucketMemberProfile = JobResourcePolicy::for(JobType::PROCESS_BUCKET_STAGED_PACKAGE, ['staged_path' => 'jobs/incoming/Test.utx']);
$record(
    'archive_resource_profiles_registered',
    $archiveProfile->resourceClass === JobResourcePolicy::SOURCE_ARCHIVE_IMPORT
        && $archiveProfile->limit === 4
        && is_string($archiveProfile->concurrencyKey)
        && str_starts_with($archiveProfile->concurrencyKey, 'import:file:')
        && !str_starts_with($archiveProfile->concurrencyKey, 'import:game:')
        && $bucketArchiveProfile->resourceClass === JobResourcePolicy::ARCHIVE_IMPORT_HEAVY
        && $bucketMemberProfile->resourceClass === JobResourcePolicy::BUCKET_PROCESSING,
    'Independent staged source archives use bounded parallel per-source admission; serial archive/backup coordinators remain on archive-import-heavy.'
);

$record(
    'queued_staged_archives_are_reclassified_on_worker_start',
    str_contains($resourceLimitStoreSource, 'JobResourcePolicy::SOURCE_ARCHIVE_IMPORT')
        && str_contains($resourceLimitStoreSource, 'JobType::IMPORT_STAGED_PAK')
        && str_contains($resourceLimitStoreSource, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($resourceLimitStoreSource, 'CONCAT("import:source-job:",id)')
        && str_contains($resourceLimitStoreSource, '$sourceArchiveRows'),
    'Already-queued staged source archives must learn the parallel per-source resource policy when workers restart; operators must not need to delete and requeue them.'
);

$factory = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$record(
    'archive_worker_routes_registered',
    str_contains($factory, 'JobType::IMPORT_STAGED_ARCHIVE => static fn() => new CatalogArchiveWorkflowJobHandler')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_ARCHIVE => static fn() => new CatalogArchiveWorkflowJobHandler')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_STAGED_PACKAGE => static fn() => new CatalogArchiveMemberContentRoutingJobHandler')
        && str_contains($archiveWorkflowSource, 'new CatalogArchiveImportJobHandler')
        && str_contains($factory, 'new CatalogBucketStagedPackageJobHandler'),
    'Archive roots must route through the lifecycle coordinator, which delegates extraction to CatalogArchiveImportJobHandler; staged archive members must retain content routing.'
);
$record(
    'worker_start_synchronizes_queued_resource_policy',
    str_contains($factory, 'synchronizeQueuedPolicies()'),
    'Worker startup must repair persisted queued resource policy before claiming work.'
);

$bucketApi = (string)@file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$profiledBatchApi = (string)@file_get_contents($root . '/api/v1/profiled-upload-batch.php');
$profiledChunkApi = (string)@file_get_contents($root . '/api/v1/profiled-upload-chunk.php');
$record(
    'upload_ingress_recognizes_archives',
    str_contains($bucketApi, 'archive_container')
        && str_contains($profiledBatchApi, 'CatalogArchiveExtractor::archiveExtensions()')
        && str_contains($profiledBatchApi, 'CatalogArchiveExtractor::isArchiveName($originalName)')
        && str_contains($profiledChunkApi, 'CatalogArchiveExtractor::archiveExtensions()')
        && str_contains($profiledChunkApi, 'CatalogArchiveExtractor::isArchiveName($originalName)'),
    'Both Upload Bucket and selected-game upload ingress must share the complete archive-container policy, including UMOD/UT2MOD/UT4MOD.'
);

$profiledClient = (string)@file_get_contents($root . '/assets/profiled-upload-jobs.js');
$bucketInspector = (string)@file_get_contents($root . '/assets/upload-file-inspector-worker-compatible.js');
$record(
    'browser_archive_policy_uses_container_path',
    str_contains($profiledClient, 'function isArchive(file)')
        && str_contains($profiledClient, 'return isPak(file) || isArchive(file) ||')
        && str_contains($profiledClient, 'const container = isPak(file) || isArchive(file);')
        && str_contains($profiledClient, 'archive/container limit'),
    'Selected-game browser uploads must keep archive containers out of ordinary package handling.'
);
$record(
    'bucket_inspector_skips_archive_package_hashing',
    str_contains($bucketInspector, "new Set(['zip', '7z', 'rar', 'umod', 'ut2mod', 'ut4mod'])")
        && str_contains($bucketInspector, "new Set(['umod', 'ut2mod', 'ut4mod'])")
        && str_contains($bucketInspector, 'await umodHeader(file, extension)')
        && str_contains($bucketInspector, 'archive: true')
        && !str_contains($bucketInspector, "replace(/\\.uz$/i, '.uz3')"),
    'Upload Bucket preflight must not hash archive bytes as package identity; UMOD-family files use a bounded footer check and a 5678 .uz remains .uz.'
);

$hydrator = new CatalogBackgroundJobResultHydrator(['storage_path' => sys_get_temp_dir()]);
$redirectRows = $hydrator->hydrate([[
    'id' => 91,
    'job_type' => JobType::PROCESS_BUCKET_STAGED_PACKAGE,
    'status' => 'completed',
    'payload_json' => json_encode([
        'original_name' => 'Example.utx.uz3',
        'source_relative_path' => 'bundle.zip/Example.utx.uz3',
    ], JSON_THROW_ON_ERROR),
    'progress_json' => json_encode(['stage' => 'complete', 'percent' => 100], JSON_THROW_ON_ERROR),
    'result_json' => json_encode([
        'operation' => 'process_bucket_staged_package',
        'status' => 'bucketed',
        'original_name' => 'Example.utx',
        'source_relative_path' => 'bundle.zip/Example.utx',
        'decoder' => 'epic-uz3-zlib-uncompress',
        'queue_name' => 'Example.utx',
    ], JSON_THROW_ON_ERROR),
]]);
$redirectResult = is_array($redirectRows[0]['result'] ?? null) ? $redirectRows[0]['result'] : [];
$record(
    'archive_redirect_child_identity_is_valid',
    empty($redirectResult['integrity_mismatch']) && (string)($redirectResult['status'] ?? '') === 'bucketed',
    'An archive-extracted redirect child may return the decompressed package name without triggering a false result-identity failure.'
);

if (class_exists(ZipArchive::class)) {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-archive-verify-' . bin2hex(random_bytes(6));
    @mkdir($directory, 0700, true);
    $archivePath = $directory . DIRECTORY_SEPARATOR . 'fixture.zip';
    $zip = new ZipArchive();
    $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        $record('zip_fixture_created', false, 'ZipArchive could not create the temporary fixture.');
    } else {
        $zip->addFromString('Maps/Test.utx', 'UNREALDB_ARCHIVE_TEST');
        $zip->addFromString('../evil.utx', 'TRAVERSAL_MUST_NOT_EXTRACT');
        $zip->addFromString('nested.zip', 'NESTED_ARCHIVE_MUST_NOT_RECURSE');
        $zip->close();

        try {
            $extractor = new CatalogArchiveExtractor([
                'storage_path' => $directory,
                'archive' => ['max_entries' => 20, 'max_unpacked_bytes' => 1024 * 1024],
            ]);
            $entries = $extractor->entries($archivePath, 'fixture.zip');
            $valid = null;
            $traversal = null;
            foreach ($entries as $entry) {
                if (($entry['path'] ?? '') === 'Maps/Test.utx') {
                    $valid = $entry;
                }
                if (str_contains((string)($entry['path'] ?? ''), 'evil.utx')) {
                    $traversal = $entry;
                }
            }
            $record(
                'zip_listing_classifies_paths',
                is_array($valid) && !empty($valid['safe'])
                    && is_array($traversal) && empty($traversal['safe']),
                'Normal relative paths must be accepted and parent traversal must be rejected before extraction.'
            );

            if (is_array($valid)) {
                $temporary = $extractor->extractToTemp($archivePath, 'fixture.zip', $valid, 1024 * 1024);
                $bytes = @file_get_contents($temporary);
                @unlink($temporary);
                $record(
                    'zip_member_extracts_exact_bytes',
                    $bytes === 'UNREALDB_ARCHIVE_TEST',
                    'One selected member should be extracted to a controlled temporary regular file without unpacking the archive tree.'
                );
            } else {
                $record('zip_member_extracts_exact_bytes', false, 'Valid fixture member was not listed.');
            }
        } catch (Throwable $error) {
            $record('zip_runtime_extraction', false, get_class($error) . ': ' . $error->getMessage());
        }
    }
    @unlink($archivePath);
    @rmdir($directory);
} else {
    $record(
        'zip_runtime_backend_available',
        !empty($capabilities['zip']),
        'ZIP needs either ext-zip (ZipArchive) or ext-archive/libarchive.'
    );
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
