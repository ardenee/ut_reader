<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/FederationTransferAuth.php';

function upload_incoming_dir(array $config): string
{
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/federation/incoming';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create incoming folder.');
    return $dir;
}
function upload_safe_name(string $name): string
{
    return preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?: 'upload.bin';
}
function upload_stream(string $path, int $expected, string $expectedHash, int $limit): array
{
    $in = fopen('php://input', 'rb');
    $out = fopen($path, 'xb');
    if (!$in || !$out) throw new RuntimeException('Could not open upload stream.');
    $bytes = 0; $md5 = hash_init('md5'); $sha1 = hash_init('sha1'); $sha256 = hash_init('sha256');
    try {
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) throw new RuntimeException('Upload stream read failed.');
            if ($chunk === '') continue;
            $bytes += strlen($chunk);
            if ($bytes > $limit || fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Upload stream write failed.');
            hash_update($md5, $chunk); hash_update($sha1, $chunk); hash_update($sha256, $chunk);
        }
    } finally { fclose($in); fclose($out); }
    $hash = hash_final($sha256);
    if ($bytes !== $expected || !hash_equals($expectedHash, $hash)) { @unlink($path); throw new RuntimeException('Upload integrity verification failed.'); }
    return [$bytes, hash_final($md5), hash_final($sha1), $hash];
}

try {
    $config = catalog_config(); $db = catalog_db($config);
    [$peer, $meta] = fed_require_streaming_upload_peer($db);
    if ((string)$peer['peer_role'] !== 'child') fed_json_response(['ok' => false, 'error' => 'Only paired children may upload to this parent.'], 403);
    $max = (int)(fed_setting($db, 'max_transfer_file_size_mb', '1024') ?: 1024) * 1024 * 1024;
    if ($meta['bytes'] > $max) fed_json_response(['ok' => false, 'error' => 'Upload exceeds max transfer size'], 413);
    $safe = upload_safe_name('upload_peer_' . (int)$peer['id'] . '_remote_' . $meta['remote_id'] . '_' . date('Ymd_His') . '_' . $meta['name']);
    $path = upload_incoming_dir($config) . '/' . $safe;
    [$bytes, $md5, $sha1, $sha256] = upload_stream($path . '.part', $meta['bytes'], $meta['sha256'], $max);
    if (!rename($path . '.part', $path)) { @unlink($path . '.part'); throw new RuntimeException('Could not finalize verified upload.'); }
    $relative = 'storage/federation/incoming/' . $safe;
    $sql = 'INSERT INTO ue_federation_transfer_jobs(peer_id,direction,remote_file_id,status,bytes_total,bytes_done,incoming_path,downloaded_md5,downloaded_sha1,finished_at,last_error) VALUES(?,"upload_to_parent",?,"downloaded",?,?,?,?,NOW(),?)';
    $db->prepare($sql)->execute([(int)$peer['id'], $meta['remote_id'] ?: null, $bytes, $bytes, $relative, $md5, $sha1, 'Received SHA-256 verified upload from child: ' . $meta['name']]);
    $jobId = (int)$db->lastInsertId();
    fed_log($db, (int)$peer['id'], $jobId, 'INFO', 'UPLOAD_RECEIVED', 'Received verified streaming upload as ' . $safe);
    fed_json_response(['ok' => true, 'job_id' => $jobId, 'status' => 'downloaded', 'bytes' => $bytes, 'sha256' => $sha256]);
} catch (Throwable $e) {
    error_log('[UnrealDB federation upload] ' . get_class($e) . ': ' . $e->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Upload could not be completed.'], 500);
}
