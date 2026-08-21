#!/usr/bin/env php
<?php
/** Read-only verifier for Upload Bucket client-side issue queue semantics. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/assets/upload-bucket-v2-issue-recorder.js';
$js = (string)@file_get_contents($path);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$record(
    'stale_v1_pending_queue_is_retired',
    str_contains($js, "const storageKey = 'unrealdb.uploadBucketV2.pendingIssues.v2';")
        && str_contains($js, "const legacyStorageKey = 'unrealdb.uploadBucketV2.pendingIssues';")
        && str_contains($js, 'window.localStorage.removeItem(legacyStorageKey);'),
    'Old browser diagnostic rows must not be replayed after the error-reporting protocol changes.'
);
$record(
    'concurrent_enqueue_is_drained',
    str_contains($js, 'if (loadPending().length) schedulePendingFlush(500);')
        && str_contains($js, 'schedulePendingFlush(0);'),
    'Failures queued while an async flush is active must trigger another bounded pass automatically.'
);
$record(
    'new_issues_capture_client_occurrence_time',
    str_contains($js, 'occurred_at: String(payload && payload.occurred_at ? payload.occurred_at : new Date().toISOString())'),
    'Diagnostic payloads must carry their browser occurrence time instead of relying only on later persistence time.'
);

$node = getenv('NODE_BINARY') ?: 'node';
$syntaxOk = false;
$syntaxDetail = 'node --check unavailable';
if (function_exists('proc_open')) {
    $pipes = [];
    $process = @proc_open([$node, '--check', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $syntaxOk = $code === 0;
        $syntaxDetail = trim($stdout . "\n" . $stderr);
    }
}
$record('javascript_syntax', $syntaxOk, $syntaxDetail);

echo json_encode(['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
