<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for federation dependency downloads.
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
require_once __DIR__ . '/FederationPeerSecret.php';
require_once __DIR__ . '/FederationBaseGamePolicy.php';

function federation_dependency_request_still_needed(PDO $db, string $requiredPackage, string $requiredObjectPath = ''): bool
{
    $requiredPackage = trim($requiredPackage);
    $requiredObjectPath = trim($requiredObjectPath);
    if ($requiredPackage === '') {
        return false;
    }

    if ($requiredObjectPath !== '') {
        $row = catalog_one(
            $db,
            'SELECT d.id
             FROM ue_dependencies d
             JOIN ue_files f ON f.id=d.file_id
             WHERE d.status="missing" AND f.scan_status="verified"
               AND d.required_package=? AND d.required_object_path=?
             LIMIT 1',
            [$requiredPackage, $requiredObjectPath]
        );
        if ($row) {
            return true;
        }
    }

    return catalog_one(
        $db,
        'SELECT d.id
         FROM ue_dependencies d
         JOIN ue_files f ON f.id=d.file_id
         WHERE d.status="missing" AND f.scan_status="verified" AND d.required_package=?
         LIMIT 1',
        [$requiredPackage]
    ) !== null;
}

/** @param array<string,mixed> $item */
function federation_dependency_item_already_local(PDO $db, array $item): bool
{
    $guid = strtoupper(trim((string)($item['package_guid'] ?? '')));
    $md5 = strtolower(trim((string)($item['md5'] ?? '')));
    $package = trim((string)($item['required_package'] ?? ''));

    if ($guid !== '' && catalog_one($db, 'SELECT id FROM ue_files WHERE package_guid=? AND scan_status="verified" LIMIT 1', [$guid])) {
        return true;
    }
    if ($md5 !== '' && catalog_one($db, 'SELECT id FROM ue_files WHERE md5=? AND scan_status="verified" LIMIT 1', [$md5])) {
        return true;
    }
    if ($package !== '' && catalog_one($db, 'SELECT id FROM ue_files WHERE package_name=? AND scan_status="verified" LIMIT 1', [$package])) {
        return true;
    }
    return false;
}

/** @param array<string,mixed> $item */
function federation_cache_approved_parent_file(PDO $db, int $peerId, array $item): void
{
    $remoteFileId = (int)($item['local_file_id'] ?? 0);
    $packageName = trim((string)($item['package_name'] ?? $item['required_package'] ?? ''));
    $originalName = catalog_clean_unreal_filename((string)($item['original_name'] ?? ''));
    $guid = strtoupper(trim((string)($item['package_guid'] ?? '')));
    $md5 = strtolower(trim((string)($item['md5'] ?? '')));
    $sha1 = strtolower(trim((string)($item['sha1'] ?? '')));

    if ($remoteFileId <= 0 || $packageName === '' || $originalName === '' || $originalName === 'package') {
        throw new RuntimeException('Approved parent file metadata is incomplete.');
    }

    $db->prepare(
        'INSERT INTO ue_federation_peer_files(
            peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,
            package_name,original_name,extension,file_size,md5,sha1,package_guid,is_base_game,
            is_compressed,compression_flags,import_count,export_count,last_seen_at
         ) VALUES(?,NULL,"","",?,?,?,?,?,?,?,?,?,0,0,0,0,NOW())
         ON DUPLICATE KEY UPDATE
            package_name=VALUES(package_name), original_name=VALUES(original_name),
            extension=VALUES(extension), file_size=VALUES(file_size),
            md5=VALUES(md5), sha1=VALUES(sha1), package_guid=VALUES(package_guid),
            is_base_game=VALUES(is_base_game), last_seen_at=NOW()'
    )->execute([
        $peerId,
        $remoteFileId,
        mb_substr($packageName, 0, 255, 'UTF-8'),
        mb_substr($originalName, 0, 255, 'UTF-8'),
        mb_substr(strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)), 0, 32, 'UTF-8'),
        max(0, (int)($item['file_size'] ?? 0)),
        $md5 !== '' ? $md5 : null,
        $sha1 !== '' ? $sha1 : null,
        $guid !== '' ? $guid : null,
        !empty($item['is_base_game']) ? 1 : 0,
    ]);
}

/**
 * Poll every active parent and queue only approved files that still satisfy a
 * local missing dependency and remain visible under the parent base-game policy.
 * Arbitrary or policy-excluded parent files are never queued here.
 *
 * @return array<string,mixed>
 */
