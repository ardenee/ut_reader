<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

function federation_policy_bool(mixed $value, bool $default = true): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

/** @return array<string,mixed> */
function federation_parent_base_game_policy(PDO $db): array
{
    return [
        'ignore_base_game_files' => federation_policy_bool(fed_setting($db, 'ignore_base_game_files', '1'), true),
        'missing_dependency_exception' => true,
    ];
}

/** @return array<string,mixed> */
function federation_peer_permissions(array $peer): array
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

/**
 * Cache the policy advertised by the paired parent. The child cannot override
 * this value locally; it is refreshed by signed parent responses/inventory sync.
 */
function federation_cache_parent_base_game_policy(PDO $db, int $peerId, array $policy): void
{
    $peer = catalog_one($db, 'SELECT id,peer_role,permissions_json FROM ue_federation_peers WHERE id=?', [$peerId]);
    if (!$peer || (string)$peer['peer_role'] !== 'parent') {
        return;
    }

    $permissions = federation_peer_permissions($peer);
    $permissions['parent_policy'] = [
        'ignore_base_game_files' => federation_policy_bool($policy['ignore_base_game_files'] ?? true, true),
        'missing_dependency_exception' => true,
        'updated_at' => date('c'),
    ];
    $encoded = json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $db->prepare('UPDATE ue_federation_peers SET permissions_json=? WHERE id=?')->execute([$encoded, $peerId]);
}

/**
 * Parent mode uses the local setting. Child mode uses the signed policy cached
 * for its parent. The safe default is to ignore base-game files.
 */
function federation_ignore_base_game_files(PDO $db, ?array $parentPeer = null): bool
{
    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    if ($role !== 'child') {
        return federation_policy_bool(fed_setting($db, 'ignore_base_game_files', '1'), true);
    }

    $peer = $parentPeer;
    if (!$peer) {
        $peer = catalog_one(
            $db,
            'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id LIMIT 1'
        );
    }
    if (!$peer) {
        return true;
    }

    $permissions = federation_peer_permissions($peer);
    $policy = $permissions['parent_policy'] ?? null;
    if (!is_array($policy)) {
        return true;
    }
    return federation_policy_bool($policy['ignore_base_game_files'] ?? true, true);
}

function federation_base_game_policy_label(PDO $db, ?array $parentPeer = null): string
{
    return federation_ignore_base_game_files($db, $parentPeer)
        ? 'Base-game files are ignored in ordinary federation views and transfers. Missing-dependency matches remain included.'
        : 'Base-game files participate in ordinary federation inventories, totals, lists and transfers.';
}
