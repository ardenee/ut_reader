<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Removes verified catalog files and managed on-disk storage for one game while retaining the game configuration.
 * Why: This was historically the page-local gm_reset_game_files() implementation required by GameManagerLifecycle.php.
 * Role: Infrastructure cleanup collaborator used by reset and delete lifecycle orchestration.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogGameManagedFileCleanup
{
    private readonly CatalogGameStorageCleanup $storage;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogPackageAliases.php';
        $this->storage = new CatalogGameStorageCleanup($config);
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{game_id:int,game_name:string,catalog_records:int,stored_files:int,total_size:int}
     */
    public function resetManagedFiles(int $gameId, ?callable $progress = null): array
    {
        CatalogGameLifecycleProgress::emit(
            $progress,
            'prepare',
            0,
            1,
            1,
            'Preparing game reset…'
        );
        $game = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,COUNT(f.id) file_count,COALESCE(SUM(f.file_size),0) total_size '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_files f ON f.game_id=g.id '
            . 'WHERE g.id=? '
            . 'GROUP BY g.id,g.name,g.slug',
            [$gameId]
        );
        if (!$game) {
            throw new RuntimeException('Game not found.');
        }

        $fileIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            \catalog_all(
                $this->db,
                'SELECT id FROM ue_files WHERE game_id=? ORDER BY id',
                [$gameId]
            )
        );

        $storedFilesRemoved = $this->storage->removeManagedGameTree(
            (string)$game['slug'],
            $progress
        );

        \catalog_package_aliases_ensure($this->db);
        CatalogGameLifecycleProgress::emit(
            $progress,
            'database',
            0,
            max(1, count($fileIds)),
            84,
            'Removing package aliases and catalog records…'
        );

        $catalogRecordsRemoved = 0;
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ue_file_package_aliases WHERE game_id=?')
                ->execute([$gameId]);

            $chunks = array_chunk($fileIds, 100);
            $totalChunks = max(1, count($chunks));
            if ($chunks === []) {
                CatalogGameLifecycleProgress::emit(
                    $progress,
                    'database',
                    0,
                    0,
                    98,
                    'No catalog file records remain to delete.'
                );
            } else {
                foreach ($chunks as $chunkIndex => $chunk) {
                    $sql = 'DELETE FROM ue_files WHERE id IN ('
                        . implode(',', array_fill(0, count($chunk), '?')) . ')';
                    $statement = $this->db->prepare($sql);
                    $statement->execute($chunk);
                    $catalogRecordsRemoved += $statement->rowCount();

                    $doneChunks = $chunkIndex + 1;
                    $percent = 84 + (int)floor(($doneChunks / $totalChunks) * 14);
                    CatalogGameLifecycleProgress::emit(
                        $progress,
                        'database',
                        min(count($fileIds), $doneChunks * 100),
                        max(1, count($fileIds)),
                        $percent,
                        'Deleting catalog records batch '
                            . $doneChunks . '/' . $totalChunks
                    );
                }
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
            'done',
            1,
            1,
            100,
            'Game reset complete.'
        );

        return [
            'game_id' => (int)$game['id'],
            'game_name' => (string)$game['name'],
            'catalog_records' => $catalogRecordsRemoved,
            'stored_files' => $storedFilesRemoved,
            'total_size' => (int)$game['total_size'],
        ];
    }
}
