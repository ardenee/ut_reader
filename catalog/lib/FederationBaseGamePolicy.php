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

/**
 * SQL expression that identifies a package name in ue_base_game_files. The
 * optional game expression keeps same-named packages scoped to the selected game.
 */
function federation_base_game_package_exists_sql(string $packageSql, ?string $gameIdSql = null): string
{
    $bgStem = '(CASE WHEN LOCATE(".",COALESCE(policy_bg.original_name,""))>0 '
        . 'THEN LEFT(policy_bg.original_name,CHAR_LENGTH(policy_bg.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(policy_bg.original_name,".",-1))-1) '
        . 'ELSE COALESCE(policy_bg.original_name,"") END)';
    $sourceStem = '(CASE WHEN LOCATE(".",COALESCE(policy_src.original_name,""))>0 '
        . 'THEN LEFT(policy_src.original_name,CHAR_LENGTH(policy_src.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(policy_src.original_name,".",-1))-1) '
        . 'ELSE COALESCE(policy_src.original_name,"") END)';
    $gameSql = $gameIdSql !== null && trim($gameIdSql) !== ''
        ? ' AND policy_bg.game_id=' . $gameIdSql
        : '';

    return 'EXISTS (
        SELECT 1
        FROM ue_base_game_files policy_bg
        LEFT JOIN ue_files policy_src ON policy_src.id=policy_bg.source_file_id
        WHERE (
            LOWER(TRIM(COALESCE(policy_bg.package_name,"")))=LOWER(TRIM(' . $packageSql . '))
            OR LOWER(TRIM(' . $bgStem . '))=LOWER(TRIM(' . $packageSql . '))
            OR LOWER(TRIM(COALESCE(policy_src.package_name,"")))=LOWER(TRIM(' . $packageSql . '))
            OR LOWER(TRIM(' . $sourceStem . '))=LOWER(TRIM(' . $packageSql . '))
        )' . $gameSql . '
    )';
}

function federation_dependency_is_base_game_sql(string $fileAlias = 'f', string $dependencyAlias = 'd'): string
{
    return federation_base_game_package_exists_sql($dependencyAlias . '.required_package', $fileAlias . '.game_id');
}

function federation_request_item_is_base_game_sql(string $itemAlias = 'i'): string
{
    $localFileMatch = 'EXISTS (
        SELECT 1
        FROM ue_files policy_local
        JOIN ue_base_game_files policy_local_bg
          ON policy_local_bg.game_id=policy_local.game_id
         AND policy_local_bg.package_guid=policy_local.package_guid
        WHERE policy_local.id=' . $itemAlias . '.local_file_id
    )';

    return '(' . $localFileMatch
        . ' OR ' . federation_base_game_package_exists_sql($itemAlias . '.required_package')
        . ' OR LOWER(COALESCE(' . $itemAlias . '.status_message,"")) LIKE "%base-game%")';
}

function federation_visible_request_item_sql(PDO $db, string $itemAlias = 'i', ?array $parentPeer = null): string
{
    return federation_ignore_base_game_files($db, $parentPeer)
        ? 'NOT ' . federation_request_item_is_base_game_sql($itemAlias)
        : '1=1';
}

function federation_transfer_job_is_base_game_sql(string $jobAlias = 'j'): string
{
    $peerFileMatch = 'EXISTS (
        SELECT 1 FROM ue_federation_peer_files policy_pf
        WHERE policy_pf.peer_id=' . $jobAlias . '.peer_id
          AND policy_pf.remote_file_id=' . $jobAlias . '.remote_file_id
          AND COALESCE(policy_pf.is_base_game,0)=1
    )';
    $requestItemMatch = 'EXISTS (
        SELECT 1 FROM ue_federation_request_items policy_i
        WHERE policy_i.id=' . $jobAlias . '.remote_request_item_id
          AND ' . federation_request_item_is_base_game_sql('policy_i') . '
    )';
    $localFileMatch = 'EXISTS (
        SELECT 1
        FROM ue_files policy_job_file
        JOIN ue_base_game_files policy_job_bg
          ON policy_job_bg.game_id=policy_job_file.game_id
         AND policy_job_bg.package_guid=policy_job_file.package_guid
        WHERE policy_job_file.id=' . $jobAlias . '.local_file_id
    )';

    return '(' . $peerFileMatch . ' OR ' . $requestItemMatch . ' OR ' . $localFileMatch . ')';
}

function federation_visible_transfer_job_sql(PDO $db, string $jobAlias = 'j', ?array $parentPeer = null): string
{
    return federation_ignore_base_game_files($db, $parentPeer)
        ? 'NOT ' . federation_transfer_job_is_base_game_sql($jobAlias)
        : '1=1';
}

function federation_base_game_policy_label(PDO $db, ?array $parentPeer = null): string
{
    return federation_ignore_base_game_files($db, $parentPeer)
        ? 'Base-game files are excluded from all federation inventories, missing-file lists, requests, totals, reports and transfers.'
        : 'Base-game files participate in federation inventories, missing-file lists, requests, totals, reports and transfers.';
}
