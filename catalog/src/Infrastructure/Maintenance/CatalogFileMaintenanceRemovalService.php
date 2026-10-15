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
        require_once dirname(__DIR__, 3) . '/lib/CatalogPackageAliases.php';
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

        // Deletion stages and eventually unlinks a physical source file. It must
        // own the SQL commit; a caller-owned transaction could later roll back
        // after the source was irreversibly removed.
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Package removal requires its own database transaction.');
        }

        $gameId = (int)$file['game_id'];
        $packageName = trim((string)$file['package_name']);
        \catalog_package_aliases_ensure($this->db);
        $packageNames = [$packageName];
        foreach (\catalog_all(
            $this->db,
            'SELECT package_name FROM ue_file_package_aliases WHERE file_id=? ORDER BY id',
            [$fileId]
        ) as $alias) {
            $aliasName = trim((string)($alias['package_name'] ?? ''));
            if ($aliasName !== '') {
                $packageNames[] = $aliasName;
            }
        }
        $packageNames = array_values(array_unique(array_filter(
            $packageNames,
            static fn(string $name): bool => $name !== ''
        )));
        $uedb5Path = CatalogFileMaintenanceSupport::uedb5MetadataPath($this->config, $gameId, $fileId);
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

        $ownsTransaction = false;
        $coreDeletionCommitted = false;
        $reconciliationJobId = null;
        try {
            $affectedFileIds = [];
            $affectedUedb5FileIds = $support->affectedUedb5ConsumerIds($gameId, $fileId);
            foreach ($packageNames as $logicalPackageName) {
                $affectedFileIds = array_merge(
                    $affectedFileIds,
                    \catalog_file_maintenance_affected_ids(
                        $this->db,
                        $gameId,
                        $fileId,
                        $logicalPackageName,
                        $deferDependencyRefresh
                    )
                );
            }
            $affectedFileIds = array_values(array_unique(array_map('intval', $affectedFileIds)));
            \catalog_file_maintenance_emit(
                $progress,
                'delete',
                20,
                'Removing catalog records and compact projections'
            );
            // V5 registration, aliases, file identity and Pass-2 invalidation must
            // succeed or fail together; restoring only the staged source is insufficient.
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $ownsTransaction = true;
            }
            $support->deleteFileProjections($fileId);
            $this->db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
            $this->db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
            $support->invalidateUedb5DependencyPass($affectedUedb5FileIds);
            if ($ownsTransaction) {
                $this->db->commit();
                $ownsTransaction = false;
            }
            $coreDeletionCommitted = true;
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
            if (!$deferDependencyRefresh) {
                $reconciliationJobId = CatalogProjectionReconciliationQueue::enqueue(
                    $this->db,
                    $fileId,
                    [$gameId],
                    $packageNames,
                    $this->config
                );
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($coreDeletionCommitted) {
                // SQL deletion is committed. Never resurrect the source file without
                // its ue_files identity; retain neither a dangling staged file nor
                // old V5 container when downstream dependency refresh fails.
                if ($stagedPath !== null && is_file($stagedPath)) {
                    @unlink($stagedPath);
                }
                if (is_file($uedb5Path)) {
                    @unlink($uedb5Path);
                }
                throw new RuntimeException(
                    'Catalog deletion was committed for file #' . $fileId
                    . ', but post-deletion dependency refresh failed: ' . $error->getMessage(),
                    0,
                    $error
                );
            }
            if ($stagedPath !== null && is_file($stagedPath) && $storedPath !== null) {
                @rename($stagedPath, $storedPath);
            }
            throw $error;
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
        if (is_file($uedb5Path) && !@unlink($uedb5Path)) {
            $warnings[] = 'the UEDB5 metadata file could not be deleted';
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
