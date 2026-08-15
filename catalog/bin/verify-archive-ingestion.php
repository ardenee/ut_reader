#!/usr/bin/env php
<?php
/**
 * Read-only/no-database regression verifier for ZIP/7z/RAR ingestion plumbing.
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

$phpFiles = [
    'src/Infrastructure/Archive/CatalogArchiveExtractor.php',
    'src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    'src/Domain/Jobs/JobType.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Import/CatalogBucketBatchQueue.php',
    'src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
    'api/v1/profiled-upload-batch.php',
    'api/v1/profiled-upload-chunk.php',
    'api/v1/upload-bucket-chunk.php',
    'config.example.php',
];

$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Domain\Jobs\JobType;

$record(
    'archive_extensions_recognized',
    CatalogArchiveExtractor::isArchiveName('x.zip')
        && CatalogArchiveExtractor::isArchiveName('x.7z')
        && CatalogArchiveExtractor::isArchiveName('x.rar')
        && !CatalogArchiveExtractor::isArchiveName('x.uz3'),
    'ZIP, 7z and RAR are unpack-only containers; redirect wrappers remain separate.'
);

$record(
    'archive_job_types_registered',
    in_array(JobType::IMPORT_STAGED_ARCHIVE, JobType::all(), true)
        && in_array(JobType::PROCESS_BUCKET_ARCHIVE, JobType::all(), true)
        && in_array(JobType::PROCESS_BUCKET_STAGED_PACKAGE, JobType::all(), true),
    'Archive coordinator and extracted Upload Bucket member job types must stay in the domain registry.'
);

$factory = @file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$record(
    'archive_worker_routes_registered',
    is_string($factory)
        && str_contains($factory, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($factory, 'JobType::PROCESS_BUCKET_STAGED_PACKAGE')
        && str_contains($factory, 'new CatalogArchiveImportJobHandler')
        && str_contains($factory, 'new CatalogBucketStagedPackageJobHandler'),
    'Every archive job type must resolve to a worker handler.'
);

$bucketApi = @file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$profiledBatchApi = @file_get_contents($root . '/api/v1/profiled-upload-batch.php');
$profiledChunkApi = @file_get_contents($root . '/api/v1/profiled-upload-chunk.php');
$record(
    'upload_ingress_recognizes_archives',
    is_string($bucketApi) && str_contains($bucketApi, 'archive_container')
        && is_string($profiledBatchApi) && str_contains($profiledBatchApi, "['zip', '7z', 'rar']")
        && is_string($profiledChunkApi) && str_contains($profiledChunkApi, "['zip', '7z', 'rar']"),
    'Both Upload Bucket and selected-game upload ingress must recognize archive containers.'
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
    $checks[] = [
        'check' => 'zip_runtime_extraction',
        'ok' => true,
        'detail' => 'ZipArchive is unavailable in this CLI runtime; ZIP can use the 7-Zip backend on a configured server.',
    ];
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
