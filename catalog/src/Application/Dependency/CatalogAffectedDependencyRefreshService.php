<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Search\CatalogSearchIndexQueue;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
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
        if (self::summaryAvailable($db)) {
            self::collectFileIds(
                $db,
                'SELECT s.file_id FROM ue_dependency_package_summaries s'
                . ' JOIN ue_files f ON f.id=s.file_id'
                . ' WHERE s.required_package=? AND s.file_id<>?'
                . ' AND s.game_id=? AND f.scan_status="verified"'
                . ' ORDER BY s.file_id',
                [$packageName, $newFileId, $gameId],
                $fileIds
            );
        } else {
            self::collectFileIds(
                $db,
                'SELECT DISTINCT l.file_id'
                . ' FROM ue_dependency_links l'
                . ' JOIN ue_terms t ON t.id=l.required_package_term_id'
                . ' JOIN ue_files f ON f.id=l.file_id'
                . ' WHERE t.value_hash=? AND t.value_length=? AND t.value_prefix=?'
                . ' AND l.file_id<>? AND f.game_id=? AND f.scan_status="verified"'
                . ' ORDER BY l.file_id',
                [md5($packageName, true), strlen($packageName), substr($packageName, 0, 200), $newFileId, $gameId],
                $fileIds
            );
        }

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

    private static function summaryAvailable(PDO $db): bool
    {
        return (new PdoDependencyPackageSummary($db))->available();
    }

    private static function hasAffectedFiles(PDO $db, int $gameId, int $newFileId, string $packageName): bool
    {
        if (self::summaryAvailable($db)) {
            $statement = $db->prepare(
                'SELECT 1 FROM ue_dependency_package_summaries s'
                . ' JOIN ue_files f ON f.id=s.file_id'
                . ' WHERE s.required_package=? AND s.file_id<>?'
                . ' AND s.game_id=? AND f.scan_status="verified" LIMIT 1'
            );
            $arguments = [$packageName, $newFileId, $gameId];
        } else {
            $statement = $db->prepare(
                'SELECT 1 FROM ue_dependency_links l'
                . ' JOIN ue_terms t ON t.id=l.required_package_term_id'
                . ' JOIN ue_files f ON f.id=l.file_id'
                . ' WHERE t.value_hash=? AND t.value_length=? AND t.value_prefix=?'
                . ' AND l.file_id<>? AND f.game_id=? AND f.scan_status="verified" LIMIT 1'
            );
            $arguments = [
                md5($packageName, true),
                strlen($packageName),
                substr($packageName, 0, 200),
                $newFileId,
                $gameId,
            ];
        }
        $statement->execute($arguments);
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

    private static function existingRefreshJobId(PDO $db, int $fileId): int
    {
        try {
            $statement = $db->prepare(
                'SELECT id,payload_json FROM ue_background_jobs'
                . ' WHERE job_type=? AND status IN ("queued","running") ORDER BY id DESC'
            );
            $statement->execute([JobType::REBUILD_AFFECTED_DEPENDENCIES]);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $payload = json_decode((string)($row['payload_json'] ?? ''), true);
                if (is_array($payload) && (int)($payload['file_id'] ?? 0) === $fileId) {
                    return (int)($row['id'] ?? 0);
                }
            }
        } catch (Throwable) {
            // Continue to the durable enqueue path when queue inspection fails.
        }

        return 0;
    }

    private static function enqueueRefresh(PDO $db, int $fileId, int $gameId, string $packageName): int
    {
        try {
            $existingJobId = self::existingRefreshJobId($db, $fileId);
            if ($existingJobId > 0) {
                return $existingJobId;
            }

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
