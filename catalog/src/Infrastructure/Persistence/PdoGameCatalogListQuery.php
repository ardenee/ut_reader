<?php
/**
 * Public game-list read model.
 *
 * The query prefers the compact per-game statistics projection and preserves
 * the historical fallback when that projection is unavailable.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope;

final class PdoGameCatalogListQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $stats = new PdoGameCatalogStats($this->db);
        if ($stats->available()) {
            $statement = $this->db->query(
                'SELECT g.id,g.name,g.slug,g.description,p.engine_key profile_engine,'
                . 'COALESCE(s.file_count,0) file_count,COALESCE(s.total_size,0) total_size,'
                . 'COALESCE(s.verified_count,0) verified_count,COALESCE(s.failed_count,0) failed_count,'
                . 'COALESCE(s.missing_dependency_count,0) missing_dependency_count,'
                . 'COALESCE(s.missing_base_game_dependency_count,0) missing_base_game_dependency_count,'
                . 'COALESCE(s.resolved_dependency_count,0) resolved_dependency_count,'
                . 's.updated_at dependency_stats_updated_at,'
                . 'GREATEST(0,CAST(COALESCE(s.dependency_count,0) AS SIGNED)'
                . '-CAST(COALESCE(s.missing_dependency_count,0) AS SIGNED)'
                . '-CAST(COALESCE(s.resolved_dependency_count,0) AS SIGNED)'
                . '-CAST(COALESCE(s.package_only_dependency_count,0) AS SIGNED)'
                . '-CAST(COALESCE(s.common_dependency_count,0) AS SIGNED)) unresolved_dependency_count '
                . 'FROM ue_games g '
                . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id '
                . 'ORDER BY g.name'
            );
            $games = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($games as &$game) {
                if (!Uedb5DependencyPublicationScope::isGameStatsCurrent(
                    $this->db, (int)$game['id'], (string)($game['profile_engine'] ?? '')
                )) {
                    $game['missing_dependency_count'] = null;
                    $game['missing_base_game_dependency_count'] = null;
                    $game['unresolved_dependency_count'] = null;
                    $game['resolved_dependency_count'] = null;
                }
            }
            unset($game);
            return $games;
        }

        $statement = $this->db->query(
            'SELECT g.id,g.name,g.slug,g.description,p.engine_key profile_engine,'
            . 'COUNT(f.id) file_count,COALESCE(SUM(f.file_size),0) total_size,'
            . 'COALESCE(SUM(f.scan_status="verified"),0) verified_count,'
            . 'COALESCE(SUM(f.scan_status="failed"),0) failed_count,'
            . 'NULL missing_dependency_count,NULL missing_base_game_dependency_count,'
            . 'NULL resolved_dependency_count,NULL dependency_stats_updated_at,'
            . 'NULL unresolved_dependency_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_files f ON f.game_id=g.id '
            . 'GROUP BY g.id,g.name,g.slug,g.description,p.id,p.engine_key '
            . 'ORDER BY g.name'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
