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
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
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
     * Each source package will republish its parser-owned UEDB5 metadata as it is reparsed. A final dependency pass still runs after every
     * selected-game package has been republished so provider selection sees the complete set.
     *
     * @return array<string,int>
     */
    public function resetDerivedState(int $gameId): array
    {
        return $this->withWriteLock(function () use ($gameId): array {
            $this->requireGame($gameId);
            $fileIds = $this->verifiedFileIds($gameId);
            $this->emit('reset', 5, 'Clearing selected-game UEDB5 dependency/provider projections.');

            $counts = [
                'dependency_links' => 0,
                'dependency_identity_rows' => 0,
                'dependency_summaries' => 0,
                'provider_rows' => 0,
                'coverage_provider_rows' => 0,
                'coverage_rows' => 0,
            ];

            $delete = $this->db->prepare(
                'DELETE e FROM ue_uedb5_dependency_edges e '
                . 'JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=?'
            );
            $delete->execute([$gameId]);
            $counts['dependency_links'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare(
                'DELETE p FROM ue_uedb5_dependency_packages p WHERE p.game_id=?'
            );
            $delete->execute([$gameId]);
            $counts['dependency_summaries'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare(
                'DELETE p FROM ue_uedb5_provider_keys p WHERE p.game_id=?'
            );
            $delete->execute([$gameId]);
            $counts['provider_rows'] = max(0, $delete->rowCount());

            $status = $this->db->prepare(
                'UPDATE ue_uedb5_migration_status SET dependency_policy=NULL,'
                . 'dependency_payload_sha256=NULL,dependency_completed_at=NULL,updated_at=NOW() '
                . 'WHERE game_id=?'
            );
            $status->execute([$gameId]);
            $counts['dependency_identity_rows'] = max(0, $status->rowCount());

            $delete = $this->db->prepare('DELETE FROM ue_package_provider_coverage_cache WHERE game_id=?');
            $delete->execute([$gameId]);
            $counts['coverage_provider_rows'] = max(0, $delete->rowCount());

            $delete = $this->db->prepare('DELETE FROM ue_package_coverage_cache WHERE game_id=?');
            $delete->execute([$gameId]);
            $counts['coverage_rows'] = max(0, $delete->rowCount());

            $this->emit(
                'reset',
                100,
                'Selected-game UEDB5 derived dependency state cleared for ' . count($fileIds) . ' verified package(s).'
            );
            return $counts + ['verified_files' => count($fileIds)];
        });
    }

    /** @return array<string,mixed> */
    public function prepareDependencies(int $gameId): array
    {
        return $this->withWriteLock(function () use ($gameId): array {
            $this->requireGame($gameId);
            $this->emit('providers', 5, 'Verifying UEDB5 package-provider projection before dependency resolution.');

            $counts = $this->db->prepare(
                'SELECT '
                . 'SUM(source_kind=1) primary_count,'
                . 'SUM(source_kind=2) alias_count,COUNT(*) total_count '
                . 'FROM ue_uedb5_provider_keys WHERE game_id=?'
            );
            $counts->execute([$gameId]);
            $row = $counts->fetch(PDO::FETCH_ASSOC) ?: [];

            $missing = $this->db->prepare(
                'SELECT COUNT(*) FROM ue_files f '
                . 'LEFT JOIN ue_uedb5_provider_keys p '
                . 'ON p.file_id=f.id AND p.game_id=f.game_id AND p.source_kind=1 AND p.source_id=f.id '
                . 'WHERE f.game_id=? AND f.scan_status="verified" AND p.file_id IS NULL'
            );
            $missing->execute([$gameId]);
            $missingPrimary = (int)($missing->fetchColumn() ?: 0);
            if ($missingPrimary > 0) {
                throw new RuntimeException(
                    'Full Sync UEDB5 provider projection is incomplete; missing primary providers=' . $missingPrimary . '.'
                );
            }

            $providers = [
                'primary'=>(int)($row['primary_count'] ?? 0),
                'aliases'=>(int)($row['alias_count'] ?? 0),
                'total'=>(int)($row['total_count'] ?? 0),
                'missing_primary'=>$missingPrimary,
            ];
            $this->emit(
                'providers',
                100,
                'UEDB5 package-provider projection ready: ' . $providers['primary']
                    . ' primary, ' . $providers['aliases'] . ' aliases.'
            );
            return [
                'ok'=>true,
                'game_id'=>$gameId,
                'providers'=>$providers,
                'message'=>'UEDB5 package providers verified for final dependency resolution.',
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
                'Verifying UEDB5 package dependency summaries for ' . count($fileIds) . ' package(s).'
            );
            $summaryStatement = $this->db->prepare(
                'SELECT COUNT(DISTINCT p.file_id) files,COUNT(*) summary_rows '
                . 'FROM ue_uedb5_dependency_packages p '
                . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
                . 'WHERE p.game_id=?'
            );
            $summaryStatement->execute([$gameId]);
            $summaries = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: ['files'=>0,'summary_rows'=>0];

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
                'message' => 'UEDB5 providers, dependency summaries and game counters finalized.',
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
