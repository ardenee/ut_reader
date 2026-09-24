<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finalizes game-wide projections around the Full Sync dependency pass.
 * Why: Full Sync deliberately defers per-package reconciliation until every package identity has been rebuilt; provider,
 *      dependency-summary and game-stat projections therefore need explicit bounded game-level synchronization.
 * Role: Infrastructure maintenance service for Full Sync projection preparation and finalization.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageCoverageCache;

final class CatalogFullSyncProjectionService
{
    private const WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';
    private const LOCK_WAIT_SECONDS = 45;
    private const STATS_RETRY_COUNT = 40;
    private const STATS_RETRY_DELAY_US = 250000;

    /** @param null|callable(array<string,mixed>):void $progress */
    public function __construct(
        private readonly PDO $db,
        private readonly mixed $progress = null
    ) {
    }

    /**
     * Remove dependency/provider projections owned by one game before a Full
     * Sync source pass. Stable ue_files identities, source paths, locations and
     * upload provenance are deliberately untouched.
     *
     * Each source package will republish its parser-owned format-3 metadata with
     * unresolved dependency rows. Dependency matching starts only after every
     * selected-game package has been reparsed.
     *
     * @return array<string,int>
     */
    public function resetDerivedState(int $gameId): array
    {
        return $this->withWriteLock(function () use ($gameId): array {
            $this->requireGame($gameId);
            $fileIds = $this->verifiedFileIds($gameId);
            $this->emit('reset', 5, 'Clearing selected-game dependency and provider projections.');

            $counts = [
                'dependency_links' => 0,
                'dependency_summaries' => 0,
                'provider_rows' => 0,
                'coverage_provider_rows' => 0,
                'coverage_rows' => 0,
            ];

            $delete = $this->db->prepare(
                'DELETE l FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id WHERE f.game_id=?'
            );
            $delete->execute([$gameId]);
            $counts['dependency_links'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare(
                'DELETE s FROM ue_dependency_package_summaries s JOIN ue_files f ON f.id=s.file_id WHERE f.game_id=?'
            );
            $delete->execute([$gameId]);
            $counts['dependency_summaries'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare('DELETE FROM ue_package_provider_coverage_cache WHERE game_id=?');
            $delete->execute([$gameId]);
            $counts['coverage_provider_rows'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare('DELETE FROM ue_package_coverage_cache WHERE game_id=?');
            $delete->execute([$gameId]);
            $counts['coverage_rows'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare('DELETE FROM ue_package_providers WHERE game_id=?');
            $delete->execute([$gameId]);
            $counts['provider_rows'] = max(0, $delete->rowCount());

            $this->emit(
                'reset',
                100,
                'Selected-game derived dependency state cleared for ' . count($fileIds) . ' verified package(s).'
            );
            return $counts + ['verified_files' => count($fileIds)];
        });
    }

    /** @return array<string,mixed> */
    public function prepareDependencies(int $gameId): array
    {
        return $this->withWriteLock(function () use ($gameId): array {
            $this->requireGame($gameId);
            $this->emit('providers', 5, 'Rebuilding package-provider projection before dependency resolution.');
            $providers = (new PdoPackageProviderRepository($this->db))->reconcileGame($gameId);
            $this->emit(
                'providers',
                100,
                'Package-provider projection ready: ' . (int)$providers['primary']
                    . ' primary, ' . (int)$providers['aliases'] . ' aliases.'
            );
            return [
                'ok' => true,
                'game_id' => $gameId,
                'providers' => $providers,
                'message' => 'Package providers rebuilt for final dependency resolution.',
            ];
        });
    }

    /** @return array<string,mixed> */
    public function finalize(int $gameId): array
    {
        return $this->withWriteLock(function () use ($gameId): array {
            $this->requireGame($gameId);
            $fileIds = $this->verifiedFileIds($gameId);

            // Provider projection was built once after the complete source pass.
            // Dependency matching cannot change package/provider identity, so a
            // second game-wide reconciliation here is redundant.
            $providers = ['primary' => 0, 'aliases' => 0, 'total' => 0, 'reused' => true];

            $this->emit(
                'dependency_summaries',
                5,
                'Verifying package dependency summaries for ' . count($fileIds) . ' package(s).'
            );
            $summaries = (new PdoDependencyPackageSummary($this->db))->rebuildFiles($fileIds);
            if (empty($summaries['available'])) {
                throw new RuntimeException('Dependency package summary projection is unavailable.');
            }

            $this->emit('package_coverage', 70, 'Rebuilding cached selected-game package object coverage.');
            $coverage = (new PdoPackageCoverageCache($this->db))->rebuildGame(
                $gameId,
                function (int $done, int $total, string $package): void {
                    $percent = $total > 0 ? 70 + (int)floor(($done / $total) * 20) : 90;
                    $this->emit('package_coverage', $percent, 'Caching object coverage ' . $done . '/' . $total . ': ' . $package);
                }
            );

            $this->emit('game_stats', 90, 'Rebuilding cached game dependency counters.');
            $stats = $this->rebuildStats($gameId);

            $this->emit(
                'complete',
                100,
                'Full Sync projections finalized: missing dependencies=' . (int)($stats['missing_dependency_count'] ?? 0)
                    . ', missing packages=' . (int)($stats['missing_package_count'] ?? 0) . '.'
            );
            return [
                'ok' => true,
                'game_id' => $gameId,
                'verified_files' => count($fileIds),
                'providers' => $providers,
                'summary_files' => (int)($summaries['files'] ?? 0),
                'summary_rows' => (int)($summaries['summary_rows'] ?? 0),
                'coverage_packages' => (int)($coverage['packages'] ?? 0),
                'stats' => $stats,
                'message' => 'Package providers, dependency summaries and game counters finalized.',
            ];
        });
    }

    /** @return array<string,int> */
    private function rebuildStats(int $gameId): array
    {
        $statsStore = new PdoGameCatalogStats($this->db);
        for ($attempt = 1; $attempt <= self::STATS_RETRY_COUNT; $attempt++) {
            $stats = $statsStore->rebuildGame($gameId);
            if ($stats !== null) {
                return $stats;
            }
            if ($attempt < self::STATS_RETRY_COUNT) {
                $this->emit(
                    'game_stats',
                    80 + (int)floor(($attempt / self::STATS_RETRY_COUNT) * 15),
                    'Waiting for an existing game-stat refresh to release its lock ('
                        . $attempt . '/' . self::STATS_RETRY_COUNT . ').'
                );
                usleep(self::STATS_RETRY_DELAY_US);
            }
        }
        throw new RuntimeException('Game catalog statistics could not be rebuilt because the stats lock remained busy.');
    }

    private function requireGame(int $gameId): void
    {
        if ($gameId < 1) {
            throw new RuntimeException('A valid game ID is required for Full Sync projection maintenance.');
        }
        $statement = $this->db->prepare('SELECT id FROM ue_games WHERE id=?');
        $statement->execute([$gameId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Full Sync game no longer exists.');
        }
    }

    /** @return list<int> */
    private function verifiedFileIds(int $gameId): array
    {
        $statement = $this->db->prepare(
            'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY id'
        );
        $statement->execute([$gameId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function withWriteLock(callable $operation): mixed
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([self::WRITE_LOCK, self::LOCK_WAIT_SECONDS]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another catalog maintenance task is still running.');
        }

        try {
            return $operation();
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::WRITE_LOCK]);
            } catch (Throwable) {
                // The database connection also releases advisory locks when it closes.
            }
        }
    }

    private function emit(string $stage, int $percent, string $message): void
    {
        if ($this->progress === null) {
            return;
        }
        $percent = max(0, min(100, $percent));
        ($this->progress)([
            'stage' => $stage,
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'message' => $message,
        ]);
    }
}
