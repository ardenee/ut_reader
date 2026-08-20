<?php
/**
 * Moves one verified package back into the global Unverified Files queue without
 * deleting its stable ue_files identity.
 *
 * The physical package is moved first, the existing row is reused as the
 * unverified staging row, and verified-only projections are then removed. If the
 * staging conversion fails, the verified row and physical path are restored.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationQueue;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;

final class CatalogVerifiedFileDemotionService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function returnToUnverified(int $fileId, ?int $userId = null, ?callable $progress = null): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('A positive file ID is required.');
        }

        $file = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
        if (!$file) {
            throw new \RuntimeException('File #' . $fileId . ' no longer exists.');
        }
        if ((string)($file['scan_status'] ?? '') === 'unverified' && (int)($file['game_id'] ?? 0) === 0) {
            return [
                'status' => 'already_unverified',
                'file_id' => $fileId,
                'source_game_id' => 0,
                'original_name' => (string)($file['original_name'] ?? ''),
                'message' => 'File #' . $fileId . ' is already in Unverified Files.',
                'warnings' => [],
            ];
        }
        if ((string)($file['scan_status'] ?? '') !== 'verified' || (int)($file['game_id'] ?? 0) < 1) {
            throw new \RuntimeException('Only an active verified game file can be returned to Unverified Files.');
        }

        $sourceGameId = (int)$file['game_id'];
        $sourceGame = \catalog_one($this->db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$sourceGameId]);
        if (!$sourceGame) {
            throw new \RuntimeException('The source game no longer exists.');
        }

        $support = new CatalogFileMaintenanceSupport($this->db, $this->config);
        $rollbackState = $support->reimportState($fileId);
        $sourcePath = CatalogFileMaintenanceSupport::storagePath($this->config, $file);
        if ($sourcePath === null || !is_file($sourcePath)) {
            throw new \RuntimeException('The verified package is missing from controlled storage; it was not reassigned.');
        }

        $packageName = trim((string)($file['package_name'] ?? ''));
        $originalName = trim((string)($file['original_name'] ?? '')) ?: basename($sourcePath);
        $sourceRelativePath = CatalogFileMaintenanceSupport::sourceRelativePath($rollbackState);
        $oldMetadataPath = CatalogFileMaintenanceSupport::metadataPath($this->config, $sourceGameId, $fileId);
        $affectedIds = $support->affectedIds($sourceGameId, $fileId, $packageName, false);

        $directory = CatalogUnverifiedQueueStorage::uploadBucketDirectory($this->config, true);
        $queueName = CatalogUnverifiedQueueStorage::safeQueueName($originalName);
        $destination = CatalogUnverifiedQueueStorage::uniqueDestination($directory, $queueName);
        $queueName = basename($destination);
        $queueKey = CatalogUnverifiedStagingIndex::queueKey(0, $queueName);
        $reason = 'Returned from ' . (string)$sourceGame['name'] . ' by administrator.';

        $this->emit($progress, 'move_to_unverified', 5, 'Moving ' . $originalName . ' into Unverified Files');
        if (!@rename($sourcePath, $destination)) {
            throw new \RuntimeException('Could not move the verified package into Unverified Files.');
        }
        @file_put_contents($destination . '.txt', $reason);

        try {
            // Seed the unique queue identity on the existing verified row. The
            // staging index then resolves this same row and converts it in place,
            // preserving every external reference that points at ue_files.id.
            $statement = $this->db->prepare(
                'UPDATE ue_files SET unverified_queue_key=? WHERE id=? AND scan_status="verified" AND game_id=?'
            );
            $statement->execute([$queueKey, $fileId, $sourceGameId]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('The source file changed before it could be returned to Unverified Files.');
            }

            $this->emit($progress, 'index_unverified', 20, 'Rebuilding unverified package metadata');
            $indexed = (new CatalogUnverifiedStagingIndex($this->db, $this->config))->indexPath(
                0,
                $queueName,
                $destination,
                $originalName,
                $reason,
                $userId,
                $sourceRelativePath,
                true
            );
            if ((int)($indexed['file_id'] ?? 0) !== $fileId) {
                throw new \RuntimeException('Unverified staging changed the stable file identity unexpectedly.');
            }
        } catch (Throwable $error) {
            try {
                $support->restoreReimportFileRow($rollbackState);
            } catch (Throwable $restoreError) {
                error_log('[UnrealDB game file reassignment] row rollback failed for #' . $fileId . ': ' . $restoreError->getMessage());
            }
            if (is_file($destination) && !is_file($sourcePath)) {
                @rename($destination, $sourcePath);
            }
            @unlink($destination . '.txt');
            throw $error;
        }

        $warnings = [];
        $this->emit($progress, 'cleanup_verified', 60, 'Removing old verified projections');
        try {
            $this->db->beginTransaction();
            $this->db->prepare('DELETE FROM ue_dependency_links WHERE file_id=?')->execute([$fileId]);
            $this->db->prepare('DELETE FROM ue_export_lookup WHERE file_id=?')->execute([$fileId]);
            $this->db->prepare('DELETE FROM ue_dependency_package_summaries WHERE file_id=?')->execute([$fileId]);
            if (function_exists('catalog_package_aliases_ensure')) {
                \catalog_package_aliases_ensure($this->db);
                $this->db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
            }
            $this->db->prepare('DELETE FROM ue_file_metadata WHERE file_id=?')->execute([$fileId]);
            $this->db->prepare(
                'UPDATE ue_files SET metadata_status="ready",metadata_error=NULL,metadata_updated_at=UTC_TIMESTAMP() WHERE id=?'
            )->execute([$fileId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $warnings[] = 'verified projection cleanup needs reconciliation: ' . $this->shortError($error);
        }

        if (is_file($oldMetadataPath) && !@unlink($oldMetadataPath)) {
            $warnings[] = 'old compact metadata file could not be removed';
        }

        $this->emit($progress, 'refresh_source_dependencies', 72, 'Refreshing packages affected in the source game');
        try {
            $support->refreshIds(
                $affectedIds,
                $progress,
                72,
                96,
                'Refreshing dependencies after returning ' . $originalName . ' to Unverified Files'
            );
        } catch (Throwable $error) {
            $warnings[] = 'source dependency refresh was deferred: ' . $this->shortError($error);
        }

        try {
            CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                $fileId,
                [$sourceGameId],
                $packageName !== '' ? [$packageName] : [],
                $this->config
            );
        } catch (Throwable $error) {
            $warnings[] = 'projection reconciliation could not be queued: ' . $this->shortError($error);
        }

        $message = 'Returned ' . $originalName . ' from ' . (string)$sourceGame['name'] . ' to Unverified Files.';
        if ($warnings !== []) {
            $message .= ' ' . count($warnings) . ' cleanup warning(s) were recorded.';
        }
        $this->emit($progress, 'complete', 100, $message);

        return [
            'status' => 'unverified',
            'file_id' => $fileId,
            'source_game_id' => $sourceGameId,
            'source_game' => (string)$sourceGame['name'],
            'original_name' => $originalName,
            'queue_name' => $queueName,
            'warnings' => $warnings,
            'message' => $message,
        ];
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    private function emit(?callable $progress, string $stage, int $percent, string $message): void
    {
        if ($progress !== null) {
            $progress([
                'stage' => $stage,
                'done' => max(0, min(100, $percent)),
                'total' => 100,
                'percent' => max(0, min(100, $percent)),
                'message' => $message,
            ]);
        }
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return mb_substr($message !== '' ? $message : get_class($error), 0, 500, 'UTF-8');
    }
}
