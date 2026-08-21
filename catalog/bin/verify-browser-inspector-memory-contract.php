#!/usr/bin/env php
<?php
/** Read-only verifier for Upload Bucket browser inspector memory behaviour. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$workerPath = $root . '/assets/upload-file-inspector-worker.js';
$worker = (string)@file_get_contents($workerPath);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'md5_reuses_block_workspace',
    str_contains($worker, 'this.words = new Int32Array(16);')
        && str_contains($worker, 'const words = this.words;')
        && !str_contains($worker, 'process(block) {\n        const words = new Int32Array(16);'),
    'MD5 must not allocate a new 16-word typed array for every 64-byte block.'
);
$record(
    'sha1_reuses_block_workspace',
    str_contains($worker, 'this.words = new Int32Array(80);')
        && !str_contains($worker, 'process(block) {\n        const words = new Int32Array(80);'),
    'SHA-1 must not allocate a new 80-word typed array for every 64-byte block.'
);
$record(
    'md5_tables_are_precomputed_once',
    str_contains($worker, 'const MD5_SHIFTS = new Uint8Array([')
        && str_contains($worker, 'const MD5_CONSTANTS = new Int32Array(64);')
        && str_contains($worker, 'MD5_CONSTANTS[index] = Math.floor(Math.abs(Math.sin(index + 1))')
        && !str_contains($worker, 'const shifts = ['),
    'MD5 shift/constant tables must be created once per worker, not once per hash block.'
);
$record(
    'file_reads_remain_chunk_bounded',
    str_contains($worker, 'const HASH_CHUNK_BYTES = 4 * 1024 * 1024;')
        && str_contains($worker, 'const end = Math.min(total, done + HASH_CHUNK_BYTES);')
        && str_contains($worker, 'await readBytes(file, done, end)'),
    'Hashing must continue to read one bounded file chunk at a time.'
);

$node = getenv('NODE_BINARY') ?: 'node';
$nodeCheck = ['ok' => false, 'detail' => 'node --check unavailable'];
if (function_exists('proc_open')) {
    $pipes = [];
    $process = @proc_open([$node, '--check', $workerPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $nodeCheck = ['ok' => $code === 0, 'detail' => trim($stdout . "\n" . $stderr)];
    }
}
$record('javascript_syntax', $nodeCheck['ok'], $nodeCheck['detail']);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
