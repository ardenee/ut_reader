<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Polls active parents and queues approved dependency downloads that remain valid locally.
 * Why: Signed parent status polling, policy application, duplicate suppression and transfer-job creation form one federation orchestration use case.
 * Role: Infrastructure federation orchestration service; local dependency reads and peer-file cache persistence are delegated collaborators.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationDependencyDownloadQueueService
{
    private readonly CatalogFederationDependencyNeedQuery $needQuery;
    private readonly CatalogFederationApprovedParentFileCache $parentFileCache;
    private readonly CatalogFederationBaseGamePolicyService $baseGamePolicy;
    private readonly CatalogFederationParentPolicyStore $parentPolicyStore;

    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPeerSecret.php';

        $this->needQuery = new CatalogFederationDependencyNeedQuery($db);
        $this->parentFileCache = new CatalogFederationApprovedParentFileCache($db);
        $this->baseGamePolicy = new CatalogFederationBaseGamePolicyService($db);
        $this->parentPolicyStore = new CatalogFederationParentPolicyStore($db);
    }

    /** @return array<string,mixed> */
    public function queueApproved(): array
    {
        if ((string)\fed_setting($this->db, 'site_role', 'standalone') !== 'child') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'site is not a child'];
        }

        $parents = \catalog_all(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id'
        );
        if (!$parents) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'no active parent peer'];
        }

        $localSiteId = (string)\fed_setting($this->db, 'site_id', '');
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
        $insert = $this->db->prepare(
            'INSERT INTO ue_federation_transfer_jobs(
                peer_id,remote_request_item_id,direction,remote_file_id,status,
                speed_limit_kbps,wait_after_seconds,bytes_total
             ) VALUES(?,? ,"download_from_parent",? ,"queued",?,?,?)'
        );

        foreach ($parents as $parent) {
            try {
                $storedSecret = \federation_peer_stored_signing_secret($this->db, $parent);
                $status = \fed_http_post_signed(
                    rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
                    $localSiteId,
                    $storedSecret,
                    ['latest' => true]
                );
                if (empty($status['ok'])) {
                    throw new RuntimeException((string)($status['error'] ?? 'Parent request status failed.'));
                }
                if (is_array($status['policy'] ?? null)) {
                    $this->parentPolicyStore->cache((int)$parent['id'], $status['policy']);
                }

                // Preserve existing behavior: resolve from the peer snapshot loaded at the
                // start of this poll. A newly cached policy becomes visible on the next pass.
                $ignoreBaseGame = $this->baseGamePolicy->ignoreBaseGameFiles($parent);

                $parentQueued = 0;
                foreach (($status['items'] ?? []) as $item) {
                    if (!is_array($item)
                        || (string)($item['status'] ?? '') !== 'approved'
                        || empty($item['local_file_id'])) {
                        continue;
                    }
                    $approvedSeen++;
                    if ($ignoreBaseGame && !empty($item['is_base_game'])) {
                        $baseGameExcluded++;
                        continue;
                    }

                    $requiredPackage = trim((string)($item['required_package'] ?? ''));
                    $requiredObjectPath = trim((string)($item['required_object_path'] ?? ''));
                    if (!$this->needQuery->requestStillNeeded($requiredPackage, $requiredObjectPath)) {
                        $notNeeded++;
                        continue;
                    }
                    if ($this->needQuery->itemAlreadyLocal($item)) {
                        $alreadyLocal++;
                        continue;
                    }

                    $requestItemId = (int)($item['id'] ?? 0);
                    $remoteFileId = (int)($item['local_file_id'] ?? 0);
                    if ($requestItemId <= 0 || $remoteFileId <= 0) {
                        continue;
                    }

                    $existing = \catalog_one(
                        $this->db,
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

                    $this->parentFileCache->cache((int)$parent['id'], $item);
                    $insert->execute([
                        (int)$parent['id'],
                        $requestItemId,
                        $remoteFileId,
                        (int)\fed_setting($this->db, 'max_download_kbps', '0'),
                        (int)\fed_setting($this->db, 'delay_between_downloads_seconds', '5'),
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
                \fed_log(
                    $this->db,
                    (int)$parent['id'],
                    null,
                    'ERROR',
                    'DEPENDENCY_APPROVAL_POLL_FAIL',
                    $error->getMessage()
                );
            }
        }

        if ($queued > 0) {
            \fed_log(
                $this->db,
                null,
                null,
                'INFO',
                'DEPENDENCY_DOWNLOADS_AUTO_QUEUED',
                'Queued ' . $queued . ' approved dependency download(s) after applying the base-game policy.'
            );
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
}
