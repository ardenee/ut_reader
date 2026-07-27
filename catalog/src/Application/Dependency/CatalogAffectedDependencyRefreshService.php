<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Search\CatalogSearchIndexQueue;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/**
 * Finds dependency owners whose exact package/object resolution can change after
 * a package is imported.
 *
 * Normal imports enqueue search indexing and the potentially expensive affected
 * dependency refresh, then return quickly. The matching durable dependency job
 * calls this service again while its queue row is running; only that execution
 * loads and returns the affected file IDs.
 */
final class CatalogAffectedDependencyRefreshService
{
    /** @return list<int> */
    public static function findAffectedFileIds(PDO $db, int $gameId, int $newFileId, string $packageName): array
    {
        self::syncProvider($db, $newFileId);

        $activeRefresh = self::isActiveRefreshJob($db, $newFileId);
        if (!$activeRefresh) {
            $searchJobId = CatalogSearchIndexQueue::enqueueFile($db, $newFileId);
            if ($searchJobId > 0) {
                $GLOBALS['catalog_search_index_job_id'] = $searchJobId;
            }
        }

        if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
            return [];
        }

        if (!$activeRefresh) {
            if (!self::hasAffectedFiles($db, $gameId, $newFileId, $packageName)) {
                return [];
            }

            $jobId = self::enqueueRefresh($db, $newFileId, $gameId, $packageName);
            if ($jobId > 0) {
                $GLOBALS['catalog_affected_dependency_refresh_job_id'] = $jobId;
                return [];
            }
        }

        $fileIds = [];
        self::collectFileIds(
            $db,
            'SELECT DISTINCT d.file_id'
            . ' FROM ue_dependencies d'
            . ' JOIN ue_files f ON f.id=d.file_id'
            . ' WHERE d.required_package=? AND d.file_id<>?'
            . ' AND f.game_id=? AND f.scan_status="verified"'
            . ' ORDER BY d.file_id',
            [$packageName, $newFileId, $gameId],
            $fileIds
        );

        return array_map('intval', array_keys($fileIds));
    }

    private static function syncProvider(PDO $db, int $fileId): void
    {
        try {
            (new PdoPackageProviderRepository($db))->syncFile($fileId);
        } catch (PDOException $exception) {
            // The authoritative ue_files row remains valid. The resolver keeps an
            // exact fallback and maintenance can reconcile the provider cache.
            error_log('[UnrealDB package provider] file_id=' . $fileId . ' sync failed: ' . $exception->getMessage());
        }
    }

    private static function hasAffectedFiles(PDO $db, int $gameId, int $newFileId, string $packageName): bool
    {
        $statement = $db->prepare(
            'SELECT 1 FROM ue_dependencies d'
            . ' JOIN ue_files f ON f.id=d.file_id'
            . ' WHERE d.required_package=? AND d.file_id<>?'
            . ' AND f.game_id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([$packageName, $newFileId, $gameId]);
        return $statement->fetchColumn() !== false;
    }

    private static function isActiveRefreshJob(PDO $db, int $fileId): bool
    {
        try {
            $statement = $db->prepare(
                'SELECT payload_json FROM ue_background_jobs'
                . ' WHERE job_type=? AND status="running" ORDER BY id DESC LIMIT 20'
            );
            $statement->execute([JobType::REBUILD_AFFECTED_DEPENDENCIES]);
            while (($payloadJson = $statement->fetchColumn()) !== false) {
                $payload = json_decode((string)$payloadJson, true);
                if (is_array($payload) && (int)($payload['file_id'] ?? 0) === $fileId) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Missing/legacy queue infrastructure falls back to synchronous work.
        }

        return false;
    }

    private static function enqueueRefresh(PDO $db, int $fileId, int $gameId, string $packageName): int
    {
        try {
            $config = function_exists('catalog_config') ? \catalog_config() : [];
            $queueName = trim((string)($config['queue']['name'] ?? 'catalog'));
            if ($queueName === '') {
                $queueName = 'catalog';
            }

            $jobId = (new PdoJobQueue($db))->enqueue(
                $queueName,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'package_name' => $packageName,
                ],
                40,
                null,
                'rebuild-affected-file:' . $fileId,
                null,
                3
            );

            if ($config !== []) {
                try {
                    (new CatalogDetachedWorker($config))->start($queueName, 10000);
                } catch (Throwable $workerError) {
                    error_log('[UnrealDB dependency refresh worker] job_id=' . $jobId . ' launch failed: ' . $workerError->getMessage());
                }
            }

            return $jobId;
        } catch (Throwable $error) {
            error_log('[UnrealDB dependency refresh queue] file_id=' . $fileId . ' enqueue failed; using synchronous fallback: ' . $error->getMessage());
            return 0;
        }
    }

    /**
     * @param list<mixed> $args
     * @param array<int, true> $fileIds
     */
    private static function collectFileIds(PDO $db, string $sql, array $args, array &$fileIds): void
    {
        foreach (\catalog_all($db, $sql, $args) as $row) {
            $fileIds[(int)$row['file_id']] = true;
        }
    }
}
