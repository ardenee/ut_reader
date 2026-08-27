#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for UMOD/UT2MOD/UT4MOD ingestion. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogUmodArchiveReader;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogUmodBinaryCodec;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueueSupport;

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
    'src/Infrastructure/Archive/CatalogUmodArchiveReader.php',
    'src/Infrastructure/Archive/CatalogArchiveExtractor.php',
    'src/Infrastructure/Archive/CatalogSequentialArchiveReader.php',
    'src/Infrastructure/Downloads/CatalogUmodBinaryCodec.php',
    'src/Infrastructure/Persistence/PdoJobQueueSupport.php',
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Import/CatalogBucketBatchQueue.php',
    'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'api/v1/profiled-upload-batch.php',
    'api/v1/profiled-upload-chunk.php',
    'upload-bucket-v2.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim($stderr . ' ' . $stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

require_once $root . '/bootstrap/autoload.php';

$readerSource = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogUmodArchiveReader.php');
$extractorSource = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php');
$sequentialSource = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php');
$policySource = (string)@file_get_contents($root . '/src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php');
$bucketQueueSource = (string)@file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$profiledQueueSource = (string)@file_get_contents($root . '/src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
$profiledBatchSource = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php');
$profiledBatchApi = (string)@file_get_contents($root . '/api/v1/profiled-upload-batch.php');
$profiledChunkApi = (string)@file_get_contents($root . '/api/v1/profiled-upload-chunk.php');
$browserSource = (string)@file_get_contents($root . '/assets/upload-file-inspector-worker-compatible.js');
$workerVersionSource = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$record(
    'umod_family_extensions_are_transport_containers',
    CatalogArchiveExtractor::isArchiveName('fixture.umod')
        && CatalogArchiveExtractor::isArchiveName('fixture.ut2mod')
        && CatalogArchiveExtractor::isArchiveName('fixture.ut4mod')
        && !CatalogArchiveExtractor::isArchiveName('fixture.exe'),
    'UMOD/UT2MOD/UT4MOD must be accepted as archive/install containers while EXE remains outside this policy.'
);

$record(
    'umod_reader_is_php_only',
    !str_contains($readerSource, 'proc_open(')
        && !str_contains($readerSource, 'shell_exec(')
        && !str_contains($readerSource, 'exec(')
        && !str_contains($readerSource, 'popen(')
        && !str_contains(strtolower($readerSource), '7z.exe')
        && !str_contains(strtolower($readerSource), 'unrar.exe'),
    'UMOD-family parsing/extraction must remain in-process PHP with no external executables.'
);

$record(
    'umod_reader_validates_unreal_setup_structure',
    str_contains($readerSource, 'private const FOOTER_BYTES = 20;')
        && str_contains($readerSource, 'private const MAGIC = 0x9FE3C5A3;')
        && str_contains($readerSource, 'CatalogUmodBinaryCodec::readCompactIndex')
        && str_contains($readerSource, 'CatalogUmodBinaryCodec::readUe1String')
        && str_contains($readerSource, 'CatalogUmodBinaryCodec::unrealMemCrcStream')
        && str_contains($readerSource, "unpack('Voffset/Vsize/Vflags'")
        && str_contains($readerSource, '$itemOffset + $itemSize > $tableOffset'),
    'Native UMOD ingestion must validate footer magic/version/size/CRC and bounded directory offsets before extraction.'
);

$record(
    'archive_extractor_delegates_umod_family',
    str_contains($extractorSource, 'CatalogUmodArchiveReader::isName($archiveName)')
        && str_contains($extractorSource, "'umod' => (new CatalogUmodArchiveReader")
        && str_contains($extractorSource, "['zip', '7z', 'rar', 'umod', 'ut2mod', 'ut4mod']"),
    'The existing archive coordinator must reuse one native UMOD-family backend rather than creating a parallel import workflow.'
);

$record(
    'sequential_libarchive_path_excludes_umod',
    str_contains($sequentialSource, 'CatalogUmodArchiveReader::isName($archiveName)')
        && str_contains($sequentialSource, 'return false;'),
    'UMOD-family containers must bypass libarchive and use their native Unreal Setup reader.'
);

$record(
    'all_ingress_paths_share_archive_policy',
    str_contains($policySource, 'CatalogArchiveExtractor::archiveExtensions()')
        && str_contains($policySource, 'CatalogArchiveExtractor::isArchiveName($name)')
        && str_contains($bucketQueueSource, 'CatalogArchiveExtractor::isArchiveName($originalName)')
        && str_contains($profiledQueueSource, 'CatalogArchiveExtractor::isArchiveName($originalName)')
        && str_contains($profiledBatchSource, 'CatalogArchiveExtractor::isArchiveName($originalName)')
        && str_contains($profiledBatchApi, 'CatalogArchiveExtractor::archiveExtensions()')
        && str_contains($profiledBatchApi, 'CatalogArchiveExtractor::isArchiveName($originalName)')
        && str_contains($profiledChunkApi, 'CatalogArchiveExtractor::archiveExtensions()')
        && str_contains($profiledChunkApi, 'CatalogArchiveExtractor::isArchiveName($originalName)'),
    'Upload Bucket and selected-game upload paths must derive UMOD-family behavior from the shared archive policy.'
);

$record(
    'browser_preflight_recognizes_umod_footer',
    str_contains($browserSource, "new Set(['zip', '7z', 'rar', 'umod', 'ut2mod', 'ut4mod'])")
        && str_contains($browserSource, "new Set(['umod', 'ut2mod', 'ut4mod'])")
        && str_contains($browserSource, 'UMOD_FOOTER_BYTES = 20')
        && str_contains($browserSource, 'UMOD_MAGIC = 0x9fe3c5a3')
        && str_contains($browserSource, 'await umodHeader(file, extension)')
        && str_contains($browserSource, 'archive: true'),
    'The browser must treat UMOD-family files as transport containers and inspect only their bounded footer instead of package-hashing them.'
);

$record(
    'worker_fingerprint_tracks_umod_reader_and_codec',
    str_contains($workerVersionSource, 'CatalogUmodArchiveReader.php')
        && str_contains($workerVersionSource, 'CatalogUmodBinaryCodec.php')
        && str_contains($workerVersionSource, 'PdoJobQueueSupport.php'),
    'Changing UMOD parsing, binary primitives or queue JSON persistence must invalidate detached workers.'
);

$ansiOffset = 0;
$ansiSerialized = CatalogUmodBinaryCodec::compactIndex(5) . "Caf\xE9\0";
$ansiDecoded = CatalogUmodBinaryCodec::readUe1String($ansiSerialized, $ansiOffset);
$record(
    'legacy_umod_ansi_filename_is_utf8',
    $ansiDecoded === "Café" && preg_match('//u', $ansiDecoded) === 1,
    'Positive-length legacy UMOD FStrings must normalize Windows-1252 filename bytes to UTF-8 before path/job persistence.'
);

$jsonFallback = '';
try {
    $jsonFallback = PdoJobQueueSupport::encodeJson(['message' => "legacy-\xFF-name"]);
} catch (Throwable) {
    $jsonFallback = '';
}
$decodedFallback = $jsonFallback !== '' ? json_decode($jsonFallback, true) : null;
$record(
    'job_json_invalid_utf8_is_nonfatal',
    is_array($decodedFallback)
        && is_string($decodedFallback['message'] ?? null)
        && preg_match('//u', (string)$decodedFallback['message']) === 1,
    'A stray legacy byte in diagnostic/progress data must not kill a worker checkpoint.'
);

/**
 * @param string $path
 * @param list<array{path:string,bytes:string,flags:int}> $members
 */
$writeFixture = static function (string $path, array $members): void {
    $payload = '';
    $directoryItems = [];
    foreach ($members as $member) {
        $offset = strlen($payload);
        $bytes = (string)$member['bytes'];
        $payload .= $bytes;
        $directoryItems[] = [
            'path' => (string)$member['path'],
            'offset' => $offset,
            'size' => strlen($bytes),
            'flags' => (int)$member['flags'],
        ];
    }

    $tableOffset = strlen($payload);
    $table = CatalogUmodBinaryCodec::compactIndex(count($directoryItems));
    foreach ($directoryItems as $item) {
        $table .= CatalogUmodBinaryCodec::ue1String($item['path']);
        $table .= CatalogUmodBinaryCodec::packU32($item['offset']);
        $table .= CatalogUmodBinaryCodec::packU32($item['size']);
        $table .= CatalogUmodBinaryCodec::packU32($item['flags']);
    }
    $beforeFooter = $payload . $table;
    if (file_put_contents($path, $beforeFooter) !== strlen($beforeFooter)) {
        throw new RuntimeException('Could not write temporary UMOD fixture payload.');
    }
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not reopen temporary UMOD fixture for CRC.');
    }
    try {
        $crc = CatalogUmodBinaryCodec::unrealMemCrcStream($handle, strlen($beforeFooter));
    } finally {
        fclose($handle);
    }
    $size = strlen($beforeFooter) + 20;
    $footer = CatalogUmodBinaryCodec::packU32(0x9FE3C5A3)
        . CatalogUmodBinaryCodec::packU32($tableOffset)
        . CatalogUmodBinaryCodec::packU32($size)
        . CatalogUmodBinaryCodec::packU32(1)
        . CatalogUmodBinaryCodec::packU32($crc);
    if (file_put_contents($path, $footer, FILE_APPEND) !== strlen($footer)) {
        throw new RuntimeException('Could not append temporary UMOD fixture footer.');
    }
};

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-umod-verify-' . bin2hex(random_bytes(6));
@mkdir($directory, 0700, true);
try {
    foreach (['umod', 'ut2mod', 'ut4mod'] as $extension) {
        $fixture = $directory . DIRECTORY_SEPARATOR . 'fixture.' . $extension;
        $payload = 'UNREALDB_' . strtoupper($extension) . '_MEMBER';
        $writeFixture($fixture, [
            ['path' => 'Maps\\TestMap.unr', 'bytes' => $payload, 'flags' => 0],
            ['path' => '..\\evil.utx', 'bytes' => 'NO_TRAVERSAL', 'flags' => 0],
        ]);

        $config = [
            'storage_path' => $directory,
            'archive' => [
                'max_entries' => 20,
                'max_directory_bytes' => 1024 * 1024,
                'max_unpacked_bytes' => 1024 * 1024,
            ],
        ];
        $reader = new CatalogUmodArchiveReader($config);
        $entries = $reader->entries($fixture, basename($fixture));
        $valid = null;
        $unsafe = null;
        foreach ($entries as $entry) {
            if (($entry['path'] ?? '') === 'Maps/TestMap.unr') {
                $valid = $entry;
            }
            if (str_contains((string)($entry['path'] ?? ''), 'evil.utx')) {
                $unsafe = $entry;
            }
        }
        $record(
            $extension . '_native_listing',
            is_array($valid)
                && !empty($valid['safe'])
                && (int)($valid['size'] ?? -1) === strlen($payload)
                && (string)($valid['backend'] ?? '') === 'umod'
                && is_array($unsafe)
                && empty($unsafe['safe']),
            strtoupper($extension) . ' should list normalized members while rejecting traversal paths.'
        );

        $extractor = new CatalogArchiveExtractor($config);
        $extractorEntries = $extractor->entries($fixture, basename($fixture));
        $extractorValid = null;
        foreach ($extractorEntries as $entry) {
            if (($entry['path'] ?? '') === 'Maps/TestMap.unr') {
                $extractorValid = $entry;
                break;
            }
        }
        if (!is_array($extractorValid)) {
            $record($extension . '_exact_member_extraction', false, 'Valid fixture member was not listed by shared extractor.');
        } else {
            $temporary = $extractor->extractToTemp(
                $fixture,
                basename($fixture),
                $extractorValid,
                1024 * 1024
            );
            $actual = @file_get_contents($temporary);
            @unlink($temporary);
            $record(
                $extension . '_exact_member_extraction',
                $actual === $payload,
                strtoupper($extension) . ' member bytes must be copied exactly by offset/size through the shared archive extractor.'
            );
        }

        $sequential = new CatalogSequentialArchiveReader($config);
        $record(
            $extension . '_bypasses_libarchive_sequential_reader',
            $sequential->shouldUse($fixture, basename($fixture)) === false,
            strtoupper($extension) . ' must never be sent to libarchive.'
        );
        @unlink($fixture);
    }
} catch (Throwable $error) {
    $record('umod_runtime_fixture', false, get_class($error) . ': ' . $error->getMessage());
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($directory);
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
