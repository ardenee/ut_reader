<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/CatalogImport.php';
require_once __DIR__ . '/FederationInventory.php';

function federation_worker_incoming_dir(array $config): string
{
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/federation/incoming';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create federation incoming folder: ' . $dir);
    }
    return $dir;
}

function federation_worker_safe_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?? 'download.bin';
    return $name !== '' ? $name : 'download.bin';
}

function federation_worker_file_path(array $config, array $file): string
{
    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $path = realpath(__DIR__ . '/../' . (string)$file['relative_path']);
    if (!$root || !$path || !str_starts_with($path, $root) || !is_file($path)) {
        throw new RuntimeException('Stored local file missing or outside storage.');
    }
    return $path;
}

function federation_worker_signed_context(PDO $db, array $job, string $url, string $body, array $extraHeaders = []): array
{
    $secret = (string)$job['shared_secret_plain'];
    if ($secret === '') {
        throw new RuntimeException('Peer has no stored API secret.');
    }

    $timestamp = date('c');
    $nonce = fed_random_secret();
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $signature = fed_sign_request($secret, 'POST', $path, $timestamp, $nonce, $body);

    $headers = [
        'Content-Type: application/json',
        'User-Agent: UnrealFileCatalogFederation/1.0',
        'X-Site-Id: ' . fed_setting($db, 'site_id', ''),
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature: ' . $signature,
    ];
    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    return [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => 300,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
}

function federation_worker_download_info(array $job): array
{
    if ((string)$job['direction'] === 'parent_pull_from_child') {
        return [rtrim((string)$job['site_url'], '/') . '/api/federation/download-file.php', ['remote_file_id' => (int)$job['remote_file_id']], 'PARENT_PULL_DOWNLOADED'];
    }
    if ((string)$job['direction'] === 'download_from_parent') {
        return [rtrim((string)$job['site_url'], '/') . '/api/federation/download-approved-file.php', ['request_item_id' => (int)$job['remote_request_item_id']], 'CHILD_APPROVED_DOWNLOADED'];
    }
    throw new RuntimeException('Unsupported download direction: ' . (string)$job['direction']);
}

function federation_worker_run_one_download(PDO $db, array $config, array $job): array
{
    $jobId = (int)$job['id'];
    [$url, $payload, $logEvent] = federation_worker_download_info($job);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Could not encode federation payload.');
    }

    $remote = @fopen($url, 'rb', false, stream_context_create(federation_worker_signed_context($db, $job, $url, $body)));
    if (!$remote) {
        throw new RuntimeException('Could not open remote download stream.');
    }

    $meta = stream_get_meta_data($remote);
    $wrapper = $meta['wrapper_data'] ?? [];
    $statusLine = is_array($wrapper) ? (string)($wrapper[0] ?? '') : '';
    if ($statusLine !== '' && !str_contains($statusLine, ' 200 ')) {
        $err = stream_get_contents($remote);
        fclose($remote);
        throw new RuntimeException('Remote returned ' . $statusLine . ': ' . substr((string)$err, 0, 500));
    }

    $incoming = federation_worker_incoming_dir($config);
    $name = 'peer_' . (int)$job['peer_id'] . '_' . (string)$job['direction'] . '_remote_' . (int)$job['remote_file_id'] . '_item_' . (int)($job['remote_request_item_id'] ?? 0) . '_' . date('Ymd_His') . '.bin';
    $dest = $incoming . '/' . federation_worker_safe_name($name);
    $out = fopen($dest, 'wb');
    if (!$out) {
        fclose($remote);
        throw new RuntimeException('Could not open local incoming file for write.');
    }

    $bytes = 0;
    $limit = (int)$job['speed_limit_kbps'];
    while (!feof($remote)) {
        $chunk = fread($remote, 65536);
        if ($chunk === false) {
            throw new RuntimeException('Remote read failed.');
        }
        if ($chunk === '') {
            break;
        }
        fwrite($out, $chunk);
        $bytes += strlen($chunk);
        $db->prepare('UPDATE ue_federation_transfer_jobs SET bytes_done=? WHERE id=?')->execute([$bytes, $jobId]);
        if ($limit > 0) {
            usleep((int)max(0, (strlen($chunk) / max(1, $limit * 1024)) * 1000000));
        }
    }
    fclose($out);
    fclose($remote);

    $md5 = md5_file($dest) ?: '';
    $sha1 = sha1_file($dest) ?: '';
    $relativeIncoming = 'storage/federation/incoming/' . basename($dest);
    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="downloaded", bytes_done=?, incoming_path=?, downloaded_md5=?, downloaded_sha1=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$bytes, $relativeIncoming, $md5, $sha1, 'Downloaded to incoming: ' . basename($dest), $jobId]);
    fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', $logEvent, 'Downloaded remote file ' . (int)$job['remote_file_id'] . ' to ' . basename($dest));

    return ['ok' => true, 'job_id' => $jobId, 'direction' => (string)$job['direction'], 'file' => basename($dest), 'bytes' => $bytes, 'md5' => $md5];
}

