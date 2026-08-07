<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Generated package unavailable.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/DownloadActivity.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;

/** @return array{start:int,end:int,length:int,partial:bool} */
function catalog_generated_package_range(string $header, int $size): array
{
    if ($size < 1 || trim($header) === '') {
        return ['start' => 0, 'end' => max(0, $size - 1), 'length' => $size, 'partial' => false];
    }

    $header = trim($header);
    if (!preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $matches)) {
        throw new RuntimeException('Only one valid byte range may be requested.');
    }

    $first = (string)$matches[1];
    $last = (string)$matches[2];
    if ($first === '' && $last === '') {
        throw new RuntimeException('The requested byte range is empty.');
    }

    if ($first === '') {
        $suffix = (int)$last;
        if ($suffix < 1) {
            throw new RuntimeException('The requested suffix range is invalid.');
        }
        $length = min($size, $suffix);
        $start = $size - $length;
        $end = $size - 1;
    } else {
        $start = (int)$first;
        $end = $last === '' ? $size - 1 : min($size - 1, (int)$last);
        if ($start < 0 || $start >= $size || $end < $start) {
            throw new RuntimeException('The requested byte range is outside the generated package.');
        }
        $length = $end - $start + 1;
    }

    return ['start' => $start, 'end' => $end, 'length' => $length, 'partial' => true];
}

function catalog_generated_package_prepare_binary_output(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Encoding');
    }
}

$auditId = null;
try {
    catalog_start_session();
    $config = catalog_config();
    $db = catalog_db($config);
    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    $token = (string)($_SESSION['generated_package_jobs'][(string)$jobId] ?? '');
    if ($jobId < 1 || $token === '') {
        throw new RuntimeException('This generated package is not available in the current browser session.');
    }

    $job = catalog_one(
        $db,
        'SELECT payload_json,result_json,status FROM ue_background_jobs WHERE id=? AND job_type=?',
        [$jobId, JobType::GENERATE_MOD_PACKAGE]
    );
    if (!$job || (string)$job['status'] !== 'completed') {
        throw new RuntimeException('The generated package is not ready.');
    }
    $payload = json_decode((string)$job['payload_json'], true);
    $expected = is_array($payload) ? (string)($payload['access_token_hash'] ?? '') : '';
    if ($expected === '' || !hash_equals($expected, hash('sha256', $token))) {
        throw new RuntimeException('This generated package is not authorized for the current browser session.');
    }

    $result = json_decode((string)$job['result_json'], true);
    if (!is_array($result)) {
        throw new RuntimeException('The generated package result is unavailable.');
    }
    $expires = strtotime((string)($result['expires_at'] ?? ''));
    $store = new GeneratedPackageStore((string)$config['storage_path']);
    $path = $store->resolve((string)($result['artifact_name'] ?? ''));
    if ($expires === false || $expires <= time()) {
        if ($path !== null) {
            $store->delete($path);
        }
        throw new RuntimeException('This generated package has expired. Build it again.');
    }
    if ($path === null) {
        throw new RuntimeException('The generated package artifact is missing. Build it again.');
    }

    $size = filesize($path);
    if ($size === false || (int)$size !== (int)($result['artifact_size'] ?? -1)) {
        throw new RuntimeException('The generated package artifact failed its size check.');
    }
    $expectedSha256 = strtolower(trim((string)($result['artifact_sha256'] ?? '')));
    $actualSha256 = hash_file('sha256', $path);
    if ($expectedSha256 === '' || !is_string($actualSha256) || !hash_equals($expectedSha256, strtolower($actualSha256))) {
        throw new RuntimeException('The generated package artifact failed its integrity check. Build it again.');
    }

    $etag = '"' . $actualSha256 . '"';
    $rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    $ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
    if ($ifRange !== '' && !hash_equals($etag, $ifRange)) {
        $rangeHeader = '';
    }

    try {
        $range = catalog_generated_package_range($rangeHeader, (int)$size);
    } catch (Throwable $rangeError) {
        http_response_code(416);
        header('Content-Range: bytes */' . (int)$size);
        throw $rangeError;
    }

    $downloadName = catalog_clean_unreal_filename((string)($result['download_name'] ?? basename($path)));
    $contentType = (string)($result['content_type'] ?? 'application/octet-stream');
    $speedBytes = catalog_public_download_speed_bytes($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $fileId = max(0, (int)($result['file_id'] ?? (is_array($payload) ? ($payload['file_id'] ?? 0) : 0)));
    $fileRow = $fileId > 0 ? catalog_one($db, 'SELECT game_id FROM ue_files WHERE id=?', [$fileId]) : null;
    $gameId = $fileRow ? (int)$fileRow['game_id'] : null;
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($method !== 'HEAD') {
        $auditId = catalog_download_audit_start($db, [
            'download_type' => 'generated_package',
            'file_id' => $fileId,
            'game_id' => $gameId,
            'job_id' => $jobId,
            'user_id' => $userId,
            'ip_address' => catalog_public_access_client_ip(),
            'user_agent' => catalog_download_audit_user_agent(),
            'download_name' => $downloadName,
            'package_format' => (string)($result['format'] ?? (is_array($payload) ? ($payload['format'] ?? '') : '')),
            'artifact_size' => (int)$size,
            'range_start' => $range['start'],
            'range_end' => $range['end'],
            'bytes_requested' => $range['length'],
            'status' => 'started',
            'http_status' => $range['partial'] ? 206 : 200,
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    catalog_generated_package_prepare_binary_output();
    if ($range['partial']) {
        http_response_code(206);
        header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . (int)$size);
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $range['length']);
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"")
        . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: private, no-store, no-transform');
    if ($speedBytes > 0) {
        header('X-UnrealDB-Rate-Limit: ' . $speedBytes . ' bytes/second');
    }

    if ($method === 'HEAD') {
        exit;
    }
    catalog_download_audit_stream($db, $auditId, $path, $range['start'], $range['length'], $speedBytes);
} catch (Throwable $error) {
    $status = http_response_code();
    if ($status < 400) {
        $status = str_contains(strtolower($error->getMessage()), 'expired') ? 410 : 404;
    }
    if (isset($db) && $db instanceof PDO) {
        catalog_download_audit_finish($db, $auditId, 'failed', 0, $error->getMessage(), $status);
    }
    http_response_code($status);
    if (!headers_sent()) {
        catalog_head('Generated package unavailable');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Generated package unavailable');
    echo '<p><a class="button" href="javascript:history.back()">Back</a></p>';
    catalog_foot();
}
