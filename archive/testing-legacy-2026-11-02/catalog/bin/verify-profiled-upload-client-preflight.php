#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies Profiled Upload client-side duplicate preflight without performing uploads or changing database state.
 * Role: Read-only regression gate for advisory browser hashing, same-batch dedupe and authoritative server hashing.
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
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'profiled-upload.php',
    'api/v1/profiled-upload-preflight.php',
    'src/Infrastructure/Import/CatalogProfiledUploadDuplicatePreflight.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
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

$page = $read('profiled-upload.php');
$client = $read('assets/profiled-upload-jobs.js');
$worker = $read('assets/profiled-upload-hash-worker.js');
$endpoint = $read('api/v1/profiled-upload-preflight.php');
$service = $read('src/Infrastructure/Import/CatalogProfiledUploadDuplicatePreflight.php');
$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');

$record(
    'preflight_is_exposed_with_dedicated_csrf',
    str_contains($page, 'data-preflight-url="api/v1/profiled-upload-preflight.php"')
        && str_contains($page, "catalog_csrf('profiled_upload_preflight')")
        && str_contains($endpoint, "catalog_api_require_csrf('profiled_upload_preflight')")
        && str_contains($endpoint, 'catalog_api_require_admin(false)'),
    'The advisory hash lookup must use its own authenticated, CSRF-protected endpoint.'
);

$record(
    'preflight_lookup_is_hash_size_game_only',
    str_contains($service, 'WHERE sha1=? AND game_id=? AND file_size=? AND scan_status="verified"')
        && !str_contains($service, 'original_name=?')
        && !str_contains($service, 'extension=?'),
    'Duplicate preflight must not depend on filename/extension; it matches selected game + SHA-1 + exact byte size.'
);

$record(
    'browser_hash_runs_in_worker_one_file_at_a_time',
    str_contains($client, 'new Worker(hashWorkerUrl)')
        && str_contains($client, 'await duplicatePreflight(file, index, total)')
        && str_contains($worker, 'const CHUNK_BYTES = 4 * 1024 * 1024')
        && str_contains($worker, 'file.slice(loaded, end).arrayBuffer()')
        && str_contains($worker, "type: 'progress'"),
    'Ordinary-file hashing must be off the UI thread, streaming in bounded chunks with progress.'
);

$record(
    'client_sha1_has_runtime_self_test',
    str_contains($worker, "const SHA1_ABC = 'a9993e364706816aba3e25717850c26c9cd0d89d'")
        && str_contains($worker, 'function verifySha1Implementation()')
        && str_contains($worker, 'if (!verifySha1Implementation())'),
    'A wrong browser SHA-1 implementation must disable preflight instead of risking a false duplicate skip.'
);

$record(
    'redirects_and_paks_bypass_package_preflight',
    str_contains($client, 'function isRedirectWrapper(file)')
        && str_contains($client, '!isPak(file) && !isRedirectWrapper(file)'),
    'Compressed redirect/container bytes must not be compared with catalogued decompressed package hashes.'
);

$record(
    'same_batch_duplicates_skip_after_first_stages',
    str_contains($client, 'stagedHashes.has(hashKey)')
        && str_contains($client, 'stagedHashes.set(preflight.hashKey, name)')
        && str_contains($client, 'if (staged && preflight.hashKey)'),
    'The second identical file in one selected batch may be skipped only after the first copy was durably staged.'
);

$record(
    'preflight_failure_is_fail_open',
    str_contains($client, 'Upload will continue normally; server-side duplicate detection remains authoritative.')
        && str_contains($client, 'return {skip: false, hashKey: hashKey}')
        && str_contains($client, 'return {skip: false, hashKey: \'\'}'),
    'A worker/API preflight failure must never prevent upload.'
);

$record(
    'server_hashing_remains_authoritative',
    str_contains($importer, '$md5 = md5_file($temporaryPath)')
        && str_contains($importer, '$sha1 = sha1_file($temporaryPath)')
        && str_contains($endpoint, "'advisory_only' => true")
        && str_contains($endpoint, "'authoritative_hashing' => 'background_worker'"),
    'Browser hashes are an optimization only; uploaded bytes must still be hashed by the worker before catalog acceptance.'
);

$record(
    'preflight_does_not_restore_background_waiting',
    !str_contains($client, 'waitForJob(')
        && !str_contains($client, 'job-status.php')
        && str_contains($client, 'await releaseBatch();'),
    'Client preflight must not reintroduce waiting for decompression/import jobs between uploads.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
