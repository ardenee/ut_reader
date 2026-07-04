<?php
declare(strict_types=1);

require_once __DIR__ . '/FederationWorker.php';
require_once __DIR__ . '/FederationTransferAuth.php';

function federation_streaming_claim_transfer(PDO $db): ?array
{
    $db->beginTransaction();
    try {
        $sql = 'SELECT j.*, p.site_name peer_name, p.site_url, p.peer_site_id, p.shared_secret_plain '
            . 'FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id '
            . 'WHERE j.status="queued" AND j.direction IN ("parent_pull_from_child","download_from_parent","upload_to_parent") '
            . 'AND p.is_active=1 ORDER BY j.created_at ASC LIMIT 1 FOR UPDATE';
        $job = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            $db->commit();
            return null;
        }
        $stmt = $db->prepare('UPDATE ue_federation_transfer_jobs SET status="running", started_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=? AND status="queued"');
        $stmt->execute([(int)$job['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Transfer claim was lost.');
        }
        $db->commit();
        return $job;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function federation_streaming_transfer_limit(PDO $db): int
{
    return max(1, min(1024 * 1024 * 1024 * 8, (int)(fed_setting($db, 'max_transfer_file_size_mb', '1024') ?: 1024) * 1024 * 1024));
}

function federation_streaming_update_progress(PDO $db, int $jobId, int $bytes, int &$lastBytes, int &$lastTime): void
{
    $now = time();
    if (($bytes - $lastBytes) < 1048576 && ($now - $lastTime) < 2) return;
    $db->prepare('UPDATE ue_federation_transfer_jobs SET bytes_done=? WHERE id=? AND status="running"')->execute([$bytes, $jobId]);
    $lastBytes = $bytes;
    $lastTime = $now;
}

function federation_streaming_json_response(string $response, int $status, string $operation): array
{
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException($operation . ' remote endpoint returned HTTP ' . $status . '.');
    }
    $json = json_decode($response, true);
    if (!is_array($json) || empty($json['ok'])) {
        throw new RuntimeException($operation . ' remote endpoint rejected the transfer.');
    }
    return $json;
}

function federation_streaming_upload(PDO $db, array $config, array $job): array
{
    if (!extension_loaded('curl')) throw new RuntimeException('Federation streaming requires PHP cURL.');
    $jobId = (int)$job['id'];
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [(int)$job['local_file_id']]);
    if (!$file) throw new RuntimeException('Local verified file was not found.');
    $path = federation_worker_file_path($config, $file);
    $bytes = (int)(filesize($path) ?: 0);
    $max = federation_streaming_transfer_limit($db);
    if ($bytes < 1 || $bytes > $max) throw new RuntimeException('Upload file exceeds the configured transfer limit.');
    $sha256 = hash_file('sha256', $path);
    $md5 = hash_file('md5', $path);
    $sha1 = hash_file('sha1', $path);
    if (!$sha256 || !$md5 || !$sha1) throw new RuntimeException('Could not hash upload file.');
    $url = rtrim((string)$job['site_url'], '/') . '/api/federation/upload-file.php';
    $timestamp = date('c'); $nonce = fed_random_secret(); $requestPath = parse_url($url, PHP_URL_PATH) ?: '/';
    $name = (string)$file['original_name'];
    $signature = fed_transfer_signature((string)$job['shared_secret_plain'], 'PUT', $requestPath, $timestamp, $nonce, $sha256, $bytes, (int)$file['id'], $name);
    $in = fopen($path, 'rb');
    if ($in === false) throw new RuntimeException('Could not open local upload file.');
    $sent = 0; $lastBytes = 0; $lastTime = time();
    $curl = curl_init($url);
    if ($curl === false) { fclose($in); throw new RuntimeException('Could not initialize upload request.'); }
    try {
        curl_setopt_array($curl, [
            CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => $bytes,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 0, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Accept: application/json', 'User-Agent: UnrealDB-Federation/2.0',
                'X-Site-Id: ' . fed_setting($db, 'site_id', ''), 'X-Timestamp: ' . $timestamp, 'X-Nonce: ' . $nonce, 'X-Signature: ' . $signature,
                'X-UE-Original-Name: ' . $name, 'X-UE-Remote-File-Id: ' . (int)$file['id'], 'X-UE-File-Size: ' . $bytes,
                'X-UE-MD5: ' . $md5, 'X-UE-SHA1: ' . $sha1, 'X-UE-SHA256: ' . $sha256],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($h, int $totalDown, int $nowDown, int $totalUp, int $nowUp) use ($db, $jobId, &$sent, &$lastBytes, &$lastTime): int {
                $sent = max($sent, $nowUp); federation_streaming_update_progress($db, $jobId, $sent, $lastBytes, $lastTime); return 0;
            },
        ]);
        $response = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl);
        if ($response === false) throw new RuntimeException('Upload request failed' . ($error !== '' ? ': ' . $error : '.'));
        $json = federation_streaming_json_response((string)$response, $status, 'Upload');
    } finally { curl_close($curl); fclose($in); }
    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="imported", bytes_done=?, downloaded_md5=?, downloaded_sha1=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$bytes, $md5, $sha1, 'Uploaded with streamed SHA-256 verification; parent job ID ' . ($json['job_id'] ?? ''), $jobId]);
    fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'UPLOAD_TO_PARENT_DONE', 'Streamed local file ID ' . (int)$file['id'] . ' to parent.');
    if ((int)$job['wait_after_seconds'] > 0) sleep((int)$job['wait_after_seconds']);
    return ['ok' => true, 'job_id' => $jobId, 'direction' => 'upload_to_parent', 'bytes' => $bytes, 'sha256' => $sha256];
}

