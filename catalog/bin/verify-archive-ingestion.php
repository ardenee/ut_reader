#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for ZIP/7z/RAR ingestion plumbing. */
declare(strict_types=1);

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
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
    'src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php',
    'src/Domain/Jobs/JobType.php',
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Import/CatalogBucketBatchQueue.php',
    'src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
    'api/v1/profiled-upload-batch.php',
    'api/v1/profiled-upload-chunk.php',
    'api/v1/upload-bucket-chunk.php',
    'profiled-upload.php',
    'upload-bucket-v2.php',
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
$configSource = (string)@file_get_contents($root . '/config.example.php');
$workerVersion = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$record(
    'archive_runtime_uses_no_command_line_tools',
    !str_contains($extractorSource, 'proc_open(')
        && !str_contains($extractorSource, 'shell_exec(')
        && !str_contains($extractorSource, 'exec(')
        && !str_contains($extractorSource, 'popen(')
        && !str_contains($extractorSource, 'sevenZipBinary')
        && !str_contains($extractorSource, 'UNREALDB_7ZIP_BINARY')
        && !str_contains($configSource, 'seven_zip_binary')
        && !str_contains($configSource, 'UNREALDB_7ZIP_BINARY'),
    'ZIP/RAR/7z runtime decoding must be performed only through PHP extensions; no 7z/unrar executable configuration or subprocess fallback may remain.'
);

$record(
    'archive_extensions_recognized',
    CatalogArchiveExtractor::isArchiveName('x.zip')
        && CatalogArchiveExtractor::isArchiveName('x.7z')
        && CatalogArchiveExtractor::isArchiveName('x.rar')
        && !CatalogArchiveExtractor::isArchiveName('x.uz3'),
    'ZIP, 7z and RAR are unpack-only containers; redirect wrappers remain separate.'
);

$capabilities = CatalogArchiveExtractor::runtimeCapabilities();
$libarchiveClass = class_exists(\libarchive\Archive::class);
$libarchiveStream = $libarchiveClass && method_exists(\libarchive\Archive::class, 'currentEntryStream');
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
    'RAR and 7z require PHP ext-archive (cataphract/libarchive) with currentEntryStream(). Released 0.2.0 auto-detects formats and does not expose supportFormats(); newer builds may expose explicit format restriction.'
);
$record(
    'libarchive_api_generation_is_supported',
    !$libarchiveClass || $libarchiveAutomaticApi || $libarchiveRestrictedApi,
    'Supported APIs are released 0.2.0 automatic format detection or the newer explicit supportFormats()/FORMAT_* API as a complete set.'
);
$record(
    'archive_capabilities_cover_zip_rar_7z',
    !empty($capabilities['zip']) && !empty($capabilities['rar']) && !empty($capabilities['seven_zip']),
    'The active PHP runtime must provide ZIP, RAR and 7z archive readers through ext-zip/ext-archive.'
);

$record(
    'libarchive_backend_is_streamed_and_optionally_format_restricted',
    str_contains($extractorSource, 'new \\libarchive\\Archive($archivePath)')
        && str_contains($extractorSource, 'supportFormats($first, ...$formats)')
        && str_contains($extractorSource, 'currentEntryStream()')
        && str_contains($extractorSource, "'backend' => 'libarchive'")
        && str_contains($extractorSource, 'FORMAT_RAR_V5')
        && str_contains($extractorSource, 'FORMAT_7ZIP')
        && str_contains($extractorSource, 'isEncrypted')
        && str_contains($extractorSource, 'isSymlink')
        && str_contains($extractorSource, 'hardlink'),
    'RAR/RAR5/7z are streamed directly through libarchive. Newer ext-archive builds use explicit format restriction; released 0.2.0 relies on libarchive automatic format detection while retaining encryption/link/path safety gates.'
);

