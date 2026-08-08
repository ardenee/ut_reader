<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Re-imports one verified stored package while preserving rollback and dependency-reconciliation behavior.
 * Why: Filesystem staging, destructive catalog replacement, scanner execution and rollback form one maintenance use case and should not live in a procedural catalog/lib file.
 * Role: Infrastructure maintenance service preserving the existing compact reimport contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue;

final class CatalogFileMaintenanceReimportService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogFileMaintenanceCompactCore.php';
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function reimport(
        int $fileId,
        ?int $userId,
        ?callable $progress = null
    ): array {
        $snapshot = \catalog_file_maintenance_snapshot($this->db, $fileId, $this->config);
        $file = (array)$snapshot['file'];
        $storedPath = \catalog_file_maintenance_storage_path($this->config, $file);
        if ($storedPath === null || !is_file($storedPath)) {
            throw new RuntimeException('The stored package file is missing, so it cannot be re-imported.');
        }

        $sourceRelativePath = \catalog_file_maintenance_source_relative_path($snapshot);
        $scannerOriginalName = \scanner_original_name_from_source_relative($sourceRelativePath);
        if ($scannerOriginalName === '') {
            $scannerOriginalName = (string)$file['original_name'];
        }

        $suffix = '.reimport-' . bin2hex(random_bytes(8));
        $backupPath = $storedPath . $suffix . '.backup';
        $inputPath = $storedPath . $suffix . '.input';
        $oldMetadataPath = \catalog_file_maintenance_metadata_path(
            $this->config,
            (int)$file['game_id'],
            $fileId
        );
        $replacementFileId = 0;
        $replacementGameId = 0;
        \catalog_file_maintenance_emit(
            $progress,
            'reimport',
            0,
            'Verifying stored package ' . $file['original_name']
        );

        if (!@rename($storedPath, $backupPath)) {
            throw new RuntimeException('Could not stage the stored package for re-import.');
        }
        if (!@copy($backupPath, $inputPath)) {
            @rename($backupPath, $storedPath);
            throw new RuntimeException('Could not prepare a scanner copy of the stored package.');
        }

        try {
            $gameId = (int)$file['game_id'];
            $oldPackageName = (string)$file['package_name'];
            $affectedFileIds = \catalog_file_maintenance_affected_ids(
                $this->db,
                $gameId,
                $fileId,
                $oldPackageName
            );
            \catalog_file_maintenance_emit(
                $progress,
                'database',
                22,
                'Removing the old catalog record and its compact projections'
            );
            $this->db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);

            $result = \scanner_scan_uploaded_file(
                $this->db,
                $this->config,
                $gameId,
                $inputPath,
                $scannerOriginalName,
                $userId,
                true,
                $progress,
                false,
                ['source_relative_path' => $sourceRelativePath]
            );
            if (($result[0] ?? '') !== 'verified') {
                throw new RuntimeException((string)($result[2] ?? 'Stored package was not re-imported.'));
            }

            $replacementFileId = (int)$result[1];
            $replacementGameId = $gameId;
            $replacement = \catalog_one(
                $this->db,
                'SELECT package_name FROM ue_files WHERE id=?',
                [$replacementFileId]
            );
            $newPackageName = (string)($replacement['package_name'] ?? $oldPackageName);
            \catalog_file_maintenance_emit(
                $progress,
                'dependencies',
                99,
                'Refreshing references to the re-imported package'
            );
            \catalog_file_maintenance_refresh_ids(
                $this->db,
                $this->config,
                $affectedFileIds,
                $progress,
                99,
                100,
                'Refreshing affected dependency links'
            );

            $reconciliationJobId = CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                $replacementFileId,
                [$gameId],
                [$oldPackageName, $newPackageName],
                $this->config,
                $userId
            );

            @unlink($backupPath);
            if (is_file($oldMetadataPath)) {
                @unlink($oldMetadataPath);
            }
            return [
                'game_id' => $gameId,
                'file_id' => $replacementFileId,
                'old_file_id' => $fileId,
                'old_package_name' => $oldPackageName,
                'new_package_name' => $newPackageName,
                'original_name' => (string)($result[4]['source_relative_path'] ?? $scannerOriginalName),
                'reconciliation_job_id' => $reconciliationJobId,
                'message' => (string)$result[2]
                    . ($sourceRelativePath !== ''
                        ? '; reimport source=' . $sourceRelativePath
                        : '; reimport source unavailable, used stored filename metadata'),
            ];
        } catch (Throwable $error) {
            @unlink($inputPath);
            if ($replacementFileId > 0) {
                $this->db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$replacementFileId]);
                if ($replacementGameId > 0) {
                    $replacementMetadataPath = \catalog_file_maintenance_metadata_path(
                        $this->config,
                        $replacementGameId,
                        $replacementFileId
                    );
                    if (is_file($replacementMetadataPath)) {
                        @unlink($replacementMetadataPath);
                    }
                }
            }
            if (!\catalog_one($this->db, 'SELECT id FROM ue_files WHERE id=?', [$fileId])) {
                \catalog_file_maintenance_restore_snapshot($this->db, $snapshot, $this->config);
            }
            if (is_file($storedPath)) {
                @unlink($storedPath);
            }
            if (is_file($backupPath)) {
                @rename($backupPath, $storedPath);
            }
            try {
                \scanner_rebuild_game($this->db, $this->config, (int)$file['game_id']);
            } catch (Throwable $refreshError) {
                error_log(
                    '[UnrealDB reimport rollback] file_id=' . $fileId
                    . ' dependency refresh failed: ' . $refreshError->getMessage()
                );
            }
            throw $error;
        }
    }
}