function federation_streaming_download(PDO $db, array $config, array $job): array
{
    if (!extension_loaded('curl')) throw new RuntimeException('Federation streaming requires PHP cURL.');
    $jobId = (int)$job['id'];
    [$url, $payload, $event] = federation_worker_download_info($job);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $timestamp = date('c'); $nonce = fed_random_secret(); $requestPath = parse_url($url, PHP_URL_PATH) ?: '/';
    $signature = fed_sign_request((string)$job['shared_secret_plain'], 'POST', $requestPath, $timestamp, $nonce, $body);
    $incoming = federation_worker_incoming_dir($config);
    $name = 'peer_' . (int)$job['peer_id'] . '_' . (string)$job['direction'] . '_remote_' . (int)$job['remote_file_id'] . '_item_' . (int)($job['remote_request_item_id'] ?? 0) . '_' . date('Ymd_His') . '.bin';
    $dest = $incoming . '/' . federation_worker_safe_name($name); $part = $dest . '.part';
    $out = fopen($part, 'xb'); if ($out === false) throw new RuntimeException('Could not create incoming transfer file.');
    $bytes = 0; $lastBytes = 0; $lastTime = time(); $declared = null; $max = federation_streaming_transfer_limit($db);
    $curl = curl_init($url); if ($curl === false) { fclose($out); throw new RuntimeException('Could not initialize download request.'); }
    try {
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 0, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/octet-stream', 'User-Agent: UnrealDB-Federation/2.0', 'X-Site-Id: ' . fed_setting($db, 'site_id', ''), 'X-Timestamp: ' . $timestamp, 'X-Nonce: ' . $nonce, 'X-Signature: ' . $signature],
            CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$declared, $max): int { if (stripos($line, 'Content-Length:') === 0) { $v = trim(substr($line, 15)); $declared = ctype_digit($v) ? (int)$v : null; if ($declared !== null && $declared > $max) return 0; } return strlen($line); },
            CURLOPT_WRITEFUNCTION => static function ($h, string $chunk) use ($out, &$bytes, $max, $db, $jobId, &$lastBytes, &$lastTime): int { $len = strlen($chunk); if ($bytes + $len > $max || fwrite($out, $chunk) !== $len) return 0; $bytes += $len; federation_streaming_update_progress($db, $jobId, $bytes, $lastBytes, $lastTime); return $len; },
        ]);
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl);
        if ($ok === false || $status !== 200 || ($declared !== null && $declared !== $bytes)) throw new RuntimeException('Download request failed' . ($status ? ' with HTTP ' . $status : '') . ($error !== '' ? ': ' . $error : '.'));
    } catch (Throwable $e) { @unlink($part); throw $e; } finally { curl_close($curl); fclose($out); }
    if (!rename($part, $dest)) { @unlink($part); throw new RuntimeException('Could not finalize downloaded transfer.'); }
    $md5 = md5_file($dest) ?: ''; $sha1 = sha1_file($dest) ?: '';
    $relative = 'storage/federation/incoming/' . basename($dest);
    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="downloaded", bytes_done=?, incoming_path=?, downloaded_md5=?, downloaded_sha1=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$bytes, $relative, $md5, $sha1, 'Downloaded with streamed bounded transfer: ' . basename($dest), $jobId]);
    fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', $event, 'Streamed remote file to ' . basename($dest));
    if ((int)$job['wait_after_seconds'] > 0) sleep((int)$job['wait_after_seconds']);
    return ['ok' => true, 'job_id' => $jobId, 'direction' => (string)$job['direction'], 'file' => basename($dest), 'bytes' => $bytes, 'md5' => $md5];
}

function federation_streaming_run_one_transfer(PDO $db, array $config): array
{
    $job = federation_streaming_claim_transfer($db);
    if (!$job) return ['ok' => true, 'skipped' => true, 'message' => 'No queued transfer jobs.'];
    try { return (string)$job['direction'] === 'upload_to_parent' ? federation_streaming_upload($db, $config, $job) : federation_streaming_download($db, $config, $job); }
    catch (Throwable $e) { $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed", finished_at=NOW(), last_error=? WHERE id=?')->execute([$e->getMessage(), (int)$job['id']]); fed_log($db, (int)$job['peer_id'], (int)$job['id'], 'ERROR', 'TRANSFER_FAIL', $e->getMessage()); throw $e; }
}
