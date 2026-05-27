<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

function external_public_download_mode(PDO $db): string
{
    $mode = (string)fed_setting($db, 'public_download_mode', 'local_direct');
    return in_array($mode, ['local_direct','external_mirror','external_mirror_preferred','disabled'], true) ? $mode : 'local_direct';
}

function external_active_link_for_file(PDO $db, int $fileId): ?array
{
    return catalog_one($db, 'SELECT l.*, p.provider_name, p.provider_key FROM ue_external_download_links l JOIN ue_external_download_providers p ON p.id=l.provider_id WHERE l.file_id=? AND l.status="active" AND p.is_active=1 AND (l.expires_at IS NULL OR l.expires_at>NOW()) ORDER BY p.priority ASC, l.created_at DESC LIMIT 1', [$fileId]);
}

function external_queue_exists(PDO $db, int $fileId): bool
{
    return (bool)catalog_one($db, 'SELECT id FROM ue_external_mirror_jobs WHERE file_id=? AND status IN ("queued","waiting_admin","uploading") LIMIT 1', [$fileId]);
}

function external_default_provider(PDO $db): ?array
{
    return catalog_one($db, 'SELECT * FROM ue_external_download_providers WHERE is_active=1 ORDER BY priority ASC, id ASC LIMIT 1');
}

function external_queue_mirror_job(PDO $db, int $fileId, ?int $providerId = null, ?int $userId = null, ?string $ip = null): ?int
{
    if (external_queue_exists($db, $fileId)) {
        return null;
    }

    $provider = $providerId ? catalog_one($db, 'SELECT * FROM ue_external_download_providers WHERE id=? AND is_active=1', [$providerId]) : external_default_provider($db);
    if (!$provider) {
        return null;
    }

    $requiresApproval = (string)fed_setting($db, 'external_mirror_require_admin_approval', '0') === '1';
    $status = $requiresApproval ? 'waiting_admin' : 'queued';
    $stmt = $db->prepare('INSERT INTO ue_external_mirror_jobs(file_id, provider_id, status, requested_by_ip, requested_by_user_id) VALUES(?,?,?,?,?)');
    $stmt->execute([$fileId, (int)$provider['id'], $status, $ip, $userId]);
    return (int)$db->lastInsertId();
}

function external_public_download_decision(PDO $db, int $fileId, ?int $userId = null, ?string $ip = null): array
{
    $mode = external_public_download_mode($db);
    if ($mode === 'disabled') {
        return ['type' => 'disabled', 'message' => 'Public downloads are disabled.'];
    }
    if ($mode === 'local_direct') {
        return ['type' => 'local_direct'];
    }

    $link = external_active_link_for_file($db, $fileId);
    if ($link) {
        $db->prepare('UPDATE ue_external_download_links SET requested_count=requested_count+1, last_requested_at=NOW() WHERE id=?')->execute([(int)$link['id']]);
        return ['type' => 'external_link', 'link' => $link];
    }

    if ($mode === 'external_mirror_preferred') {
        external_queue_mirror_job($db, $fileId, null, $userId, $ip);
        return ['type' => 'local_direct', 'queued_mirror' => true];
    }

    if ((string)fed_setting($db, 'external_mirror_auto_queue', '1') === '1') {
        $jobId = external_queue_mirror_job($db, $fileId, null, $userId, $ip);
        return ['type' => 'pending', 'job_id' => $jobId, 'message' => 'External download link is being prepared.'];
    }

    return ['type' => 'pending', 'message' => 'External download link is not ready yet.'];
}

function external_expire_old_links(PDO $db): int
{
    $stmt = $db->prepare('UPDATE ue_external_download_links SET status="expired" WHERE status="active" AND expires_at IS NOT NULL AND expires_at<NOW()');
    $stmt->execute();
    return $stmt->rowCount();
}

function external_create_manual_link(PDO $db, int $fileId, int $providerId, string $url, ?int $userId = null, ?int $expiryDays = null): int
{
    $provider = catalog_one($db, 'SELECT * FROM ue_external_download_providers WHERE id=?', [$providerId]);
    if (!$provider) {
        throw new RuntimeException('Provider not found.');
    }
    $days = $expiryDays ?? (int)($provider['expiry_days'] ?: fed_setting($db, 'external_mirror_expiry_days', '7'));
    $stmt = $db->prepare('INSERT INTO ue_external_download_links(file_id, provider_id, status, external_url, uploaded_at, expires_at, created_by) VALUES(?,?,"active",?,NOW(),DATE_ADD(NOW(), INTERVAL ? DAY),?)');
    $stmt->execute([$fileId, $providerId, $url, max(1, $days), $userId]);
    return (int)$db->lastInsertId();
}
