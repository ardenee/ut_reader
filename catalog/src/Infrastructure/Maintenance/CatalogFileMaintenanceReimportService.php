<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Re-parses one verified stored package in place while preserving its stable catalog identity.
 * Why: Maintenance rescans must refresh parser/compact metadata without deleting ue_files rows and cascading unrelated
 *      download, PAK, federation, source-fingerprint or asset-registry relationships.
 * Role: Infrastructure maintenance service preserving stable file identity, rollback and dependency reconciliation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

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
        ?callable $progress = null,
        bool $deferDependencyRefresh = false
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

        $inputPath = $storedPath . '.reimport-' . bin2hex(random_bytes(8)) . '.input';
        $support = new CatalogFileMaintenanceSupport($this->db, $this->config);
        \catalog_file_maintenance_emit(
            $progress,
            'reimport',
            0,
            'Verifying stored package ' . $file['original_name'] . ' without changing its catalog ID'
        );

        if (!@copy($storedPath, $inputPath)) {
            throw new RuntimeException('Could not prepare a scanner copy of the stored package.');
        }

        try {
            $gameId = (int)$file['game_id'];
            $oldPackageName = (string)$file['package_name'];
            $affectedFileIds = \catalog_file_maintenance_affected_ids(
                $this->db,
                $gameId,
                $fileId,
                $oldPackageName,
                $deferDependencyRefresh
            );

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
                [
                    'source_relative_path' => $sourceRelativePath,
                    'maintenance_replace_file_id' => $fileId,
                    'defer_dependency_rebuild' => $deferDependencyRefresh,
                ]
            );
            if (($result[0] ?? '') !== 'verified') {
                throw new RuntimeException((string)($result[2] ?? 'Stored package was not refreshed.'));
            }
            if ((int)($result[1] ?? 0) !== $fileId) {
                throw new RuntimeException('Maintenance refresh unexpectedly changed the stable catalog file ID.');
            }

            $replacement = \catalog_one(
                $this->db,
                'SELECT * FROM ue_files WHERE id=?',
                [$fileId]
            );
            if (!$replacement) {
                throw new RuntimeException('Refreshed package disappeared before maintenance finalization.');
            }
            $newPackageName = (string)($replacement['package_name'] ?? $oldPackageName);
            $newStoredPath = \catalog_file_maintenance_storage_path($this->config, $replacement);

            \catalog_file_maintenance_emit(
                $progress,
                'dependencies',
                99,
                $deferDependencyRefresh
                    ? 'Dependency reconciliation deferred to the final Full Sync pass'
                    : 'Refreshing references to the re-imported package'
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

            $reconciliationJobId = null;
            if (!$deferDependencyRefresh) {
                $reconciliationJobId = CatalogProjectionReconciliationQueue::enqueue(
                    $this->db,
                    $fileId,
                    [$gameId],
                    [$oldPackageName, $newPackageName],
                    $this->config,
                    $userId
                );
            }

            $storageWarning = '';
            if ($newStoredPath !== null
                && strcasecmp($newStoredPath, $storedPath) !== 0
                && is_file($storedPath)
                && !@unlink($storedPath)) {
                $storageWarning = '; old canonical storage copy could not be removed';
            }

            return [
                'game_id' => $gameId,
                'file_id' => $fileId,
                'old_file_id' => $fileId,
                'old_package_name' => $oldPackageName,
                'new_package_name' => $newPackageName,
                'original_name' => (string)($result[4]['source_relative_path'] ?? $scannerOriginalName),
                'reconciliation_job_id' => $reconciliationJobId,
                'message' => (string)$result[2]
                    . '; stable file ID preserved=' . $fileId
                    . ($sourceRelativePath !== ''
                        ? '; reimport source=' . $sourceRelativePath
                        : '; reimport source unavailable, used stored filename metadata')
                    . $storageWarning,
            ];
        } catch (Throwable $error) {
            @unlink($inputPath);

            $failedStoredPath = null;
            try {
                $current = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
                if ($current) {
                    $failedStoredPath = \catalog_file_maintenance_storage_path($this->config, $current);
                }
            } catch (Throwable) {
                $failedStoredPath = null;
            }

            try {
                $support->restoreExistingSnapshot($snapshot);
            } catch (Throwable $restoreError) {
                error_log(
                    '[UnrealDB reimport rollback] file_id=' . $fileId
                    . ' snapshot restore failed: ' . $restoreError->getMessage()
                );
            }

            if ($failedStoredPath !== null
                && strcasecmp($failedStoredPath, $storedPath) !== 0
                && is_file($failedStoredPath)) {
                @unlink($failedStoredPath);
            }

            try {
                (new PdoPackageProviderRepository($this->db))->reconcileFile($fileId);
                if (!$deferDependencyRefresh) {
                    \scanner_rebuild_game($this->db, $this->config, (int)$file['game_id']);
                    (new PdoGameCatalogStats($this->db))->rebuildGame((int)$file['game_id']);
                }
            } catch (Throwable $refreshError) {
                error_log(
                    '[UnrealDB reimport rollback] file_id=' . $fileId
                    . ' projection refresh failed: ' . $refreshError->getMessage()
                );
            }
            throw $error;
        } finally {
            @unlink($inputPath);
        }
    }
}
