<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for federation base-game policy.
 * Why: Federation callers need one policy interpretation/cache boundary while schema ownership remains migration-only.
 * Role: Shared federation policy compatibility layer; runtime verifies required schema but never creates or alters it.
 */
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

/**
 * Verify the federation base-game policy schema required by normal runtime.
 * Schema creation/repair is owned by install.sql and catalog migrations.
 */
function federation_base_game_policy_ensure_schema(PDO $db): void
{
    static $ready = [];
    $connectionId = spl_object_id($db);
    if (!empty($ready[$connectionId])) {
        return;
    }

    $settingsTable = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.tables '
        . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_settings"'
    )->fetchColumn();
    $peerFilesTable = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.tables '
        . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files"'
    )->fetchColumn();
    $columnExists = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.columns '
        . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files" '
        . 'AND column_name="is_base_game"'
    )->fetchColumn();
    $indexExists = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.statistics '
        . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files" '
        . 'AND index_name="idx_ue_federation_peer_files_base_game"'
    )->fetchColumn();

    if ($settingsTable === 0 || $peerFilesTable === 0 || $columnExists === 0 || $indexExists === 0) {
        throw new RuntimeException(
            'Federation base-game policy schema is incomplete. Run php catalog/bin/migrate.php migrate.'
        );
    }

    $ready[$connectionId] = true;
}

/** @return array<string,mixed> */
function federation_parent_base_game_policy(PDO $db): array
{
    federation_base_game_policy_ensure_schema($db);
    return [
        'ignore_base_game_files' => federation_policy_bool(fed_setting($db, 'ignore_base_game_files', '1'), true),
        'missing_dependency_exception' => false,
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
    federation_base_game_policy_ensure_schema($db);
    $peer = catalog_one($db, 'SELECT id,peer_role,permissions_json FROM ue_federation_peers WHERE id=?', [$peerId]);
    if (!$peer || (string)$peer['peer_role'] !== 'parent') {
        return;
    }

    $permissions = federation_peer_permissions($peer);
    $permissions['parent_policy'] = [
        'ignore_base_game_files' => federation_policy_bool($policy['ignore_base_game_files'] ?? true, true),
        'missing_dependency_exception' => false,
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
    federation_base_game_policy_ensure_schema($db);
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

function federation_base_game_allowed(PDO $db, ?array $parentPeer = null): bool
{
    return !federation_ignore_base_game_files($db, $parentPeer);
}

function federation_base_game_row_visible(PDO $db, array $row, ?array $parentPeer = null): bool
{
    return federation_base_game_allowed($db, $parentPeer) || empty($row['is_base_game']);
}

/** @return list<array<string,mixed>> */
function federation_filter_base_game_rows(PDO $db, array $rows, ?array $parentPeer = null): array
{
    if (federation_base_game_allowed($db, $parentPeer)) {
        return array_values($rows);
    }
    return array_values(array_filter(
        $rows,
        static fn(mixed $row): bool => is_array($row) && empty($row['is_base_game'])
    ));
}
