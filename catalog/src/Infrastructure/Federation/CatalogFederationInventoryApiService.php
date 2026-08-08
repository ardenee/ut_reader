<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation inventory listing and parent-triggered refresh orchestration.
 * Why: Signed inventory endpoints should authenticate/parse/serialize; role policy, queries and synchronization belong to Infrastructure.
 * Role: Infrastructure federation inventory read/refresh service preserving existing endpoint response contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationInventoryApiService
{
    private readonly CatalogFederationPeerInventorySyncService $peerSync;

    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/BaseGameProtection.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
        $this->peerSync = new CatalogFederationPeerInventorySyncService($db);
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array<string,mixed> */
    public function list(array $peer, array $payload): array
    {
        \base_game_ensure($this->db);
        $localRole = strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone')));
        $peerRole = strtolower(trim((string)($peer['peer_role'] ?? '')));
        $allowed = ($localRole === 'parent' && $peerRole === 'child')
            || ($localRole === 'child' && $peerRole === 'parent');
        if (!$allowed) {
            throw new CatalogFederationApiException(
                'Only the paired opposite federation role may read this inventory.',
                403
            );
        }

        $afterFileId = max(0, (int)($payload['after_file_id'] ?? 0));
        $limit = max(1, min(1000, (int)($payload['limit'] ?? 500)));
        $policySql = \federation_ignore_base_game_files(
            $this->db,
            $peerRole === 'parent' ? $peer : null
        ) ? ' AND bg.id IS NULL' : '';

        $rows = \catalog_all(
            $this->db,
            'SELECT f.id file_id,f.game_id,g.name game_name,COALESCE(p.engine_key,"") engine_key,'
            . 'f.package_name,f.original_name,f.extension,f.file_size,f.md5,f.sha1,f.package_guid,'
            . 'CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END is_base_game,'
            . 'COALESCE(f.is_compressed,0) is_compressed,COALESCE(f.compression_flags,0) compression_flags,'
            . 'COALESCE(f.import_count,0) import_count,COALESCE(f.export_count,0) export_count '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid '
            . 'WHERE f.scan_status="verified" AND f.id>?' . $policySql . ' '
            . 'ORDER BY f.id LIMIT ' . $limit,
            [$afterFileId]
        );

        $files = [];
        $nextAfter = $afterFileId;
        foreach ($rows as $row) {
            $fileId = (int)$row['file_id'];
            $nextAfter = max($nextAfter, $fileId);
            $files[] = [
                'file_id' => $fileId,
                'game_id' => (int)$row['game_id'],
                'game_name' => (string)$row['game_name'],
                'engine_key' => (string)$row['engine_key'],
                'package_name' => (string)$row['package_name'],
                'original_name' => (string)$row['original_name'],
                'extension' => (string)$row['extension'],
                'file_size' => (int)$row['file_size'],
                'md5' => (string)$row['md5'],
                'sha1' => (string)$row['sha1'],
                'package_guid' => (string)$row['package_guid'],
                'is_base_game' => (int)$row['is_base_game'],
                'is_compressed' => (int)$row['is_compressed'],
                'compression_flags' => (int)$row['compression_flags'],
                'import_count' => (int)$row['import_count'],
                'export_count' => (int)$row['export_count'],
            ];
        }

        $identity = \fed_ensure_identity($this->db);
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'INVENTORY_READ_BY_PEER',
            'Returned ' . count($files) . ' inventory row(s) after file ID '
            . $afterFileId . ' under the effective base-game policy.'
        );

        return [
            'ok' => true,
            'site' => [
                'site_id' => (string)$identity['site_id'],
                'site_name' => (string)$identity['site_name'],
                'site_url' => (string)$identity['site_url'],
            ],
            'policy' => $localRole === 'parent' ? \federation_parent_base_game_policy($this->db) : null,
            'files' => $files,
            'next_after_file_id' => $nextAfter,
            'complete' => count($rows) < $limit,
            'generated_at' => date('c'),
        ];
    }

    /** @param array<string,mixed> $peer @return array<string,mixed> */
    public function refreshFromParent(array $peer): array
    {
        $localRole = strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone')));
        $peerRole = strtolower(trim((string)($peer['peer_role'] ?? '')));
        if ($localRole !== 'child' || $peerRole !== 'parent') {
            throw new CatalogFederationApiException(
                'Only the paired parent may request this child to refresh its parent inventory.',
                403
            );
        }

        $result = $this->peerSync->pullFromParent((int)$peer['id']);
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'PARENT_INVENTORY_REFRESH_REQUESTED',
            'Refreshed ' . (int)$result['received'] . ' parent inventory row(s); removed '
            . (int)$result['removed_stale'] . ' stale row(s).'
        );

        return [
            'ok' => true,
            'received' => (int)$result['received'],
            'removed_stale' => (int)$result['removed_stale'],
            'pages' => (int)$result['pages'],
            'synchronized_at' => (string)$result['synchronized_at'],
        ];
    }
}
