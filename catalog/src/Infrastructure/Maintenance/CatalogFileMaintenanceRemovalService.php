<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Removes one verified package from storage and the catalog while refreshing affected dependency projections.
 * Why: Filesystem staging, catalog deletion, dependency refresh, reconciliation enqueueing and cleanup warnings form one maintenance use case.
 * Role: Infrastructure maintenance service preserving the existing compact removal contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationQueue;

final class CatalogFileMaintenanceRemovalService
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
    public function remove(
        int $fileId,
        ?callable $progress = null,
        bool $deferDependencyRefresh = false
    ): array {
        $file = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
        if (!$file) {
            throw new RuntimeException('File no longer exists in the catalog.');
        }

        $gameId = (int)$file['game_id'];
        $packageName = (string)$file['package_name'];
        $metadataPath = \catalog_file_maintenance_metadata_path($this->config, $gameId, $fileId);
        $storedPath = \catalog_file_maintenance_storage_path($this->config, $file);
        $stagedPath = null;
        $support = new CatalogFileMaintenanceSupport($this->db, $this->config);

        if ($storedPath !== null && is_file($storedPath)) {
            $stagedPath = $storedPath . '.deleting-' . bin2hex(random_bytes(8));
            \catalog_file_maintenance_emit($progress, 'delete', 5, 'Staging stored package for removal');
            if (!@rename($storedPath, $stagedPath)) {
                throw new RuntimeException('Could not stage the stored package for deletion.');
            }
        }

        try {
            $affectedFileIds = \catalog_file_maintenance_affected_ids(
                $this->db,
                $gameId,
                $fileId,
                $packageName,
                $deferDependencyRefresh
            );
            \catalog_file_maintenance_emit(
                $progress,
                'delete',
                20,
                'Removing catalog records and compact projections'
            );
            $support->deleteFileProjections($fileId);
            $this->db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
            if ($deferDependencyRefresh) {
                \catalog_file_maintenance_emit(
                    $progress,
                    'dependencies',
                    95,
                    'Dependency reconciliation deferred to the final Full Sync pass'
                );
            } else {
                \catalog_file_maintenance_refresh_ids(
                    $this->db,
                    $this->config,
                    $affectedFileIds,
                    $progress,
                    25,
                    95,
                    'Refreshing affected dependency links'
                );
            }
        } catch (Throwable $error) {
            if ($stagedPath !== null && is_file($stagedPath) && $storedPath !== null) {
                @rename($stagedPath, $storedPath);
            }
            throw $error;
        }

        $reconciliationJobId = null;
        if (!$deferDependencyRefresh) {
            $reconciliationJobId = CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                $fileId,
                [$gameId],
                [$packageName],
                $this->config
            );
        }

        $warnings = [];
        \catalog_file_maintenance_emit(
            $progress,
            'delete',
            98,
            'Removing staged storage and metadata files'
        );
        if ($stagedPath !== null && is_file($stagedPath) && !@unlink($stagedPath)) {
            $warnings[] = 'the staged package file could not be deleted';
        }
        if (is_file($metadataPath) && !@unlink($metadataPath)) {
            $warnings[] = 'the compact metadata file could not be deleted';
        }
        \catalog_file_maintenance_emit($progress, 'done', 100, 'Package removal complete');

        return [
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$file['original_name'],
            'storage_found' => $storedPath !== null,
            'warning' => $warnings !== []
                ? ' The database record was removed, but ' . implode(' and ', $warnings) . '.'
                : '',
            'reconciliation_job_id' => $reconciliationJobId,
        ];
    }
}
