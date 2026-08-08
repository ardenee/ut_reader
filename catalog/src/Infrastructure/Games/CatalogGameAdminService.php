<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns Game Admin reads, save validation, reset and delete lifecycle orchestration.
 * Why: Game/profile persistence and destructive catalog lifecycle operations should not live in the rendering controller.
 * Role: Infrastructure/application service over GameProfiles, CatalogGameLifecycleService and catalog statistics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

final class CatalogGameAdminService
{
    private readonly CatalogGameLifecycleService $lifecycle;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/GameProfiles.php';
        $this->lifecycle = new CatalogGameLifecycleService($db, $config);
    }

    /** @return list<array<string,mixed>> */
    public function profileChoices(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT * FROM ue_game_profiles WHERE is_active=1 '
            . 'ORDER BY COALESCE(profile_name,engine_key),engine_key,id'
        );
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        $stats = new PdoGameCatalogStats($this->db);
        $statsAvailable = $stats->available();

        $sql = 'SELECT g.*,p.id profile_id,p.profile_name,p.engine_key profile_engine,'
            . 'p.allowed_extensions_json,p.package_version_min,p.package_version_max,'
            . 'p.licensee_version_min,p.licensee_version_max,p.confidence_policy,p.notes profile_notes,'
            . ($statsAvailable ? 'COALESCE(gs.file_count,0)' : '0') . ' file_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . ($statsAvailable ? 'LEFT JOIN ue_game_catalog_stats gs ON gs.game_id=g.id ' : '')
            . 'ORDER BY g.name';
        $games = \catalog_all($this->db, $sql);

        $fileCounts = [];
        if (!$statsAvailable) {
            $fileCounts = $this->countMap(
                \catalog_all(
                    $this->db,
                    'SELECT game_id,COUNT(*) file_count FROM ue_files '
                    . 'WHERE game_id IS NOT NULL GROUP BY game_id'
                ),
                'game_id',
                'file_count'
            );
        }

        $unverifiedCounts = $this->countMap(
            \catalog_all(
                $this->db,
                'SELECT unverified_queue_game_id game_id,COUNT(*) unverified_count '
                . 'FROM ue_files WHERE game_id IS NULL '
                . 'AND unverified_queue_game_id IS NOT NULL GROUP BY unverified_queue_game_id'
            ),
            'game_id',
            'unverified_count'
        );
        $sourceCounts = $this->countMap(
            \catalog_all(
                $this->db,
                'SELECT game_id,COUNT(*) source_count FROM ue_sources GROUP BY game_id'
            ),
            'game_id',
            'source_count'
        );

        foreach ($games as &$game) {
            $gameId = (int)($game['id'] ?? 0);
            $baseFiles = $statsAvailable
                ? (int)($game['file_count'] ?? 0)
                : (int)($fileCounts[$gameId] ?? 0);
            $game['file_count'] = $baseFiles + (int)($unverifiedCounts[$gameId] ?? 0);
            $game['source_count'] = (int)($sourceCounts[$gameId] ?? 0);
        }
        unset($game);
        return $games;
    }

    /** @return list<array<string,mixed>> */
    public function sourcesForGame(int $gameId): array
    {
        return \catalog_all(
            $this->db,
            'SELECT * FROM ue_sources WHERE game_id=? ORDER BY name',
            [$gameId]
        );
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): int
    {
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $slug = $this->slug((string)($input['slug'] ?? $name));
        $profileId = (int)($input['profile_id'] ?? 0);
        $description = trim((string)($input['description'] ?? ''));
        if ($name === '' || $profileId <= 0) {
            throw new RuntimeException('Game name and game profile are required.');
        }
        $profile = \catalog_one(
            $this->db,
            'SELECT id FROM ue_game_profiles WHERE id=? AND is_active=1',
            [$profileId]
        );
        if (!$profile) {
            throw new RuntimeException('Selected active game profile not found.');
        }

        if ($id > 0) {
            $this->db->prepare(
                'UPDATE ue_games SET name=?,slug=?,description=?,profile_id=? WHERE id=?'
            )->execute([$name, $slug, $description ?: null, $profileId, $id]);
            return $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_games(name,slug,description,profile_id) VALUES(?,?,?,?)'
        );
        $statement->execute([$name, $slug, $description ?: null, $profileId]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{message:string,return_url:string,result:array<string,mixed>}
     */
    public function reset(int $gameId, bool $confirmed, ?callable $progress): array
    {
        if ($gameId <= 0 || !$confirmed) {
            throw new RuntimeException('Game reset confirmation is required.');
        }
        $result = $this->lifecycle->reset($gameId, $progress);
        $message = 'Reset ' . $result['game_name'] . ': removed '
            . $result['catalog_records'] . ' catalog file record(s), '
            . $result['pak_archives'] . ' PAK archive record(s), deleted '
            . $result['stored_files'] . ' stored file(s), and cleared '
            . \catalog_bytes($result['total_size']) . ' of recorded file data.'
            . $this->optimiseMessage($result);
        return [
            'message' => $message,
            'return_url' => 'game-manager.php?game_id=' . (int)$result['game_id'],
            'result' => $result,
        ];
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{message:string,return_url:string,result:array<string,mixed>}
     */
    public function delete(int $gameId, bool $confirmed, ?callable $progress): array
    {
        if ($gameId <= 0 || !$confirmed) {
            throw new RuntimeException('Game deletion confirmation is required.');
        }
        $result = $this->lifecycle->delete($gameId, $progress);
        $message = 'Deleted game ' . $result['game_name'] . ': removed '
            . $result['catalog_records'] . ' catalog file record(s), '
            . $result['pak_archives'] . ' PAK archive record(s), '
            . $result['sources'] . ' source definition(s), '
            . $result['base_game_rows'] . ' base-game protection row(s), and '
            . $result['stored_files'] . ' stored file(s).'
            . $this->optimiseMessage($result);
        return [
            'message' => $message,
            'return_url' => 'game-manager.php',
            'result' => $result,
        ];
    }

    private function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'game';
    }

    /** @return array<int,int> */
    private function countMap(array $rows, string $idColumn, string $countColumn): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $id = (int)($row[$idColumn] ?? 0);
            if ($id > 0) {
                $counts[$id] = (int)($row[$countColumn] ?? 0);
            }
        }
        return $counts;
    }

    private function optimiseMessage(array $result): string
    {
        $optimised = count((array)($result['optimised_tables'] ?? []));
        $failed = count((array)($result['optimise_failures'] ?? []));
        if ($failed > 0) {
            return ' Optimised ' . $optimised . ' table(s), with '
                . $failed . ' optimisation warning(s).';
        }
        return ' Optimised ' . $optimised . ' table(s).';
    }
}