function federation_worker_run_one_upload(PDO $db, array $config, array $job): array
{
    $jobId = (int)$job['id'];
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [(int)$job['local_file_id']]);
    if (!$file) {
        throw new RuntimeException('Local verified file not found for upload job.');
    }

    $path = federation_worker_file_path($config, $file);
    $bytesTotal = filesize($path) ?: 0;
    $maxBytes = (int)(fed_setting($db, 'max_transfer_file_size_mb', '1024') ?: 1024) * 1024 * 1024;
    if ($bytesTotal <= 0 || $bytesTotal > $maxBytes) {
        throw new RuntimeException('Upload file size is invalid or exceeds max transfer limit.');
    }

    $body = file_get_contents($path);
    if ($body === false) {
        throw new RuntimeException('Could not read local file for upload.');
    }

    $url = rtrim((string)$job['site_url'], '/') . '/api/federation/upload-file.php';
    $headers = [
        'X-UE-Original-Name: ' . (string)$file['original_name'],
        'X-UE-Remote-File-Id: ' . (int)$file['id'],
        'X-UE-File-Size: ' . $bytesTotal,
        'X-UE-MD5: ' . (string)$file['md5'],
        'X-UE-SHA1: ' . (string)$file['sha1'],
    ];

    $response = @file_get_contents($url, false, stream_context_create(federation_worker_signed_context($db, $job, $url, $body, $headers)));
    unset($body);
    if ($response === false) {
        throw new RuntimeException('Upload POST failed.');
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Upload returned invalid JSON: ' . substr($response, 0, 300));
    }
    if (empty($json['ok'])) {
        throw new RuntimeException('Upload rejected: ' . ($json['error'] ?? 'unknown error'));
    }

    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="imported", bytes_done=?, downloaded_md5=?, downloaded_sha1=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$bytesTotal, (string)$file['md5'], (string)$file['sha1'], 'Uploaded to parent; parent job ID ' . ($json['job_id'] ?? ''), $jobId]);
    fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'UPLOAD_TO_PARENT_DONE', 'Uploaded local file ID ' . (int)$file['id'] . ' to parent.');

    if ((int)$job['wait_after_seconds'] > 0) {
        sleep((int)$job['wait_after_seconds']);
    }

    return ['ok' => true, 'job_id' => $jobId, 'direction' => 'upload_to_parent', 'remote_job_id' => $json['job_id'] ?? null, 'bytes' => $bytesTotal, 'md5' => (string)$file['md5']];
}

function federation_worker_run_one_transfer(PDO $db, array $config): array
{
    $job = catalog_one($db, 'SELECT j.*, p.site_name peer_name, p.site_url, p.peer_site_id, p.shared_secret_plain FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.status="queued" AND j.direction IN ("parent_pull_from_child","download_from_parent","upload_to_parent") AND p.is_active=1 ORDER BY j.created_at ASC LIMIT 1');
    if (!$job) {
        return ['ok' => true, 'skipped' => true, 'message' => 'No queued transfer jobs.'];
    }

    $jobId = (int)$job['id'];
    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="running", started_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=?')->execute([$jobId]);

    try {
        if ((string)$job['direction'] === 'upload_to_parent') {
            return federation_worker_run_one_upload($db, $config, $job);
        }
        return federation_worker_run_one_download($db, $config, $job);
    } catch (Throwable $e) {
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed", finished_at=NOW(), last_error=? WHERE id=?')->execute([$e->getMessage(), $jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'ERROR', 'TRANSFER_FAIL', $e->getMessage());
        throw $e;
    }
}

