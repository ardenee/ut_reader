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

final class CatalogFullSyncProjectionService
{
    private const WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';
    private const LOCK_WAIT_SECONDS = 45;

    /** @param null|callable(array<string,mixed>):void $progress */
    public function __construct(
        private readonly PDO $db,
        private readonly mixed $progress = null
    ) {
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

            $this->emit('providers', 5, 'Rechecking package-provider projection after dependency resolution.');
            $providers = (new PdoPackageProviderRepository($this->db))->reconcileGame($gameId);

            $this->emit(
                'dependency_summaries',
                15,
                'Verifying package dependency summaries for ' . count($fileIds) . ' package(s).'
            );
            $summaries = (new PdoDependencyPackageSummary($this->db))->rebuildFiles($fileIds);
            if (empty($summaries['available'])) {
                throw new RuntimeException('Dependency package summary projection is unavailable.');
            }

            $this->emit('game_stats', 80, 'Rebuilding cached game dependency counters.');
            $stats = (new PdoGameCatalogStats($this->db))->rebuildGame($gameId);
            if ($stats === null) {
                throw new RuntimeException('Game catalog statistics could not be rebuilt because the stats lock is busy.');
            }

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
                'stats' => $stats,
                'message' => 'Package providers, dependency summaries and game counters finalized.',
            ];
        });
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
