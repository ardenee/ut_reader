<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Coordinates destructive Game Manager reset and delete operations.
 * Why: Game lifecycle policy should have one owner instead of mixing controller globals, filesystem traversal, SQL cleanup and table maintenance.
 * Role: Infrastructure/application lifecycle service preserving the historical reset/delete result and progress contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue;

final class CatalogGameLifecycleService
{
    private readonly PdoCatalogGameTableMaintenance $tables;
    private readonly CatalogGameStorageCleanup $storage;
    private readonly CatalogGameManagedFileCleanup $managedFiles;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->tables = new PdoCatalogGameTableMaintenance($db);
        $this->storage = new CatalogGameStorageCleanup($config);
        $this->managedFiles = new CatalogGameManagedFileCleanup($db, $config);
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function reset(int $gameId, ?callable $progress = null): array
    {
        $result = $this->cleanupGame($gameId, $progress, 1, 76);
        $optimise = $this->tables->optimiseTables(
            $this->tables->tableList(false),
            $progress,
            78,
            96
        );
        $result['optimised_tables'] = $optimise['optimised'];
        $result['optimise_failures'] = $optimise['failed'];

        CatalogGameLifecycleProgress::emit(
            $progress,
            'reconcile',
            0,
            1,
            98,
            'Queueing zero-state catalogue projection reconciliation…'
        );
        $result['reconciliation_job_id'] = CatalogProjectionReconciliationQueue::enqueue(
            $this->db,
            0,
            [$gameId],
            [],
            $this->config
        );
        CatalogGameLifecycleProgress::emit(
            $progress,
            'done',
            1,
            1,
            100,
            'Game reset, database optimisation and projection reconciliation complete.'
        );
        return $result;
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function delete(int $gameId, ?callable $progress = null): array
    {
        $game = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,'
                . '(SELECT COUNT(*) FROM ue_sources s WHERE s.game_id=g.id) source_count '
                . 'FROM ue_games g WHERE g.id=?',
            [$gameId]
        );
        if (!$game) {
            throw new RuntimeException('Game not found.');
        }

        $result = $this->cleanupGame($gameId, $progress, 1, 68);
        CatalogGameLifecycleProgress::emit(
            $progress,
            'delete_game',
            0,
            1,
            70,
            'Deleting game configuration and source definitions…'
        );

        $baseGameRows = 0;
        $this->db->beginTransaction();
        try {
            if ($this->tables->exists('ue_base_game_files')) {
                $statement = $this->db->prepare(
                    'DELETE FROM ue_base_game_files WHERE game_id=?'
                );
                $statement->execute([$gameId]);
                $baseGameRows = $statement->rowCount();
            }

            $statement = $this->db->prepare('DELETE FROM ue_games WHERE id=?');
            $statement->execute([$gameId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The game could not be deleted.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        CatalogGameLifecycleProgress::emit(
            $progress,
            'delete_game',
            1,
            1,
            76,
            'Game configuration deleted; foreign-key projection rows were removed.'
        );
        $optimise = $this->tables->optimiseTables(
            $this->tables->tableList(true),
            $progress,
            78,
            99
        );

        $result['deleted_game_id'] = (int)$game['id'];
        $result['game_name'] = (string)$game['name'];
        $result['sources'] = (int)$game['source_count'];
        $result['base_game_rows'] = $baseGameRows;
        $result['optimised_tables'] = $optimise['optimised'];
        $result['optimise_failures'] = $optimise['failed'];
        CatalogGameLifecycleProgress::emit(
            $progress,
            'done',
            1,
            1,
            100,
            'Game deletion and database optimisation complete.'
        );
        return $result;
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    private function cleanupGame(
        int $gameId,
        ?callable $progress,
        int $startPercent,
        int $endPercent
    ): array {
        $unverifiedRows = $this->unverifiedRows($gameId);
        $unverifiedBytes = array_sum(array_column($unverifiedRows, 'file_size'));

        $pakCount = 0;
        $pakBytes = 0;
        if ($this->tables->exists('ue_pak_archives')) {
            $pak = \catalog_one(
                $this->db,
                'SELECT COUNT(*) archive_count,COALESCE(SUM(file_size),0) total_size '
                    . 'FROM ue_pak_archives WHERE game_id=?',
                [$gameId]
            ) ?: [];
            $pakCount = (int)($pak['archive_count'] ?? 0);
            $pakBytes = (int)($pak['total_size'] ?? 0);
        }

        $innerEnd = max($startPercent, $endPercent - 8);
        $mappedProgress = $progress === null
            ? null
            : static function (array $state) use ($progress, $startPercent, $innerEnd): void {
                $innerPercent = max(0, min(100, (int)($state['percent'] ?? 0)));
                CatalogGameLifecycleProgress::emit(
                    $progress,
                    (string)($state['stage'] ?? 'cleanup'),
                    (int)($state['done'] ?? 0),
                    max(1, (int)($state['total'] ?? 1)),
                    $startPercent
                        + (int)floor((($innerEnd - $startPercent) * $innerPercent) / 100),
                    (string)($state['message'] ?? 'Removing game files…')
                );
            };

        $result = $this->managedFiles->resetManagedFiles($gameId, $mappedProgress);
        CatalogGameLifecycleProgress::emit(
            $progress,
            'database_cleanup',
            0,
            max(1, count($unverifiedRows) + $pakCount),
            $innerEnd + 1,
            'Removing retained PAK records and game-associated staging rows…'
        );

        $stagedFilesRemoved = $this->storage->removeStagedRows($unverifiedRows);
        $extraRecordsRemoved = 0;
        $pakRecordsRemoved = 0;
        $this->db->beginTransaction();
        try {
            if ($this->tables->exists('ue_pak_archives')) {
                $statement = $this->db->prepare(
                    'DELETE FROM ue_pak_archives WHERE game_id=?'
                );
                $statement->execute([$gameId]);
                $pakRecordsRemoved = $statement->rowCount();
            }

            foreach (array_chunk(array_column($unverifiedRows, 'id'), 100) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $statement = $this->db->prepare(
                    'DELETE FROM ue_files WHERE id IN ('
                        . implode(',', array_fill(0, count($chunk), '?')) . ')'
                );
                $statement->execute($chunk);
                $extraRecordsRemoved += $statement->rowCount();
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        CatalogGameLifecycleProgress::emit(
            $progress,
            'database_cleanup',
            count($unverifiedRows) + $pakCount,
            max(1, count($unverifiedRows) + $pakCount),
            $endPercent,
            'All game file, PAK, and staging records have been removed.'
        );

        $result['catalog_records'] = (int)$result['catalog_records'] + $extraRecordsRemoved;
        $result['stored_files'] = (int)$result['stored_files'] + $stagedFilesRemoved;
        $result['total_size'] = (int)$result['total_size'] + $unverifiedBytes + $pakBytes;
        $result['unverified_records'] = $extraRecordsRemoved;
        $result['pak_archives'] = $pakRecordsRemoved;
        return $result;
    }

    /** @return list<array{id:int,relative_path:string,file_size:int}> */
    private function unverifiedRows(int $gameId): array
    {
        return array_map(
            static fn(array $row): array => [
                'id' => (int)$row['id'],
                'relative_path' => (string)($row['relative_path'] ?? ''),
                'file_size' => (int)($row['file_size'] ?? 0),
            ],
            \catalog_all(
                $this->db,
                'SELECT id,relative_path,file_size FROM ue_files '
                    . 'WHERE game_id IS NULL AND scan_status="unverified" '
                    . 'AND unverified_queue_game_id=? ORDER BY id',
                [$gameId]
            )
        );
    }
}
