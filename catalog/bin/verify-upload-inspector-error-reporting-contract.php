#!/usr/bin/env php
<?php
/** Read-only verifier for Upload Bucket browser inspector failure diagnostics. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$coordinatorPath = $root . '/assets/upload-bucket-v2-coordinator.js';
$coordinator = (string)@file_get_contents($coordinatorPath);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'worker_error_preserves_browser_detail',
    str_contains($coordinator, 'function inspectorWorkerError(event, relativePath)')
        && str_contains($coordinator, 'event.message')
        && str_contains($coordinator, 'event.filename')
        && str_contains($coordinator, 'event.lineno')
        && str_contains($coordinator, 'event.colno')
        && !str_contains($coordinator, "reject(new Error('The browser file-inspection worker failed.'))"),
    'Worker.onerror must preserve the browser supplied message and source location instead of replacing it with a generic failure.'
);

$record(
    'worker_message_decode_failure_is_distinct',
    str_contains($coordinator, 'worker.onmessageerror = function ()')
        && str_contains($coordinator, 'could not decode the File message'),
    'Structured-clone/message decoding failures must be distinguishable from worker runtime failures.'
);

$record(
    'worker_postmessage_clone_failure_is_caught',
    str_contains($coordinator, 'try {')
        && str_contains($coordinator, "worker.postMessage({type: 'inspect', id: requestId, file: file});")
        && str_contains($coordinator, 'Browser could not send ')
        && str_contains($coordinator, 'message-clone failure'),
    'A synchronous File structured-clone failure must be reported with the affected path rather than escaping the preflight promise.'
);

$node = getenv('NODE_BINARY') ?: 'node';
$syntaxOk = false;
$syntaxDetail = 'node --check unavailable';
if (function_exists('proc_open')) {
    $pipes = [];
    $process = @proc_open([$node, '--check', $coordinatorPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
