<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists and retrieves the parent federation policy cached on peer records.
 * Why: Signed parent-policy cache state is persistence logic and should not be mixed with effective-policy resolution.
 * Role: Infrastructure federation policy store preserving the existing permissions_json representation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use Throwable;

final class CatalogFederationParentPolicyStore
{
    private readonly CatalogFederationPolicySchemaGuard $schemaGuard;

    public function __construct(private readonly PDO $db, ?CatalogFederationPolicySchemaGuard $schemaGuard = null)
    {
        $this->schemaGuard = $schemaGuard ?? new CatalogFederationPolicySchemaGuard($db);
    }

    /** @return array<string,mixed> */
    public static function decodePermissions(array $peer): array
    {
        $raw = trim((string)($peer['permissions_json'] ?? ''));
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function activeParentPeer(): ?array
    {
        $this->schemaGuard->ensure();
        $statement = $this->db->query(
            'SELECT * FROM ue_federation_peers '
            . 'WHERE peer_role="parent" AND is_active=1 ORDER BY id LIMIT 1'
        );
        $peer = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($peer) ? $peer : null;
    }

    /** @return array<string,mixed>|null */
    public static function cachedPolicy(array $peer): ?array
    {
        $policy = self::decodePermissions($peer)['parent_policy'] ?? null;
        return is_array($policy) ? $policy : null;
    }

    /** @param array<string,mixed> $policy */
    public function cache(int $peerId, array $policy): void
    {
        $this->schemaGuard->ensure();
        $statement = $this->db->prepare(
            'SELECT id,peer_role,permissions_json FROM ue_federation_peers WHERE id=?'
        );
        $statement->execute([$peerId]);
        $peer = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($peer) || (string)($peer['peer_role'] ?? '') !== 'parent') {
            return;
        }

        $permissions = self::decodePermissions($peer);
        $permissions['parent_policy'] = [
            'ignore_base_game_files' => CatalogFederationBaseGamePolicyService::boolValue(
                $policy['ignore_base_game_files'] ?? true,
                true
            ),
            'missing_dependency_exception' => false,
            'updated_at' => date('c'),
        ];

        $encoded = json_encode(
            $permissions,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $update = $this->db->prepare('UPDATE ue_federation_peers SET permissions_json=? WHERE id=?');
        $update->execute([$encoded, $peerId]);
    }
}
