#!/usr/bin/env php
<?php
/** Read-only source/runtime contract for archive/package failure classification and retry hygiene. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$inspectorPath = $root . '/assets/upload-file-inspector-worker-compatible.js';
$extractorPath = $root . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php';
$archiveHandlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$memberHandlerPath = $root . '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php';
$leaseStorePath = $root . '/src/Infrastructure/Persistence/PdoJobLeaseStore.php';
$statsPath = $root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php';
$workerVersionPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';

$inspector = (string)@file_get_contents($inspectorPath);
$extractor = (string)@file_get_contents($extractorPath);
$archiveHandler = (string)@file_get_contents($archiveHandlerPath);
$memberHandler = (string)@file_get_contents($memberHandlerPath);
$leaseStore = (string)@file_get_contents($leaseStorePath);
$stats = (string)@file_get_contents($statsPath);
$workerVersion = (string)@file_get_contents($workerVersionPath);

$record(
    'browser_archive_preflight_does_not_require_signature_at_byte_zero',
    str_contains($inspector, 'ARCHIVE_SNIFF_BYTES = 64 * 1024')
        && str_contains($inspector, 'indexOfSequence(')
        && str_contains($inspector, 'server parser will validate the complete container')
        && !str_contains($inspector, 'The .zip file does not contain a ZIP signature.'),
    'Allowed ZIP/7z/RAR files must reach authoritative server parsing even when a signature is offset, outside the bounded sniff, or the filename suffix is wrong.'
);

$record(
    'server_archive_format_is_content_driven',
    str_contains($extractor, 'detectArchiveFormat(')
        && str_contains($extractor, 'ZipArchive is authoritative for ZIP')
        && str_contains($extractor, "'format' => \$extension")
        && str_contains($extractor, "'format' => 'zip'")
        && str_contains($extractor, 'formatDiagnostic('),
    'Server-side archive parsing must preserve the detected content format and provide first-byte diagnostics when complete parsers reject the container.'
);

$record(
    'solid_rar_capability_failure_is_terminal_and_source_retained',
    str_contains($archiveHandler, 'isTerminalArchiveCapabilityFailure')
        && str_contains($archiveHandler, 'rar solid archive support unavailable')
        && str_contains($archiveHandler, "'status' => 'partial'")
        && str_contains($archiveHandler, "'source_retained' => true")
        && str_contains($archiveHandler, 'solid RAR support is unavailable'),
    'A decoder that explicitly lacks solid-RAR support cannot succeed on retry; the job should finish visibly with its source retained instead of consuming three attempts.'
);

$record(
    'non_package_archive_members_are_rejected_once',
    str_contains($memberHandler, 'isDeterministicNonPackage')
        && str_contains($memberHandler, 'unreal package magic not found')
        && str_contains($memberHandler, "'status' => 'rejected'")
        && str_contains($memberHandler, 'firstBytesDiagnostic(')
        && str_contains($memberHandler, '$incoming->delete($stagedPath)')
        && str_contains($memberHandler, '$preparedStore->clear()'),
    'Archive members with a package extension but deterministic non-Unreal bytes must complete as rejected in one attempt, with byte diagnostics and durable staging cleanup.'
);

$record(
    'successful_retry_clears_resolved_last_error',
    str_contains($leaseStore, 'status="completed",result_json=?,dedupe_key=NULL,last_error=NULL'),
    'A job that succeeds after a transient retry must not continue displaying the previous retry exception as its current Error/result.'
);

$record(
    'game_stats_retry_transient_innodb_deadlocks_locally',
    str_contains($stats, 'isRetryableConcurrencyFailure')
        && str_contains($stats, "\$attempt <= 3")
        && str_contains($stats, 'usleep($attempt * 150000)')
        && str_contains($stats, "'40001'")
        && str_contains($stats, "'deadlock found'")
        && str_contains($stats, "'lock wait timeout'"),
    'Cached game-counter refresh should absorb short 1213/1205 conflicts locally instead of replaying the whole dependency job immediately.'
);

$record(
    'worker_fingerprint_tracks_failure_handling_runtime',
    str_contains($workerVersion, 'CatalogArchiveExtractor.php')
        && str_contains($workerVersion, 'CatalogArchiveImportJobHandler.php')
        && str_contains($workerVersion, 'CatalogBucketStagedPackageJobHandler.php')
        && str_contains($workerVersion, 'PdoJobLeaseStore.php')
        && str_contains($workerVersion, 'PdoGameCatalogStats.php'),
    'All changed worker/runtime collaborators must invalidate stale detached worker processes.'
);

$syntaxFailures = [];
foreach ([$extractorPath, $archiveHandlerPath, $memberHandlerPath, $leaseStorePath, $statsPath, $workerVersionPath] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
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

$node = '';
if (function_exists('shell_exec')) {
    $node = trim((string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}
if ($node !== '') {
    $nodePath = preg_split('/\R/', $node)[0] ?? 'node';
    $pipes = [];
    $process = @proc_open([$nodePath, '--check', $inspectorPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $record('browser_inspector_js_syntax', $exit === 0, trim((string)$stderr . ' ' . (string)$stdout));
    } else {
        $record('browser_inspector_js_syntax', false, 'Node process could not be started.');
    }
} else {
    $checks[] = ['check' => 'browser_inspector_js_syntax', 'ok' => true, 'detail' => 'Node.js unavailable; structural browser-worker checks passed.'];
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
