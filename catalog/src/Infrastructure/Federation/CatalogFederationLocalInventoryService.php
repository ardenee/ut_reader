<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the local federation inventory and pushes it to the active parent.
 * Why: Local inventory projection, signed push transport and parent policy caching are one outbound federation responsibility.
 * Role: Infrastructure federation service replacing procedural local inventory build/push helpers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationLocalInventoryService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPeerSecret.php';
        require_once $root . '/lib/BaseGameProtection.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
    }

    /** @return array<string,mixed> */
    public function buildPayload(): array
    {
        \base_game_ensure($this->db);
        $identity = \fed_ensure_identity($this->db);
        $ignoreBaseGame = \federation_ignore_base_game_files($this->db);
        $files = \catalog_all(
            $this->db,
            'SELECT f.*, g.name game_name, p.engine_key profile_engine,
                    CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END is_base_game
             FROM ue_files f
             JOIN ue_games g ON g.id=f.game_id
             LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid
             WHERE f.scan_status="verified"'
                . ($ignoreBaseGame ? ' AND bg.id IS NULL' : '') . '
             ORDER BY f.id'
        );

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'file_id' => (int)$file['id'],
                'game_id' => (int)$file['game_id'],
                'game_name' => (string)$file['game_name'],
                'engine_key' => (string)($file['profile_engine'] ?? ''),
                'package_name' => (string)$file['package_name'],
                'original_name' => (string)$file['original_name'],
                'extension' => (string)$file['extension'],
                'file_size' => (int)$file['file_size'],
                'md5' => (string)$file['md5'],
                'sha1' => (string)$file['sha1'],
                'package_guid' => (string)$file['package_guid'],
                'is_base_game' => (int)$file['is_base_game'],
                'is_compressed' => (int)($file['is_compressed'] ?? 0),
                'compression_flags' => (int)($file['compression_flags'] ?? 0),
                'import_count' => (int)$file['import_count'],
                'export_count' => (int)$file['export_count'],
            ];
        }

        return [
            'site' => $identity,
            'policy' => strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone'))) === 'parent'
                ? \federation_parent_base_game_policy($this->db)
                : null,
            'generated_at' => date('c'),
            'file_count' => count($out),
            'base_game_excluded' => $ignoreBaseGame,
            'files' => $out,
        ];
    }

    /** @return array<string,mixed> */
    public function pushToParent(int $peerId): array
    {
        $parent = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1',
            [$peerId]
        );
        if (!$parent) {
            throw new RuntimeException('Active parent peer not found.');
        }
        $storedSecret = \federation_peer_stored_signing_secret($this->db, $parent);

        $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/inventory-push.php';
        $result = \fed_http_post_signed(
            $url,
            (string)\fed_setting($this->db, 'site_id', ''),
            $storedSecret,
            $this->buildPayload()
        );
        if (is_array($result['policy'] ?? null)) {
            \federation_cache_parent_base_game_policy($this->db, (int)$parent['id'], $result['policy']);
        }
        \fed_log(
            $this->db,
            (int)$parent['id'],
            null,
            !empty($result['ok']) ? 'INFO' : 'ERROR',
            'INVENTORY_PUSH_SEND',
            json_encode($result, JSON_UNESCAPED_SLASHES)
        );
        return $result;
    }

    /** @return array<string,mixed> */
    public function autoPushToParent(): array
    {
        if ((string)\fed_setting($this->db, 'site_role', 'standalone') !== 'child') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'site is not child'];
        }
        $parent = \catalog_one(
            $this->db,
            'SELECT id FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id LIMIT 1'
        );
        if (!$parent) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'no active parent peer'];
        }
        return $this->pushToParent((int)$parent['id']);
    }
}
