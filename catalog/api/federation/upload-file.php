<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

function upload_incoming_dir(array $config): string
{
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/federation/incoming';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create incoming folder: ' . $dir);
    }
    return $dir;
}

function upload_safe_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?? 'upload.bin';
    return $name !== '' ? $name : 'upload.bin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only paired children may upload files to this parent.'], 403);
    }

    $originalName = trim((string)($_SERVER['HTTP_X_UE_ORIGINAL_NAME'] ?? 'upload.bin'));
    $remoteFileId = (int)($_SERVER['HTTP_X_UE_REMOTE_FILE_ID'] ?? 0);
    $expectedMd5 = strtolower(trim((string)($_SERVER['HTTP_X_UE_MD5'] ?? '')));
    $expectedSha1 = strtolower(trim((string)($_SERVER['HTTP_X_UE_SHA1'] ?? '')));
    $expectedSize = (int)($_SERVER['HTTP_X_UE_FILE_SIZE'] ?? 0);

    $maxBytes = (int)(fed_setting($db, 'max_transfer_file_size_mb', '1024') ?: 1024) * 1024 * 1024;
    if (strlen($body) <= 0) {
        fed_json_response(['ok' => false, 'error' => 'Upload body is empty'], 400);
    }
    if (strlen($body) > $maxBytes) {
        fed_json_response(['ok' => false, 'error' => 'Upload exceeds max transfer size'], 413);
    }
    if ($expectedSize > 0 && $expectedSize !== strlen($body)) {
        fed_json_response(['ok' => false, 'error' => 'Upload size mismatch'], 400);
    }

    $md5 = md5($body);
    $sha1 = sha1($body);
    if ($expectedMd5 !== '' && !hash_equals($expectedMd5, $md5)) {
        fed_json_response(['ok' => false, 'error' => 'MD5 mismatch'], 400);
    }
    if ($expectedSha1 !== '' && !hash_equals($expectedSha1, $sha1)) {
        fed_json_response(['ok' => false, 'error' => 'SHA1 mismatch'], 400);
    }

    $incoming = upload_incoming_dir($config);
    $safe = upload_safe_name('upload_peer_' . (int)$peer['id'] . '_remote_' . $remoteFileId . '_' . date('Ymd_His') . '_' . $originalName);
    $path = $incoming . '/' . $safe;
    if (file_put_contents($path, $body, LOCK_EX) === false) {
        throw new RuntimeException('Could not write uploaded file.');
    }
    unset($body);

    $relativeIncoming = 'storage/federation/incoming/' . $safe;
    $stmt = $db->prepare('INSERT INTO ue_federation_transfer_jobs(peer_id,direction,remote_file_id,status,bytes_total,bytes_done,incoming_path,downloaded_md5,downloaded_sha1,finished_at,last_error) VALUES(?,"upload_to_parent",?,"downloaded",?,?,?,?,NOW(),?)');
    $stmt->execute([(int)$peer['id'], $remoteFileId > 0 ? $remoteFileId : null, filesize($path), filesize($path), $relativeIncoming, $md5, $sha1, 'Received upload from child: ' . $originalName]);
    $jobId = (int)$db->lastInsertId();

    fed_log($db, (int)$peer['id'], $jobId, 'INFO', 'UPLOAD_RECEIVED', 'Received upload ' . $originalName . ' as ' . $safe);
    fed_json_response(['ok' => true, 'job_id' => $jobId, 'status' => 'downloaded', 'incoming_path' => $relativeIncoming, 'md5' => $md5, 'sha1' => $sha1, 'bytes' => filesize($path)]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
