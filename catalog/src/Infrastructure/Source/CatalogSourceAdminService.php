<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns game-source creation and source selection reads.
 * Why: Source validation/profile checks and persistence should not live in rendering pages.
 * Role: Infrastructure source administration service.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use RuntimeException;

final class CatalogSourceAdminService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/GameProfiles.php';
    }

    /** @param array<string,mixed> $input */
    public function add(array $input): int
    {
        $gameId = (int)($input['game_id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $type = (string)($input['source_type'] ?? 'local_path');
        $base = trim((string)($input['base_path'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));

        if ($gameId <= 0 || $name === '' || $base === '') {
            throw new RuntimeException('Game, source name, and path/URL are required.');
        }
        \gp_required_profile_for_game($this->db, $gameId);
        if (!in_array($type, ['local_path', 'http_mirror', 'redirect_server'], true)) {
            throw new RuntimeException('Invalid source type.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO ue_sources(game_id,name,source_type,base_path,notes) VALUES(?,?,?,?,?)'
        );
        $stmt->execute([$gameId, $name, $type, $base, $notes ?: null]);
        return $gameId;
    }

    /** @return list<array<string,mixed>> */
    public function configuredSources(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT s.*, g.name game_name, p.engine_key profile_engine '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'ORDER BY g.name, s.name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function gamesWithActiveProfiles(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,p.engine_key profile_engine '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeSources(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT s.*,g.name game_name,p.engine_key profile_engine '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE s.is_active=1 ORDER BY g.name,s.name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeHttpSources(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT s.id,s.name,s.source_type,s.base_path,g.name game_name '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'WHERE s.is_active=1 AND s.source_type IN ("http_mirror","redirect_server") '
            . 'ORDER BY g.name,s.name'
        );
    }
}
