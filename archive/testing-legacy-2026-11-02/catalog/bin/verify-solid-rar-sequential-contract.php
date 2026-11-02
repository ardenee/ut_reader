#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for PHP-extension-only RAR/7z ingestion. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$readerPath = $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php';
$rarPath = $root . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php';
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$workerPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';
$reader = (string)@file_get_contents($readerPath);
$rar = (string)@file_get_contents($rarPath);
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
foreach ([$readerPath, $rarPath, $handlerPath, $workerPath] as $path) {
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
    'RAR/7z routing must reach the forward reader before any generic pre-list pass.'
);

$rarPrimary = strpos($reader, "if (\$format === 'rar' && class_exists(\\RarArchive::class))");
$libarchiveGate = strpos($reader, '$this->requireLibarchive($format);');
$record(
    'ext_rar_is_primary_rar_decoder',
    $rarPrimary !== false
        && $libarchiveGate !== false
        && $rarPrimary < $libarchiveGate
        && str_contains($reader, 'return (new CatalogExternalArchiveReader($this->config))->walk('),
    'When RarArchive is loaded, RAR must enter ext-rar from member zero instead of trying libarchive first.'
);

$record(
    'libarchive_reader_preserves_declared_size_boundary',
    str_contains($reader, 'while ($expectedBytes > 0 ? $written < $expectedBytes : !feof($input))')
        && str_contains($reader, '$readBytes = min($readBytes, $expectedBytes - $written)')
        && str_contains($reader, 'libarchive member stream stopped unexpectedly'),
    'The libarchive stream must stop at the declared member size and still report genuine early termination.'
);

$record(
    'libarchive_rar_directory_records_are_not_streamed',
    str_contains($reader, '$declaredSize !== null && (int)$declaredSize === 0 && !$extract')
        && str_contains($reader, '$complete($entry, null, $state);'),
    'The libarchive compatibility path must not open known zero-byte RAR directory records as data streams.'
);

$record(
    'libarchive_iterator_capability_failures_are_classified',
    str_contains($reader, "'error moving to next header'")
        && str_contains($reader, 'PHP rar extension (RarArchive) is not loaded in this worker process'),
    'A RAR capability failure raised while advancing the libarchive iterator must become a deterministic retained-capability result.'
);

$record(
    'libarchive_stream_warning_is_preserved',
    str_contains($reader, 'set_error_handler(static function (int $severity, string $message) use (&$warning): bool')
        && str_contains($reader, "'Could not read libarchive member stream.' . (\$warning !== '' ? ' Decoder: ' . \$warning : '')"),
    'When ext-archive reports decoder failure through a PHP warning plus fread(false), preserve the native decoder message.'
);

$record(
    'rar_recovery_uses_php_extension_only',
    str_contains($rar, 'class_exists(\\RarArchive::class)')
        && str_contains($rar, '\\RarArchive::open($archivePath)')
        && str_contains($rar, '$rarEntry->getStream()')
        && str_contains($rar, '$rarEntry->extract(\'\', $temporary)')
        && str_contains($rar, "'backend' => 'php-rar-extension'")
        && !str_contains($rar, 'proc_open(')
        && !str_contains($rar, 'shell_exec(')
        && !str_contains($rar, 'passthru(')
        && !str_contains($rar, '7z.exe')
        && !str_contains($rar, "['7z'")
        && !str_contains($rar, 'bypass_shell'),
    'RAR recovery must use the PECL rar PHP extension only; archive handling must never launch 7-Zip/unrar or any other executable.'
);

$record(
    'php_rar_zero_byte_stream_uses_native_extension_fallback',
    str_contains($rar, 'isImmediateStreamFailure($streamError)')
        && str_contains($rar, 'str_contains($message, \'after 0 bytes\')')
        && str_contains($rar, 'extractEntryToTemporary(')
        && str_contains($rar, '$rarEntry->extract(\'\', $temporary)')
        && str_contains($rar, 'RarEntry::extract() output size does not match its declared size'),
    'If ext-rar returns a readable handle but zero bytes for a non-empty member, retry only that member through RarEntry::extract() into controlled temporary storage and re-verify its size.'
);

$record(
    'php_rar_reader_preserves_archive_safety_bounds_without_entry_cap',
    !str_contains($rar, 'Archive contains too many entries')
        && !str_contains($rar, 'maxEntries()')
        && str_contains($rar, 'Archive expansion exceeds the configured total unpacked-data limit')
        && str_contains($rar, 'RAR member exceeded its configured import limit')
        && str_contains($rar, 'RarEntry::extract() exceeded the configured archive-member limit')
        && str_contains($rar, 'safeMemberPath('),
    'The PHP rar extension path must accept archives with any entry count while retaining path, per-member and total expansion bounds.'
);

$record(
    'missing_php_rar_extension_finishes_as_retained_partial',
    str_contains($reader, 'RAR solid archive support unavailable: installed PHP libarchive cannot decode this RAR feature')
        && str_contains($handler, 'isTerminalArchiveCapabilityFailure')
        && str_contains($handler, 'source archive retained'),
    'If ext-rar is absent and libarchive lacks a required RAR feature, retain the immutable archive instead of retrying identical bytes.'
);

$record(
    'sequential_restart_resumes_committed_cursor',
    str_contains($handler, '$resumeStage !== \'expand_archive_sequential\'')
        && str_contains($handler, '$resumeCursor = max(0, (int)($resume[\'entry_cursor\'] ?? 0));')
        && str_contains($handler, '$processed = $resumeCursor;')
        && str_contains($handler, "'kind' => 'resume_replay'")
        && str_contains($handler, "if (\$kind === 'resume_replay')")
        && str_contains($handler, 'Resuming sequential archive after ')
        && str_contains($handler, 'Sequential archive resume cursor exceeds the number of readable archive members')
        && str_contains($handler, '$this->queuedChildExists($queueName, $dedupeKey)')
        && str_contains($handler, '\'archive-entry:\' . $jobId'),
    'A process/server restart must restore the last fully committed sequential member and counters; decoder-prefix replay may rebuild solid state but must not reset progress or enqueue prior members again.'
);

$record(
    'terminal_archive_retry_does_not_skip_recovery_pass',
    str_contains($handler, '$resumeStage !== \'expand_archive_sequential\'')
        && str_contains($handler, "'stage' => 'complete'"),
    'Only an interrupted in-progress sequential expansion may resume its cursor; an operator retry of a terminal/partial archive must still perform a fresh recovery pass.'
);

$record(
    'worker_fingerprint_tracks_php_archive_readers',
    str_contains($worker, '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php')
        && str_contains($worker, '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php')
        && str_contains($worker, '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php'),
    'Changing the PHP archive decoder or archive coordinator path must invalidate detached workers.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
