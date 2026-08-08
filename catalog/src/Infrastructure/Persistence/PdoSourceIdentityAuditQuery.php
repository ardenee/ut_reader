<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads mounted-source identity mismatches for Source Identity Repair.
 * Why: UE4/UE5 source-path fallback lookup and canonical identity derivation belong in a read model using shared naming policy.
 * Role: Infrastructure read model over CatalogSourceIdentityNaming.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Identity\CatalogSourceIdentityNaming;

final class PdoSourceIdentityAuditQuery
{
    private readonly CatalogSourceIdentityNaming $naming;

    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->naming = new CatalogSourceIdentityNaming();
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,UPPER(COALESCE(p.engine_key,"")) engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id ORDER BY g.name'
        );
    }

    /** @return array<string,mixed>|null */
    public function selectedGame(int $gameId): ?array
    {
        foreach ($this->games() as $game) {
            if ((int)$game['id'] === $gameId) {
                return $game;
            }
        }
        return null;
    }

    public function repairSupported(?array $game): bool
    {
        return $game !== null && in_array((string)$game['engine_key'], ['UE4', 'UE5'], true);
    }

    /** @return list<array<string,mixed>> */
    public function mismatches(int $gameId): array
    {
        $files = \catalog_all(
            $this->db,
            'SELECT f.id,f.package_name,f.original_name,f.source_relative_path,f.detected_engine_key,p.engine_key profile_engine'
            . ' FROM ue_files f'
            . ' JOIN ue_games g ON g.id=f.game_id'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id'
            . ' WHERE f.game_id=? AND f.scan_status="verified"'
            . ' ORDER BY f.package_name,f.id',
            [$gameId]
        );

        $mismatches = [];
        foreach ($files as $file) {
            $engineKey = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
            if ($engineKey === '') {
                $engineKey = strtoupper(trim((string)($file['profile_engine'] ?? '')));
            }
            $sourcePath = $this->sourcePath($file);
            $canonical = $this->naming->packageName(
                $engineKey,
                $sourcePath,
                (string)$file['original_name']
            );
            if ($canonical === '' || strcasecmp((string)$file['package_name'], $canonical) === 0) {
                continue;
            }
            $file['canonical_package_name'] = $canonical;
            $file['canonical_source_path'] = $sourcePath;
            $mismatches[] = $file;
        }
        return $mismatches;
    }

    /** @param array<string,mixed> $file */
    private function sourcePath(array $file): string
    {
        $path = $this->naming->path((string)($file['source_relative_path'] ?? ''));
        if ($path !== '') {
            return $path;
        }

        $location = \catalog_one(
            $this->db,
            'SELECT source_relative_path FROM ue_file_locations '
            . 'WHERE file_id=? AND source_relative_path<>"" ORDER BY id LIMIT 1',
            [(int)$file['id']]
        );
        return $this->naming->path((string)($location['source_relative_path'] ?? ''));
    }
}
