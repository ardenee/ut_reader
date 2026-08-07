<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for external mirrors.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/BaseGameProtection.php';

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

function external_file_for_download(PDO $db, int $fileId): ?array
{
    return catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status<>"failed"', [$fileId]);
}

function external_queue_mirror_job(PDO $db, int $fileId, ?int $providerId = null, ?int $userId = null, ?string $ip = null): ?int
{
    $file = external_file_for_download($db, $fileId);
    if ($file && base_game_file_is_protected($db, $file)) {
        return null;
    }
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
    $file = external_file_for_download($db, $fileId);
    if ($file && base_game_file_is_protected($db, $file)) {
        return ['type' => 'disabled', 'message' => base_game_block_message($file)];
    }

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

function external_fail_stale_uploading_jobs(PDO $db): int
{
    $stmt = $db->prepare('UPDATE ue_external_mirror_jobs SET status="failed", finished_at=NOW(), last_error="Mirror job was uploading for more than 24 hours and was marked stale." WHERE status="uploading" AND started_at IS NOT NULL AND started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $stmt->execute();
    return $stmt->rowCount();
}

function external_mark_manual_jobs_waiting(PDO $db): int
{
    $stmt = $db->prepare('UPDATE ue_external_mirror_jobs j JOIN ue_external_download_providers p ON p.id=j.provider_id SET j.status="waiting_admin", j.last_error="Manual provider requires admin fulfilment." WHERE j.status="queued" AND p.provider_class="ManualProvider"');
    $stmt->execute();
    return $stmt->rowCount();
}

function external_mirror_maintenance(PDO $db): array
{
    return [
        'expired_links' => external_expire_old_links($db),
        'manual_jobs_waiting_admin' => external_mark_manual_jobs_waiting($db),
        'stale_uploading_jobs_failed' => external_fail_stale_uploading_jobs($db),
    ];
}

function external_create_manual_link(PDO $db, int $fileId, int $providerId, string $url, ?int $userId = null, ?int $expiryDays = null): int
{
    $file = external_file_for_download($db, $fileId);
    if ($file && base_game_file_is_protected($db, $file)) {
        throw new RuntimeException(base_game_block_message($file));
    }

    $provider = catalog_one($db, 'SELECT * FROM ue_external_download_providers WHERE id=?', [$providerId]);
    if (!$provider) {
        throw new RuntimeException('Provider not found.');
    }
    $days = $expiryDays ?? (int)($provider['expiry_days'] ?: fed_setting($db, 'external_mirror_expiry_days', '7'));
    $stmt = $db->prepare('INSERT INTO ue_external_download_links(file_id, provider_id, status, external_url, uploaded_at, expires_at, created_by) VALUES(?, ?, "active", ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?)');
    $stmt->execute([$fileId, $providerId, $url, max(1, $days), $userId]);
    return (int)$db->lastInsertId();
}
