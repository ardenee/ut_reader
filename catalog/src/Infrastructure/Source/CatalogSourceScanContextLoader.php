<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves and validates the source/game/profile context required by a local source scan.
 * Why: Source lookup, local-source validation, profile resolution and base-path validation are bootstrap concerns rather than scan-loop orchestration.
 * Role: Infrastructure source-scan collaborator; matching, parsing, importing and progress semantics remain owned by their existing collaborators.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use RuntimeException;

final class CatalogSourceScanContextLoader
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/GameProfiles.php';
    }

    /**
     * @return array{
     *   source:array<string,mixed>,
     *   profile:array<string,mixed>,
     *   profile_engine:string,
     *   base_path:string
     * }
     */
    public function load(int $sourceId): array
    {
        $source = \catalog_one(
            $this->db,
            'SELECT s.*,g.name game_name,g.slug game_slug,p.engine_key profile_engine '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?',
            [$sourceId]
        );
        if (!$source) {
            throw new RuntimeException('Source not found.');
        }
        if ((string)$source['source_type'] !== 'local_path') {
            throw new RuntimeException('Only local folder sources can be scanned by this job.');
        }

        $profile = \gp_required_profile_for_game($this->db, (int)$source['game_id']);
        $profileEngine = strtoupper((string)$profile['engine_key']);
        $basePath = rtrim((string)$source['base_path'], DIRECTORY_SEPARATOR);
        if (!is_dir($basePath) || !is_readable($basePath)) {
            throw new RuntimeException('Source path is not readable: ' . $basePath);
        }

        return [
            'source' => $source,
            'profile' => $profile,
            'profile_engine' => $profileEngine,
            'base_path' => $basePath,
        ];
    }
}
