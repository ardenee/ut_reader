<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation base-game policy helper API for existing callers.
 * Why: Schema readiness, cached parent-policy persistence and effective policy resolution now live under Infrastructure.
 * Role: Thin compatibility facade; do not add federation policy persistence or resolution implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationBaseGamePolicyService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationParentPolicyStore;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPolicySchemaGuard;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPolicyValue;

function federation_policy_bool(mixed $value, bool $default = true): bool
{
    return CatalogFederationPolicyValue::bool($value, $default);
}

function federation_base_game_policy_ensure_schema(PDO $db): void
{
    (new CatalogFederationPolicySchemaGuard($db))->ensure();
}

/** @return array<string,mixed> */
function federation_parent_base_game_policy(PDO $db): array
{
    return (new CatalogFederationBaseGamePolicyService($db))->parentPolicy();
}

/** @return array<string,mixed> */
function federation_peer_permissions(array $peer): array
{
    return CatalogFederationParentPolicyStore::decodePermissions($peer);
}

/** @param array<string,mixed> $policy */
function federation_cache_parent_base_game_policy(PDO $db, int $peerId, array $policy): void
{
    (new CatalogFederationParentPolicyStore($db))->cache($peerId, $policy);
}

/** @param array<string,mixed>|null $parentPeer */
function federation_ignore_base_game_files(PDO $db, ?array $parentPeer = null): bool
{
    return (new CatalogFederationBaseGamePolicyService($db))->ignoreBaseGameFiles($parentPeer);
}

/** @param array<string,mixed>|null $parentPeer */
function federation_base_game_allowed(PDO $db, ?array $parentPeer = null): bool
{
    return (new CatalogFederationBaseGamePolicyService($db))->baseGameAllowed($parentPeer);
}

/** @param array<string,mixed> $row @param array<string,mixed>|null $parentPeer */
function federation_base_game_row_visible(PDO $db, array $row, ?array $parentPeer = null): bool
{
    return (new CatalogFederationBaseGamePolicyService($db))->rowVisible($row, $parentPeer);
}

/** @param list<array<string,mixed>> $rows @param array<string,mixed>|null $parentPeer @return list<array<string,mixed>> */
function federation_filter_base_game_rows(PDO $db, array $rows, ?array $parentPeer = null): array
{
    return (new CatalogFederationBaseGamePolicyService($db))->filterRows($rows, $parentPeer);
}

/**
 * Restore the original public SQL policy compatibility functions verbatim.
 *
 * The 6286f56f federation refactor inadvertently removed these helpers,
 * while the federation queue, request screens and transfer service still
 * call them. This is the original committed SQL from before that refactor,
 * not an invented policy or unscoped workaround.
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
