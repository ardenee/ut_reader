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
$externalPath = $root . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php';
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$workerPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';
$reader = (string)@file_get_contents($readerPath);
$external = (string)@file_get_contents($externalPath);
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
foreach ([$readerPath, $externalPath, $handlerPath, $workerPath] as $path) {
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
    'libarchive_stream_warning_is_preserved',
    str_contains($reader, 'set_error_handler(static function (int $severity, string $message) use (&$warning): bool')
        && str_contains($reader, 'Decoder: ' . "' . \$warning")
        && str_contains($reader, 'parsing filters is unsupported'),
    'fread(false) from ext-archive must retain the underlying libarchive warning so unsupported RAR filters are diagnosable.'
);

$record(
    'rar_capability_failure_can_fall_back_to_7zip',
    str_contains($reader, 'new CatalogExternalArchiveReader($this->config)')
        && str_contains($reader, '$external->isAvailable()')
        && str_contains($reader, '$external->walk(')
        && str_contains($reader, '__unrealdb_external_replay_skip')
        && str_contains($reader, 'rar-7zip-cli'),
    'When libarchive cannot decode a RAR member, the reader must be able to resume through 7-Zip without replaying already-completed callbacks.'
);

$record(
    'external_rar_reader_is_bounded_and_shell_free',
    str_contains($external, "[$binary, 'l', '-slt'")
        && str_contains($external, "[$binary, 'x', '-so'")
        && str_contains($external, "['bypass_shell' => true]")
        && str_contains($external, 'External RAR member exceeded its configured import limit')
        && str_contains($external, 'Archive contains too many entries')
        && str_contains($external, 'safeMemberPath('),
    'The optional 7-Zip fallback must use argument-array process execution and preserve path, entry-count and byte limits.'
);

$record(
    'external_rar_fallback_autodetects_windows_7zip',
    str_contains($external, "PHP_OS_FAMILY === 'Windows'")
        && str_contains($external, "'\\\\7-Zip\\\\'")
        && str_contains($external, 'UNREALDB_7ZIP_BINARY')
        && str_contains($external, "external_7zip_binary"),
    'Windows servers should use a normal 7-Zip installation automatically while retaining explicit configuration/environment overrides.'
);

$record(
    'missing_external_decoder_finishes_as_retained_partial',
    str_contains($reader, 'RAR solid archive support unavailable or RAR filter decoding unsupported')
        && str_contains($reader, 'external 7-Zip fallback unavailable')
        && str_contains($handler, 'isTerminalArchiveCapabilityFailure')
        && str_contains($handler, 'source archive retained'),
    'If neither decoder can handle the RAR, the immutable source must be retained as partial instead of burning three identical retries.'
);

$record(
    'worker_fingerprint_tracks_archive_decoders',
    str_contains($worker, '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php')
        && str_contains($worker, '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php'),
    'Changing either archive decoder path must invalidate detached workers.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
