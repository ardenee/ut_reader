<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Pulls remote federation inventories into local peer-file storage and manages synchronization cadence.
 * Why: Remote pagination, row normalization, transactions, stale-row pruning and due scheduling are one persistence/network use case.
 * Role: Infrastructure federation synchronization service replacing procedural peer inventory helpers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationPeerInventorySyncService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPeerSecret.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
    }

    /** @return array<string,mixed> */
    public function pullFromPeer(int $peerId): array
    {
        $peer = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role IN ("parent","child") AND is_active=1',
            [$peerId]
        );
        if (!$peer) {
            throw new RuntimeException('Active federation peer not found.');
        }
        $storedSecret = \federation_peer_stored_signing_secret($this->db, $peer);
        $siteId = (string)\fed_setting($this->db, 'site_id', '');
        if ($siteId === '') {
            throw new RuntimeException('Local federation site ID is unavailable.');
        }

        $url = rtrim((string)$peer['site_url'], '/') . '/api/federation/inventory-list.php';
        $seenAt = date('Y-m-d H:i:s');
        $afterFileId = 0;
        $received = 0;
        $excluded = 0;
        $pages = 0;
        $complete = false;
        $policy = null;
        $ignoreBaseGame = \federation_ignore_base_game_files(
            $this->db,
            (string)$peer['peer_role'] === 'parent' ? $peer : null
        );
        $upsert = $this->db->prepare(
            'INSERT INTO ue_federation_peer_files(
                peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,
                package_name,original_name,extension,file_size,md5,sha1,package_guid,is_base_game,
                is_compressed,compression_flags,import_count,export_count,last_seen_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                game_id=VALUES(game_id), remote_game_name=VALUES(remote_game_name),
                remote_engine_key=VALUES(remote_engine_key), remote_file_id=VALUES(remote_file_id),
                package_name=VALUES(package_name), original_name=VALUES(original_name),
                extension=VALUES(extension), file_size=VALUES(file_size), md5=VALUES(md5),
                sha1=VALUES(sha1), package_guid=VALUES(package_guid), is_base_game=VALUES(is_base_game),
                is_compressed=VALUES(is_compressed), compression_flags=VALUES(compression_flags),
                import_count=VALUES(import_count), export_count=VALUES(export_count),
                last_seen_at=VALUES(last_seen_at)'
        );

        while (!$complete) {
            if (++$pages > 1000) {
                throw new RuntimeException('Peer inventory exceeded the maximum page count.');
            }
            $result = \fed_http_post_signed($url, $siteId, $storedSecret, [
                'after_file_id' => $afterFileId,
                'limit' => 500,
            ]);
            if (empty($result['ok']) || !isset($result['files']) || !is_array($result['files'])) {
                throw new RuntimeException(
                    'Peer inventory request failed: ' . ($result['error'] ?? 'invalid response')
                );
            }
            if (is_array($result['policy'] ?? null)) {
                $policy = $result['policy'];
                if ((string)$peer['peer_role'] === 'parent') {
                    \federation_cache_parent_base_game_policy($this->db, $peerId, $policy);
                    $peer = \catalog_one(
                        $this->db,
                        'SELECT * FROM ue_federation_peers WHERE id=?',
                        [$peerId]
                    ) ?: $peer;
                    $ignoreBaseGame = \federation_ignore_base_game_files($this->db, $peer);
                }
            }
            $remoteSiteId = strtolower(trim((string)($result['site']['site_id'] ?? '')));
            if ($remoteSiteId !== '' && !hash_equals(strtolower((string)$peer['peer_site_id']), $remoteSiteId)) {
                throw new RuntimeException('Inventory identity does not match the selected peer.');
            }

            $this->db->beginTransaction();
            try {
                foreach ($result['files'] as $file) {
                    if (!is_array($file)) {
                        continue;
                    }
                    if ($ignoreBaseGame && !empty($file['is_base_game'])) {
                        $excluded++;
                        continue;
                    }
                    $upsert->execute($this->rowValues($peerId, $file, $seenAt));
                    $received++;
                }
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }

            $nextAfter = max($afterFileId, (int)($result['next_after_file_id'] ?? $afterFileId));
            $complete = !empty($result['complete']);
            if (!$complete && $nextAfter <= $afterFileId) {
                throw new RuntimeException('Peer inventory cursor did not advance.');
            }
            $afterFileId = $nextAfter;
        }

        $deleteSql = 'DELETE FROM ue_federation_peer_files WHERE peer_id=? AND (last_seen_at<>?'
            . ($ignoreBaseGame ? ' OR COALESCE(is_base_game,0)=1' : '') . ')';
        $delete = $this->db->prepare($deleteSql);
        $delete->execute([$peerId, $seenAt]);
        $removed = $delete->rowCount();

        \fed_log(
            $this->db,
            $peerId,
            null,
            'INFO',
            'INVENTORY_SYNC_FROM_PEER',
            'Role=' . (string)$peer['peer_role'] . '; received ' . $received
                . ' policy-visible row(s) in ' . $pages . ' page(s); excluded '
                . $excluded . ' base-game row(s); removed ' . $removed . ' stale or excluded row(s).'
        );

        return [
            'ok' => true,
            'peer_id' => $peerId,
            'peer_role' => (string)$peer['peer_role'],
            'peer_name' => (string)$peer['site_name'],
            'received' => $received,
            'base_game_excluded' => $excluded,
            'removed_stale' => $removed,
            'pages' => $pages,
            'policy' => $policy,
            'synchronized_at' => $seenAt,
        ];
    }

    /** @return array<string,mixed> */
    public function pullFromChild(int $peerId): array
    {
        $peer = \catalog_one(
            $this->db,
            'SELECT id FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1',
            [$peerId]
        );
        if (!$peer) {
            throw new RuntimeException('Active child peer not found.');
        }
        return $this->pullFromPeer($peerId);
    }

    /** @return array<string,mixed> */
    public function pullFromParent(int $peerId): array
    {
        $peer = \catalog_one(
            $this->db,
            'SELECT id FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1',
            [$peerId]
        );
        if (!$peer) {
            throw new RuntimeException('Active parent peer not found.');
        }
        return $this->pullFromPeer($peerId);
    }

    public function syncIntervalHours(): int
    {
        return max(
            0,
            min(720, (int)(\fed_setting($this->db, 'inventory_sync_interval_hours', '24') ?? '24'))
        );
    }

    public function lastSyncAt(int $peerId): ?string
    {
        $row = \catalog_one(
            $this->db,
            'SELECT MAX(created_at) synchronized_at
             FROM ue_federation_transfer_logs
             WHERE peer_id=? AND event IN ("INVENTORY_SYNC_FROM_PEER","INVENTORY_PULLED_BY_PARENT")',
            [$peerId]
        );
        $value = trim((string)($row['synchronized_at'] ?? ''));
        return $value !== '' ? $value : null;
    }

    public function syncIsDue(int $peerId, ?int $now = null): bool
    {
        $hours = $this->syncIntervalHours();
        if ($hours <= 0) {
            return false;
        }
        $last = $this->lastSyncAt($peerId);
        if ($last === null) {
            return true;
        }
        $timestamp = strtotime($last);
        if ($timestamp === false) {
            return true;
        }
        return ($now ?? time()) >= $timestamp + ($hours * 3600);
    }

    /** @return array<string,mixed> */
    public function syncDueInventories(bool $force = false): array
    {
        $role = strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone')));
        $interval = $this->syncIntervalHours();
        if ($role === 'standalone') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'standalone site',
                'interval_hours' => $interval,
                'peers' => [],
            ];
        }
        if (!$force && $interval <= 0) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'automatic inventory synchronization disabled',
                'interval_hours' => 0,
                'peers' => [],
            ];
        }

        $wantedPeerRole = $role === 'parent' ? 'child' : 'parent';
        $peers = \catalog_all(
            $this->db,
            'SELECT id,site_name,peer_role FROM ue_federation_peers '
                . 'WHERE peer_role=? AND is_active=1 ORDER BY id',
            [$wantedPeerRole]
        );
        $results = [];
        $synchronized = 0;
        $notDue = 0;
        $failed = 0;

        foreach ($peers as $peer) {
            $peerId = (int)$peer['id'];
            if (!$force && !$this->syncIsDue($peerId)) {
                $notDue++;
                $results[] = [
                    'peer_id' => $peerId,
                    'peer_name' => (string)$peer['site_name'],
                    'status' => 'not_due',
                    'last_sync_at' => $this->lastSyncAt($peerId),
                ];
                continue;
            }
            try {
                $result = $this->pullFromPeer($peerId);
                $result['status'] = 'synchronized';
                $results[] = $result;
                $synchronized++;
            } catch (Throwable $error) {
                $failed++;
                \fed_log(
                    $this->db,
                    $peerId,
                    null,
                    'ERROR',
                    'INVENTORY_SYNC_FAILED',
                    $error->getMessage()
                );
                $results[] = [
                    'peer_id' => $peerId,
                    'peer_name' => (string)$peer['site_name'],
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                ];
            }
        }

        return [
            'ok' => $failed === 0,
            'site_role' => $role,
            'interval_hours' => $interval,
            'synchronized' => $synchronized,
            'not_due' => $notDue,
            'failed' => $failed,
            'peers' => $results,
        ];
    }

    /** @return array<int,mixed> */
    private function rowValues(int $peerId, array $file, string $seenAt): array
    {
        $packageName = trim((string)($file['package_name'] ?? ''));
        $originalName = trim((string)($file['original_name'] ?? ''));
        $guid = strtoupper(trim((string)($file['package_guid'] ?? '')));
        $md5 = strtolower(trim((string)($file['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($file['sha1'] ?? '')));
        if ($packageName === '' || $originalName === '' || ($guid === '' && $md5 === '')) {
            throw new RuntimeException('Peer inventory contains an incomplete file identity.');
        }
        if ($md5 !== '' && preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            throw new RuntimeException('Peer inventory contains an invalid MD5 value.');
        }
        if ($sha1 !== '' && preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new RuntimeException('Peer inventory contains an invalid SHA1 value.');
        }
        if ($guid !== '' && preg_match('/^[A-F0-9-]{8,64}$/', $guid) !== 1) {
            throw new RuntimeException('Peer inventory contains an invalid package GUID.');
        }

        return [
            $peerId,
            $this->localGameId($file),
            mb_substr(trim((string)($file['game_name'] ?? '')), 0, 160, 'UTF-8'),
            mb_substr(trim((string)($file['engine_key'] ?? '')), 0, 32, 'UTF-8'),
            isset($file['file_id']) ? max(0, (int)$file['file_id']) ?: null : null,
            mb_substr($packageName, 0, 255, 'UTF-8'),
            mb_substr($originalName, 0, 255, 'UTF-8'),
            mb_substr(
                strtolower(trim((string)($file['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION)))),
                0,
                32,
                'UTF-8'
            ),
            max(0, (int)($file['file_size'] ?? 0)),
            $md5 !== '' ? $md5 : null,
            $sha1 !== '' ? $sha1 : null,
            $guid !== '' ? $guid : null,
            !empty($file['is_base_game']) ? 1 : 0,
            !empty($file['is_compressed']) ? 1 : 0,
            max(0, (int)($file['compression_flags'] ?? 0)),
            max(0, (int)($file['import_count'] ?? 0)),
            max(0, (int)($file['export_count'] ?? 0)),
            $seenAt,
        ];
    }

    private function localGameId(array $file): ?int
    {
        $gameName = trim((string)($file['game_name'] ?? ''));
        if ($gameName !== '') {
            $game = \catalog_one(
                $this->db,
                'SELECT id FROM ue_games WHERE name=? ORDER BY id LIMIT 1',
                [$gameName]
            );
            if ($game) {
                return (int)$game['id'];
            }
        }

        $engineKey = strtoupper(trim((string)($file['engine_key'] ?? '')));
        if ($engineKey !== '') {
            $game = \catalog_one(
                $this->db,
                'SELECT g.id
                 FROM ue_games g
                 JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
                 WHERE UPPER(p.engine_key)=?
                 ORDER BY g.id LIMIT 1',
                [$engineKey]
            );
            if ($game) {
                return (int)$game['id'];
            }
        }

        return null;
    }
}