function federation_queue_approved_dependency_downloads(PDO $db): array
{
    if ((string)fed_setting($db, 'site_role', 'standalone') !== 'child') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'site is not a child'];
    }

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id');
    if (!$parents) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no active parent peer'];
    }

    $localSiteId = (string)fed_setting($db, 'site_id', '');
    if ($localSiteId === '') {
        throw new RuntimeException('Local child site ID is unavailable.');
    }

    $queued = 0;
    $approvedSeen = 0;
    $baseGameExcluded = 0;
    $notNeeded = 0;
    $alreadyLocal = 0;
    $duplicates = 0;
    $parentResults = [];
    $insert = $db->prepare(
        'INSERT INTO ue_federation_transfer_jobs(
            peer_id,remote_request_item_id,direction,remote_file_id,status,
            speed_limit_kbps,wait_after_seconds,bytes_total
         ) VALUES(?,? ,"download_from_parent",? ,"queued",?,?,?)'
    );

    foreach ($parents as $parent) {
        try {
            $storedSecret = federation_peer_stored_signing_secret($db, $parent);
            $status = fed_http_post_signed(
                rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
                $localSiteId,
                $storedSecret,
                ['latest' => true]
            );
            if (empty($status['ok'])) {
                throw new RuntimeException((string)($status['error'] ?? 'Parent request status failed.'));
            }
            if (is_array($status['policy'] ?? null)) {
                federation_cache_parent_base_game_policy($db, (int)$parent['id'], $status['policy']);
            }
            $ignoreBaseGame = federation_ignore_base_game_files($db, $parent);

            $parentQueued = 0;
            foreach (($status['items'] ?? []) as $item) {
                if (!is_array($item) || (string)($item['status'] ?? '') !== 'approved' || empty($item['local_file_id'])) {
                    continue;
                }
                $approvedSeen++;
                if ($ignoreBaseGame && !empty($item['is_base_game'])) {
                    $baseGameExcluded++;
                    continue;
                }

                $requiredPackage = trim((string)($item['required_package'] ?? ''));
                $requiredObjectPath = trim((string)($item['required_object_path'] ?? ''));
                if (!federation_dependency_request_still_needed($db, $requiredPackage, $requiredObjectPath)) {
                    $notNeeded++;
                    continue;
                }
                if (federation_dependency_item_already_local($db, $item)) {
                    $alreadyLocal++;
                    continue;
                }

                $requestItemId = (int)($item['id'] ?? 0);
                $remoteFileId = (int)($item['local_file_id'] ?? 0);
                if ($requestItemId <= 0 || $remoteFileId <= 0) {
                    continue;
                }

                $existing = catalog_one(
                    $db,
                    'SELECT id FROM ue_federation_transfer_jobs
                     WHERE peer_id=? AND direction="download_from_parent"
                       AND remote_request_item_id=?
                       AND status IN ("queued","running","downloaded","imported")
                     LIMIT 1',
                    [(int)$parent['id'], $requestItemId]
                );
                if ($existing) {
                    $duplicates++;
                    continue;
                }

                federation_cache_approved_parent_file($db, (int)$parent['id'], $item);
                $insert->execute([
                    (int)$parent['id'],
                    $requestItemId,
                    $remoteFileId,
                    (int)fed_setting($db, 'max_download_kbps', '0'),
                    (int)fed_setting($db, 'delay_between_downloads_seconds', '5'),
                    max(0, (int)($item['file_size'] ?? 0)),
                ]);
                $queued++;
                $parentQueued++;
            }

            $parentResults[] = [
                'peer_id' => (int)$parent['id'],
                'site_name' => (string)$parent['site_name'],
                'ok' => true,
                'request_id' => (int)($status['request']['id'] ?? 0),
                'request_status' => (string)($status['request']['status'] ?? 'none'),
                'queued' => $parentQueued,
            ];
        } catch (Throwable $error) {
            $parentResults[] = [
                'peer_id' => (int)$parent['id'],
                'site_name' => (string)$parent['site_name'],
                'ok' => false,
                'error' => $error->getMessage(),
            ];
            fed_log($db, (int)$parent['id'], null, 'ERROR', 'DEPENDENCY_APPROVAL_POLL_FAIL', $error->getMessage());
        }
    }

    if ($queued > 0) {
        fed_log($db, null, null, 'INFO', 'DEPENDENCY_DOWNLOADS_AUTO_QUEUED', 'Queued ' . $queued . ' approved dependency download(s) after applying the base-game policy.');
    }

    return [
        'ok' => true,
        'queued' => $queued,
        'approved_seen' => $approvedSeen,
        'base_game_excluded' => $baseGameExcluded,
        'not_needed' => $notNeeded,
        'already_local' => $alreadyLocal,
        'duplicates' => $duplicates,
        'parents' => $parentResults,
    ];
}
