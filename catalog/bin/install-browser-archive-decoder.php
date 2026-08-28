#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
const SOURCE_URL = 'https://raw.githubusercontent.com/use-strict/7z-wasm/521d2cf93f5964f4e77b01049e19f1b29305c454/7zz.wasm';
const EXPECTED_BYTES = 1651931;
const EXPECTED_GIT_BLOB_SHA1 = '337cfa5ac2e9ed01d9dfc5b9aeb8f2742e025502';
$target = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'vendor'
    . DIRECTORY_SEPARATOR . '7z-wasm' . DIRECTORY_SEPARATOR . '7zz.wasm';
$directory = dirname($target);
$gitBlobSha = static function (string $path): string {
    $size = @filesize($path);
    if ($size === false) return '';
    $context = hash_init('sha1');
    hash_update($context, 'blob ' . (int)$size . "\0");
    $input = @fopen($path, 'rb');
    if (!is_resource($input)) return '';
    try {
        while (!feof($input)) {
            $buffer = fread($input, 1024 * 1024);
            if (!is_string($buffer)) return '';
            if ($buffer === '') { if (feof($input)) break; return ''; }
            hash_update($context, $buffer);
        }
    } finally { fclose($input); }
    return hash_final($context);
};
if (is_file($target) && (int)(filesize($target) ?: 0) === EXPECTED_BYTES
    && hash_equals(EXPECTED_GIT_BLOB_SHA1, $gitBlobSha($target))) {
    fwrite(STDOUT, "Browser archive decoder already installed and verified: {$target}\n"); exit(0);
}
if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
    fwrite(STDERR, "Could not create vendor directory: {$directory}\n"); exit(1);
}
$temp = $target . '.download-' . bin2hex(random_bytes(6));
$downloaded = false;
$downloadErrors = [];

// Prefer PHP's HTTPS stream first. On Windows this can use a correctly configured
// OpenSSL CA source even when the cURL extension has no CA bundle configured.
$context = stream_context_create([
    'http' => [
        'follow_location' => 1,
        'timeout' => 180,
        'user_agent' => 'UnrealDB browser archive decoder installer',
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);
$input = @fopen(SOURCE_URL, 'rb', false, $context);
$output = @fopen($temp, 'wb');
if (is_resource($input) && is_resource($output)) {
    $copied = stream_copy_to_stream($input, $output);
    $downloaded = is_int($copied) && $copied > 0;
} else {
    $downloadErrors[] = 'HTTPS stream download failed.';
}
if (is_resource($input)) fclose($input);
if (is_resource($output)) fclose($output);

if (!$downloaded && function_exists('curl_init')) {
    @unlink($temp);
    $output = @fopen($temp, 'wb');
    if (is_resource($output)) {
        $curl = curl_init(SOURCE_URL);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_FILE => $output,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_USERAGENT => 'UnrealDB browser archive decoder installer',
            ]);
            $downloaded = curl_exec($curl) === true;
            if (!$downloaded) {
                $downloadErrors[] = 'cURL download failed: ' . curl_error($curl);
            }
            // PHP 8.0+ releases CurlHandle automatically; curl_close() is deprecated in PHP 8.5.
            unset($curl);
        }
        fclose($output);
    }
}
if (!$downloaded || !is_file($temp)) {
    @unlink($temp);
    fwrite(STDERR, "Could not download the pinned 7-Zip WASM binary.\n");
    foreach ($downloadErrors as $downloadError) {
        fwrite(STDERR, $downloadError . "\n");
    }
    exit(1);
}
$size = (int)(filesize($temp) ?: 0);
$blobSha = $gitBlobSha($temp);
if ($size !== EXPECTED_BYTES || !hash_equals(EXPECTED_GIT_BLOB_SHA1, $blobSha)) {
    @unlink($temp);
    fwrite(STDERR, "Decoder verification failed: bytes={$size}, git_blob_sha1={$blobSha}.\n"); exit(1);
}
if (is_file($target) && !@unlink($target)) {
    @unlink($temp); fwrite(STDERR, "Could not replace existing decoder: {$target}\n"); exit(1);
}
if (!@rename($temp, $target)) {
    @unlink($temp); fwrite(STDERR, "Could not publish decoder: {$target}\n"); exit(1);
}
fwrite(STDOUT, "Installed browser archive decoder: {$target}\nbytes=" . EXPECTED_BYTES
    . ', git_blob_sha1=' . EXPECTED_GIT_BLOB_SHA1 . "\n");