function federation_worker_resolve_incoming_path(array $config, string $relativePath): string
{
    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $path = realpath(__DIR__ . '/../' . $relativePath);
    if (!$root || !$path || !str_starts_with($path, $root) || !is_file($path)) {
        throw new RuntimeException('Incoming file is missing or outside storage: ' . $relativePath);
    }
    return $path;
}

function federation_worker_original_name(PDO $db, array $job): string
{
    if ((string)$job['direction'] === 'upload_to_parent') {
        $file = catalog_one($db, 'SELECT original_name FROM ue_files WHERE id=?', [(int)$job['remote_file_id']]);
        if ($file && trim((string)$file['original_name']) !== '') {
            return (string)$file['original_name'];
        }
    }

    $pf = catalog_one($db, 'SELECT original_name FROM ue_federation_peer_files WHERE peer_id=? AND remote_file_id=? ORDER BY id DESC LIMIT 1', [(int)$job['peer_id'], (int)$job['remote_file_id']]);
    if ($pf && trim((string)$pf['original_name']) !== '') {
        return (string)$pf['original_name'];
    }
    return basename((string)$job['incoming_path']);
}

function federation_worker_game_id_for_profile_engine(PDO $db, string $engineKey): ?int
{
    $engineKey = strtoupper(trim($engineKey));
    if ($engineKey === '') {
        return null;
    }
    $game = catalog_one($db, 'SELECT g.id FROM ue_games g JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 WHERE UPPER(p.engine_key)=? ORDER BY g.id LIMIT 1', [$engineKey]);
    return $game ? (int)$game['id'] : null;
}

function federation_worker_preferred_game_id(PDO $db, array $job): ?int
{
    $pf = catalog_one($db, 'SELECT game_id, remote_engine_key FROM ue_federation_peer_files WHERE peer_id=? AND remote_file_id=? ORDER BY id DESC LIMIT 1', [(int)$job['peer_id'], (int)$job['remote_file_id']]);
    if ($pf && !empty($pf['game_id']) && catalog_one($db, 'SELECT id FROM ue_games WHERE id=?', [(int)$pf['game_id']])) {
        return (int)$pf['game_id'];
    }
    if ($pf && !empty($pf['remote_engine_key'])) {
        return federation_worker_game_id_for_profile_engine($db, (string)$pf['remote_engine_key']);
    }
    return null;
}

function federation_worker_notify_parent(PDO $db, array $job, array $result, string $status): void
{
    if ((string)$job['direction'] !== 'download_from_parent' || empty($job['remote_request_item_id'])) {
        return;
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [(int)$job['peer_id']]);
    if (!$peer || empty($peer['shared_secret_plain'])) {
        fed_log($db, (int)$job['peer_id'], (int)$job['id'], 'WARN', 'PARENT_STATUS_NOTIFY_SKIP', 'Parent peer missing or has no API secret.');
        return;
    }

    $payload = [
        'request_item_id' => (int)$job['remote_request_item_id'],
        'status' => $status === 'imported' ? 'imported' : 'failed',
        'child_local_file_id' => $result['file_id'] ?? null,
        'md5' => (string)($job['downloaded_md5'] ?? ''),
        'sha1' => (string)($job['downloaded_sha1'] ?? ''),
        'message' => (string)($result['message'] ?? $result['status'] ?? ''),
    ];

    $url = rtrim((string)$peer['site_url'], '/') . '/api/federation/request-item-status-update.php';
    $response = fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), (string)$peer['shared_secret_plain'], $payload);
    fed_log($db, (int)$peer['id'], (int)$job['id'], !empty($response['ok']) ? 'INFO' : 'ERROR', 'PARENT_STATUS_NOTIFY', json_encode($response, JSON_UNESCAPED_SLASHES));
}

