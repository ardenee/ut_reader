#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for forward-only solid RAR/7z ingestion. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$readerPath = $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php';
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$workerPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';
$reader = (string)@file_get_contents($readerPath);
$handler = (string)@file_get_contents($handlerPath);
$worker = (string)@file_get_contents($workerPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$syntaxFailures = [];
foreach ([$readerPath, $handlerPath, $workerPath] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ' could not be linted';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$record(
    'rar_and_7z_use_forward_only_reader',
    str_contains($handler, 'new CatalogSequentialArchiveReader($this->config)')
        && str_contains($handler, '$sequential->shouldUse($sourcePath, $originalName)')
        && str_contains($handler, 'handleSequentialArchive(')
        && strpos($handler, '$sequential->shouldUse($sourcePath, $originalName)')
            < strpos($handler, '$extractor->entries($sourcePath, $originalName)'),
    'The coordinator must choose the sequential reader before any pre-list pass can trigger solid-archive data skipping.'
);

$record(
    'single_libarchive_handle_consumes_every_member',
    str_contains($reader, '$archive = $this->newArchive($archivePath, $format);')
        && str_contains($reader, 'foreach ($archive as $archiveEntry)')
        && str_contains($reader, '$input = $archive->currentEntryStream();')
        && str_contains($reader, '$this->consumeStream($input, $output, $streamLimit')
        && !str_contains($reader, 'extractCurrent('),
    'Solid traversal must keep one Archive object alive and fully read the current entry stream instead of reopening/extractCurrent per selected member.'
);

$record(
    'skipped_members_are_drained_not_seek_skipped',
    str_contains($reader, '$extract = (bool)$decision[\'extract\'];')
        && str_contains($reader, '$output = null;')
        && str_contains($reader, '$streamLimit = $extract ? min($entryLimit, $remainingTotal) : $remainingTotal;')
        && str_contains($reader, '$this->consumeStream($input, $output, $streamLimit'),
    'Unsupported or already-queued members must still be decoded/drained so the solid dictionary remains valid for later files.'
);

$record(
    'sequential_retry_restarts_and_dedupes_children',
    str_contains($handler, 'A sequential retry must begin at the archive start')
        && str_contains($handler, '$processed = 0;')
        && str_contains($handler, '$this->queuedChildExists($queueName, $dedupeKey)')
        && str_contains($handler, '\'archive-entry:\' . $jobId'),
    'A retry must rebuild decoder state from member zero while reusing already-created durable child jobs.'
);

$record(
    'sequential_reader_preserves_archive_limits',
    str_contains($reader, '$maxDecodedBytes - $decodedBytes')
        && str_contains($reader, 'Archive expansion exceeds the configured total unpacked-data limit')
        && str_contains($reader, 'Archive member exceeded its configured sequential decode limit')
        && str_contains($reader, 'safeMemberPath('),
    'Forward-only decoding must retain traversal, per-member and total expansion bounds plus path safety.'
);

$record(
    'solid_capability_error_is_only_terminal_fallback',
    str_contains($handler, 'even during sequential streaming')
        && str_contains($handler, 'isTerminalArchiveCapabilityFailure')
        && str_contains($handler, 'source archive retained'),
    'If the installed libarchive genuinely cannot decode a solid stream, the archive must finish visibly as partial only after the sequential strategy was attempted.'
);

$record(
    'worker_fingerprint_tracks_sequential_reader',
    str_contains($worker, '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php'),
    'Changing solid-archive streaming code must invalidate detached workers.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