$record(
    'archive_worker_fingerprint_tracks_backend',
    str_contains($workerVersion, 'CatalogArchiveExtractor.php')
        && str_contains($workerVersion, 'CatalogArchiveImportJobHandler.php'),
    'Changing archive decoding code must invalidate the detached-worker fingerprint so stale workers are reconciled.'
);

$record(
    'archive_job_types_registered',
    in_array(JobType::IMPORT_STAGED_ARCHIVE, JobType::all(), true)
        && in_array(JobType::PROCESS_BUCKET_ARCHIVE, JobType::all(), true)
        && in_array(JobType::PROCESS_BUCKET_STAGED_PACKAGE, JobType::all(), true),
    'Archive coordinator and extracted Upload Bucket member job types must stay in the domain registry.'
);

$archiveProfile = JobResourcePolicy::for(JobType::IMPORT_STAGED_ARCHIVE, ['game_id' => 7]);
$bucketArchiveProfile = JobResourcePolicy::for(JobType::PROCESS_BUCKET_ARCHIVE, ['staged_path' => 'jobs/incoming/archive.zip']);
$bucketMemberProfile = JobResourcePolicy::for(JobType::PROCESS_BUCKET_STAGED_PACKAGE, ['staged_path' => 'jobs/incoming/Test.utx']);
$record(
    'archive_resource_profiles_registered',
    $archiveProfile->resourceClass === JobResourcePolicy::ARCHIVE_IMPORT_HEAVY
        && $bucketArchiveProfile->resourceClass === JobResourcePolicy::ARCHIVE_IMPORT_HEAVY
        && $bucketMemberProfile->resourceClass === JobResourcePolicy::BUCKET_PROCESSING,
    'Archive coordinators stay bounded while extracted Upload Bucket members use normal bucket-processing capacity.'
);

$factory = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$record(
    'archive_worker_routes_registered',
    str_contains($factory, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_STAGED_PACKAGE')
        && str_contains($factory, 'new CatalogArchiveImportJobHandler')
        && str_contains($factory, 'new CatalogBucketStagedPackageJobHandler'),
    'Every archive job type must resolve to a worker handler.'
);

$bucketApi = (string)@file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$profiledBatchApi = (string)@file_get_contents($root . '/api/v1/profiled-upload-batch.php');
$profiledChunkApi = (string)@file_get_contents($root . '/api/v1/profiled-upload-chunk.php');
$record(
    'upload_ingress_recognizes_archives',
    str_contains($bucketApi, 'archive_container')
        && str_contains($profiledBatchApi, "['zip', '7z', 'rar']")
        && str_contains($profiledChunkApi, "['zip', '7z', 'rar']"),
    'Both Upload Bucket and selected-game upload ingress must recognize archive containers.'
);

$profiledClient = (string)@file_get_contents($root . '/assets/profiled-upload-jobs.js');
$bucketInspector = (string)@file_get_contents($root . '/assets/upload-file-inspector-worker-compatible.js');
$record(
    'browser_archive_policy_uses_container_path',
    str_contains($profiledClient, 'function isArchive(file)')
        && str_contains($profiledClient, 'return isPak(file) || isArchive(file) ||')
        && str_contains($profiledClient, 'const container = isPak(file) || isArchive(file);')
        && str_contains($profiledClient, 'archive/container limit'),
    'Selected-game browser uploads must treat ZIP/7z/RAR as resumable containers, not normal package files.'
);
$record(
    'bucket_inspector_skips_archive_package_hashing',
    str_contains($bucketInspector, "['zip', '7z', 'rar'].includes(extension)")
        && str_contains($bucketInspector, 'archive: true')
        && !str_contains($bucketInspector, "replace(/\\.uz$/i, '.uz3')"),
    'Upload Bucket preflight must not hash archive bytes as package identity or relabel a 5678 .uz wrapper as UT3 .uz3.'
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
                    'One selected member should be streamed to a temporary regular file without unpacking the archive tree.'
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