function federation_worker_stage_failed_import(PDO $db, array $config, array $job, string $incoming, string $originalName, ?int $preferredGameId, Throwable $error): ?array
{
    if (!is_file($incoming)) {
        return null;
    }
    $queueGameId = $preferredGameId ?? federation_worker_preferred_game_id($db,$job);
    if ($queueGameId === null) {
        $detected = catalog_import_detect_game($db,(string)pathinfo($originalName,PATHINFO_EXTENSION));
        $queueGameId = $detected ? (int)$detected['id'] : null;
    }
    if ($queueGameId === null) {
        return null;
    }
    $reason = 'Federation import job ' . (int)$job['id'] . ' failed for ' . $originalName . ': ' . $error->getMessage();
    $stager = new \UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager($db,$config);
    return $stager->stageFailedUpload($queueGameId,$incoming,$originalName,$reason,null,'');
}

function federation_worker_run_one_import(PDO $db, array $config): array
{
    $job = catalog_one($db, 'SELECT * FROM ue_federation_transfer_jobs WHERE status="downloaded" AND incoming_path IS NOT NULL AND incoming_path<>"" ORDER BY finished_at ASC, id ASC LIMIT 1');
    if (!$job) {
        return ['ok' => true, 'skipped' => true, 'message' => 'No downloaded jobs waiting for import.'];
    }

    $jobId = (int)$job['id'];
    $incoming = federation_worker_resolve_incoming_path($config, (string)$job['incoming_path']);
    $md5 = md5_file($incoming) ?: '';
    $sha1 = sha1_file($incoming) ?: '';
    if (!empty($job['downloaded_md5']) && !hash_equals((string)$job['downloaded_md5'], $md5)) {
        throw new RuntimeException('MD5 mismatch before import for job ' . $jobId);
    }
    if (!empty($job['downloaded_sha1']) && !hash_equals((string)$job['downloaded_sha1'], $sha1)) {
        throw new RuntimeException('SHA1 mismatch before import for job ' . $jobId);
    }

    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="running", started_at=COALESCE(started_at,NOW()), last_error=NULL WHERE id=?')->execute([$jobId]);
    $originalName = federation_worker_original_name($db,$job);
    $preferredGameId = in_array((string)$job['direction'], ['download_from_parent','upload_to_parent'], true) ? null : federation_worker_preferred_game_id($db,$job);

    try {
        $result = catalog_import_file($db,$config,$incoming,$originalName,$preferredGameId,$_SESSION['user']['id'] ?? null);
        $status = ($result['status'] === 'verified' || str_starts_with((string)$result['status'], 'duplicate_')) ? 'imported' : 'failed';
        if (str_starts_with((string)$result['status'],'duplicate_') && is_file($incoming)) {
            @unlink($incoming);
        }
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status=?, local_file_id=?, incoming_path=NULL, finished_at=NOW(), last_error=? WHERE id=?')->execute([$status,$result['file_id'] ?? null,$result['message'] ?? $result['status'],$jobId]);
        fed_log($db,(int)$job['peer_id'],$jobId,$status === 'imported' ? 'INFO' : 'WARN','FEDERATION_IMPORT',json_encode($result,JSON_UNESCAPED_SLASHES));
        federation_worker_notify_parent($db,$job,$result,$status);
        return ['ok'=>true,'job_id'=>$jobId,'result'=>$result,'notified_parent'=>(string)$job['direction'] === 'download_from_parent'];
    } catch (Throwable $e) {
        $staged = null;
        try {
            $staged = federation_worker_stage_failed_import($db,$config,$job,$incoming,$originalName,$preferredGameId,$e);
        } catch (Throwable $stageError) {
            fed_log($db,(int)$job['peer_id'],$jobId,'ERROR','FEDERATION_STAGE_FAIL',$stageError->getMessage());
        }
        $message = $e->getMessage();
        $stagedFileId = null;
        $stagedPath = (string)$job['incoming_path'];
        if (is_array($staged)) {
            $stagedFileId = (int)$staged['file_id'];
            $stagedPath = catalog_unverified_storage_relative($config,(string)$staged['path']);
            $message .= ' Staged as unverified file #' . $stagedFileId . '.';
        }
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed", local_file_id=?, incoming_path=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$stagedFileId,$stagedPath,$message,$jobId]);
        fed_log($db,(int)$job['peer_id'],$jobId,'ERROR','FEDERATION_IMPORT_FAIL',$message);
        federation_worker_notify_parent($db,$job,['status'=>'failed','message'=>$message],'failed');
        throw $e;
    }
}
