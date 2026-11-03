<?php
/** Engine-policy-aware read gate for published UEDB5 dependency projections.
 * Both edges and package summaries are historical projections; consumers must
 * reject a source row until its owner has current game policy and payload SHA.
 * This class does not change tables or labels and never writes to the DB.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;

final class Uedb5DependencyPublicationScope
{
    private static function checkedAlias(string $alias): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid dependency SQL alias.');
        }
        return $alias;
    }

    private static function enginePolicySql(string $profileAlias): string
    {
        $sql = 'CASE UPPER(' . self::checkedAlias($profileAlias) . '.engine_key)';
        foreach (['UE1', 'UE2', 'UE3', 'UE4', 'UE5'] as $engine) {
            $policy = Uedb5GameDependencyPassService::policyForEngine($engine);
            $sql .= " WHEN '" . $engine . "' THEN '" . $policy . "'";
        }
        return $sql . ' ELSE NULL END';
    }

    /**
     * Add to FROM ... ue_uedb5_dependency_edges <alias>; excludes all stale
     * edges without a duplicate/ambiguous owner row. Aliases are fixed here.
     */
    public static function joinCurrentEdges(string $alias = 'e'): string
    {
        return self::currentJoin(self::checkedAlias($alias));
    }

    /** Same freshness gate for ue_uedb5_dependency_packages summary rows. */
    public static function joinCurrentPackages(string $alias = 'p'): string
    {
        return self::currentJoin(self::checkedAlias($alias));
    }

    private static function currentJoin(string $sourceAlias): string
    {
        return ' JOIN ue_uedb5_files fresh_v ON fresh_v.file_id=' . $sourceAlias . '.file_id'
            . ' AND fresh_v.format_version=5'
            . ' JOIN ue_files fresh_f ON fresh_f.id=fresh_v.file_id'
            . ' AND fresh_f.game_id=fresh_v.game_id AND fresh_f.scan_status="verified"'
            . ' JOIN ue_games fresh_g ON fresh_g.id=fresh_f.game_id'
            . ' JOIN ue_game_profiles fresh_p ON fresh_p.id=fresh_g.profile_id'
            . ' AND fresh_p.is_active=1'
            . ' JOIN ue_uedb5_migration_status fresh_s ON fresh_s.file_id=fresh_v.file_id'
            . ' AND fresh_s.game_id=fresh_v.game_id'
            . ' AND fresh_s.dependency_completed_at IS NOT NULL'
            . ' AND fresh_s.dependency_payload_sha256=fresh_v.payload_sha256'
            . ' AND fresh_s.dependency_policy=(' . self::enginePolicySql('fresh_p') . ') ';
    }

    /**
     * Fail closed for a game if even one verified file lacks the engine's
     * current policy and exact source payload. The LIMIT 1 query returns
     * quickly during a partial rollout; no table modifications are required.
     */
    public static function isGameCurrent(\PDO $db, int $gameId, string $engineKey): bool
    {
        $engineKey = strtoupper(trim($engineKey));
        if ($gameId < 1 || !in_array($engineKey, ['UE1','UE2','UE3','UE4','UE5'], true)) {
            return false;
        }
        $policy = Uedb5GameDependencyPassService::policyForEngine($engineKey);
        $s = $db->prepare(
            'SELECT f.id FROM ue_files f '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'LEFT JOIN ue_uedb5_migration_status ms ON ms.file_id=f.id AND ms.game_id=f.game_id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND '
            . '(v.file_id IS NULL OR v.format_version<>5 OR v.payload_sha256 IS NULL '
            . 'OR ms.dependency_completed_at IS NULL '
            . 'OR ms.dependency_policy IS NULL OR ms.dependency_policy<>? '
            . 'OR ms.dependency_payload_sha256 IS NULL '
            . 'OR ms.dependency_payload_sha256<>v.payload_sha256) LIMIT 1'
        );
        $s->execute([$gameId, $policy]);
        return $s->fetchColumn() === false;
    }

    /** A cached aggregate is trustworthy only after the last current publish. */
    public static function isGameStatsCurrent(\PDO $db, int $gameId, string $engineKey): bool
    {
        if (!self::isGameCurrent($db, $gameId, $engineKey)) {
            return false;
        }
        $s = $db->prepare(
            'SELECT COUNT(f.id) verified_files, MAX(gs.updated_at) cached_at, '
            . 'MAX(gs.dependency_count) cached_dependencies,'
            . 'MAX(gs.missing_dependency_count) cached_missing,'
            . 'MAX(gs.resolved_dependency_count) cached_resolved,'
            . 'MAX(ms.dependency_completed_at) last_published '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_catalog_stats gs ON gs.game_id=g.id '
            . 'LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified" '
            . 'LEFT JOIN ue_uedb5_migration_status ms ON ms.file_id=f.id AND ms.game_id=f.game_id '
            . 'WHERE g.id=?'
        );
        $s->execute([$gameId]);
        $row = $s->fetch(\PDO::FETCH_ASSOC) ?: [];
        $verified = (int)($row['verified_files'] ?? 0);
        $cachedAt = (string)($row['cached_at'] ?? '');
        $lastPublished = (string)($row['last_published'] ?? '');
        if ($verified === 0) {
            // Empty active game does not prove a previously cached nonzero
            // dependency count has been cleared after file removals.
            return $cachedAt !== ''
                && $row['cached_dependencies'] !== null
                && $row['cached_missing'] !== null
                && $row['cached_resolved'] !== null
                && (int)$row['cached_dependencies'] === 0
                && (int)$row['cached_missing'] === 0
                && (int)$row['cached_resolved'] === 0;
        }
        return $cachedAt !== '' && $lastPublished !== ''
            && strcmp($cachedAt, $lastPublished) >= 0;
    }

    /** True only if every configured game has current dependency aggregates. */
    public static function allGameStatsCurrent(\PDO $db): bool
    {
        $games = $db->query(
            'SELECT g.id,p.engine_key FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($games as $game) {
            if (!self::isGameStatsCurrent(
                $db, (int)$game['id'], (string)($game['engine_key'] ?? '')
            )) {
                return false;
            }
        }
        return true;
    }

    /**
     * Correlated condition for joins/search/filter queries which already have
     * their own table aliases. No new outer SQL parameters or aliases needed.
     */
    public static function currentOwnerPredicate(string $sourceAlias): string
    {
        return self::currentIdPredicate(self::checkedAlias($sourceAlias) . '.file_id');
    }

    /** Allows a plain ue_files alias (id, not file_id) to be checked. */
    public static function currentFilePredicate(string $fileAlias): string
    {
        return self::currentIdPredicate(self::checkedAlias($fileAlias) . '.id');
    }

    private static function currentIdPredicate(string $fileIdExpression): string
    {
        return 'EXISTS (SELECT 1 FROM ue_uedb5_files current_v'
            . ' JOIN ue_files current_f ON current_f.id=current_v.file_id'
            . ' AND current_f.game_id=current_v.game_id AND current_f.scan_status="verified"'
            . ' JOIN ue_games current_g ON current_g.id=current_f.game_id'
            . ' JOIN ue_game_profiles current_p ON current_p.id=current_g.profile_id'
            . ' AND current_p.is_active=1'
            . ' JOIN ue_uedb5_migration_status current_s ON current_s.file_id=current_v.file_id'
            . ' AND current_s.game_id=current_v.game_id'
            . ' AND current_s.dependency_completed_at IS NOT NULL'
            . ' AND current_s.dependency_policy=(' . self::enginePolicySql('current_p') . ')'
            . ' AND current_s.dependency_payload_sha256=current_v.payload_sha256'
            . ' WHERE current_v.file_id=' . $fileIdExpression
            . ' AND current_v.format_version=5)';
    }
}
