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
$uploadPagePath = $root . '/upload-bucket-v2.php';
$worker = (string)@file_get_contents($workerPath);
$uploadPage = (string)@file_get_contents($uploadPagePath);
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
    'hash_blocks_use_offsets_not_subarray_views',
    str_contains($worker, 'this.process(bytes, offset);')
        && str_contains($worker, 'process(block, blockOffset)')
        && str_contains($worker, 'const position = base + (index * 4);')
        && !str_contains($worker, 'this.process(bytes.subarray(offset, offset + 64));')
        && !str_contains($worker, 'this.process(finalBlock.subarray(offset, offset + 64));'),
    'The hot 64-byte loop must process the existing chunk by offset; a subarray view per hash block still creates millions of temporary objects on large files.'
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
$record(
    'worker_url_versions_compatibility_delegate_and_redirect_reader',
    str_contains($uploadPage, "\$delegatedInspectorPath = __DIR__ . '/assets/upload-file-inspector-worker.js';")
        && str_contains($uploadPage, "\$redirectReaderPath = __DIR__ . '/assets/unreal-redirect-reader.js';")
        && str_contains($uploadPage, '$workerScriptVersion = max(')
        && str_contains($uploadPage, 'filemtime($workerScriptPath)')
        && str_contains($uploadPage, 'filemtime($delegatedInspectorPath)')
        && str_contains($uploadPage, 'filemtime($redirectReaderPath)'),
    'Changing the delegated worker or shared redirect reader must change the compatibility-worker URL so Chrome cannot keep stale code cached.'
);

$node = getenv('NODE_BINARY') ?: 'node';
$nodeCheck = ['ok' => false, 'detail' => 'node --check unavailable'];
$hashCheck = ['ok' => false, 'detail' => 'node hash fixture unavailable'];
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

    $fixture = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const crypto = require('crypto');
const source = fs.readFileSync(process.argv[1], 'utf8');
const context = {
    Uint8Array,
    Int32Array,
    Math,
    Number,
    Set,
    Error,
    self: {addEventListener() {}, postMessage() {}}
};
vm.createContext(context);
vm.runInContext(source, context);
const result = vm.runInContext(`(() => {
    const input = new Uint8Array(4097);
    for (let index = 0; index < input.length; index++) input[index] = index & 255;
    const md5 = new Md5();
    const sha1 = new Sha1();
    md5.update(input.subarray(0, 17));
    md5.update(input.subarray(17, 2049));
    md5.update(input.subarray(2049));
    sha1.update(input.subarray(0, 17));
    sha1.update(input.subarray(17, 2049));
    sha1.update(input.subarray(2049));
    return {md5: md5.digestHex(), sha1: sha1.digestHex(), bytes: Array.from(input)};
})()`, context);
const buffer = Buffer.from(result.bytes);
const expectedMd5 = crypto.createHash('md5').update(buffer).digest('hex');
const expectedSha1 = crypto.createHash('sha1').update(buffer).digest('hex');
if (result.md5 !== expectedMd5 || result.sha1 !== expectedSha1) {
    console.error(JSON.stringify({result, expectedMd5, expectedSha1}));
    process.exit(2);
}
JS;
    $pipes = [];
    $process = @proc_open([$node, '-e', $fixture, $workerPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $hashCheck = ['ok' => $code === 0, 'detail' => trim($stdout . "\n" . $stderr)];
    }
}
$record('javascript_syntax', $nodeCheck['ok'], $nodeCheck['detail']);
$record(
    'hash_fixture_matches_node_crypto',
    $hashCheck['ok'],
    $hashCheck['detail'] !== '' ? $hashCheck['detail'] : 'MD5/SHA-1 multi-update fixture must match Node crypto.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
