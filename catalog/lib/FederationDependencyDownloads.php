<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

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

/**
 * Poll every active parent and queue only approved files that still satisfy a
 * local missing dependency. This is the child-side policy boundary: arbitrary
 * parent files are never queued through this path.
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
        $secret = fed_peer_secret($db, $parent);
        if ($secret === '') {
            $parentResults[] = ['peer_id' => (int)$parent['id'], 'ok' => false, 'error' => 'Parent peer has no API secret.'];
            continue;
        }

        try {
            $status = fed_http_post_signed(
                rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
                $localSiteId,
                $secret,
                ['latest' => true]
            );
            if (empty($status['ok'])) {
                throw new RuntimeException((string)($status['error'] ?? 'Parent request status failed.'));
            }

            $parentQueued = 0;
            foreach (($status['items'] ?? []) as $item) {
                if (!is_array($item) || (string)($item['status'] ?? '') !== 'approved' || empty($item['local_file_id'])) {
                    continue;
                }
                $approvedSeen++;

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
        fed_log($db, null, null, 'INFO', 'DEPENDENCY_DOWNLOADS_AUTO_QUEUED', 'Queued ' . $queued . ' approved dependency download(s).');
    }

    return [
        'ok' => true,
        'queued' => $queued,
        'approved_seen' => $approvedSeen,
        'not_needed' => $notNeeded,
        'already_local' => $alreadyLocal,
        'duplicates' => $duplicates,
        'parents' => $parentResults,
    ];
}
