<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;

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

    $downloadName = catalog_clean_unreal_filename((string)($result['download_name'] ?? basename($path)));
    $contentType = (string)($result['content_type'] ?? 'application/octet-stream');
    $speedBytes = catalog_public_download_speed_bytes($db);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (int)$size);
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"")
        . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($speedBytes > 0) {
        header('X-UnrealDB-Rate-Limit: ' . $speedBytes . ' bytes/second');
    }
    catalog_public_stream_file($path, $speedBytes);
} catch (Throwable $error) {
    $status = http_response_code();
    if ($status < 400) {
        $status = str_contains(strtolower($error->getMessage()), 'expired') ? 410 : 404;
    }
    http_response_code($status);
    if (!headers_sent()) {
        catalog_head('Generated package unavailable');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Generated package unavailable');
    echo '<p><a class="button" href="javascript:history.back()">Back</a></p>';
    catalog_foot();
}
