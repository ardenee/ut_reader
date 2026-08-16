<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Coordinates affected dependency discovery and durable refresh scheduling.
 * Why: Dependency discovery, queue inspection, provider persistence and worker launch are infrastructure concerns.
 * Role: Infrastructure coordinator used by scanner compatibility code and dependency job handlers.
 * Audit: Primary implementation; keep HTTP/request policy outside this class.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/**
 * Finds dependency owners whose exact package/object resolution can change after
 * a package is imported or its canonical package identity is corrected.
 *
 * Foreground imports never load affected IDs. The exact-file dependency worker
 * first makes the provider and its own dependency rows authoritative, then calls
 * this coordinator. The affected worker performs bounded discovery/planning.
 */
final class CatalogAffectedDependencyRefreshCoordinator
{
    /**
     * @param list<string> $additionalPackageNames
     * @return list<int>
     */
    public static function findAffectedFileIds(
        PDO $db,
        int $gameId,
        int $newFileId,
        string $packageName,
        array $additionalPackageNames = [],
        bool $includeResolvedProvider = false
    ): array {
        self::syncProvider($db, $newFileId);

        $activeRefresh = self::isActiveRefreshJob($db, $newFileId);
        if (!$activeRefresh && $additionalPackageNames === [] && !$includeResolvedProvider) {
            $jobId = self::enqueueIfNeeded($db, $gameId, $newFileId, $packageName, true, true);
            if ($jobId > 0) {
                $GLOBALS['catalog_affected_dependency_refresh_job_id'] = $jobId;
                return [];
            }
        }

        // Affected-file discovery must use authoritative dependency links and
        // indexed term identities, never package-summary text scans.
        $fileIds = [];
        $packageNames = self::packageNames($packageName, $additionalPackageNames);
        foreach ($packageNames as $name) {
            self::collectFileIds(
                $db,
                'SELECT DISTINCT l.file_id'
                . ' FROM ue_dependency_links l'
                . ' JOIN ue_terms t ON t.id=l.required_package_term_id'
                . ' JOIN ue_files f ON f.id=l.file_id'
                . ' WHERE t.value_hash=? AND t.value_length=?'
                . ' AND l.file_id<>? AND f.game_id=? AND f.scan_status="verified"'
                . ' ORDER BY l.file_id',
                [md5($name, true), strlen($name), $newFileId, $gameId],
                $fileIds
            );
        }

        // A rename must also invalidate files that were already resolved to the
        // old provider. The provider row is reconciled to the new canonical name,
        // but those dependency rows remain stale until their owning files rebuild.
        if ($includeResolvedProvider) {
            self::collectFileIds(
                $db,
                'SELECT DISTINCT l.file_id'
                . ' FROM ue_dependency_links l'
                . ' JOIN ue_files f ON f.id=l.file_id'
                . ' WHERE l.resolved_file_id=? AND l.file_id<>?'
                . ' AND f.game_id=? AND f.scan_status="verified"'
                . ' ORDER BY l.file_id',
                [$newFileId, $newFileId, $gameId],
                $fileIds
            );
        }

        return array_map('intval', array_keys($fileIds));
    }

    public static function enqueueIfNeeded(
        PDO $db,
        int $gameId,
        int $newFileId,
        string $packageName,
        bool $sourceSummaryReady = false,
        bool $providerReady = false
    ): int {
        if ($gameId < 1 || $newFileId < 1 || trim($packageName) === '') {
            return 0;
        }

        if (!$providerReady) {
            self::syncProvider($db, $newFileId);
        }

        $existingJobId = self::existingRefreshJobId($db, $newFileId);
        if ($existingJobId > 0) {
            return $existingJobId;
        }
        if (!self::hasAffectedFiles($db, $gameId, $newFileId, $packageName)) {
            return 0;
        }

        return self::enqueueRefresh(
            $db,
            $newFileId,
            $gameId,
            $packageName,
            $sourceSummaryReady
        );
    }

    /**
     * Queue an affected-file pass after a canonical filename/package rename.
     *
     * Unlike a normal import, rename discovery must cover three groups:
     * - files requiring the corrected/new package name;
     * - files requiring the old package name;
     * - files already resolved to this provider before its identity changed.
     */
    public static function enqueueRenameRefresh(
        PDO $db,
        int $gameId,
        int $fileId,
        string $newPackageName,
        string $oldPackageName
    ): int {
        $newPackageName = trim($newPackageName);
        $oldPackageName = trim($oldPackageName);
        if ($gameId < 1 || $fileId < 1 || $newPackageName === '') {
            return 0;
        }

        self::syncProvider($db, $fileId);
        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $identity = strtolower($oldPackageName) . "\0" . strtolower($newPackageName);

        try {
            return (new PdoJobQueue($db))->enqueue(
                $queueName,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'package_name' => $newPackageName,
                    'additional_package_names' => $oldPackageName !== '' && strcasecmp($oldPackageName, $newPackageName) !== 0
                        ? [$oldPackageName]
                        : [],
                    'include_resolved_provider' => true,
                    'source_summary_ready' => true,
                    'rename_refresh' => true,
                ],
                35,
                null,
                self::dedupeKey($fileId) . ':rename:' . substr(hash('sha256', $identity), 0, 32),
                null,
                3
            );
        } catch (Throwable $error) {
            error_log('[UnrealDB dependency rename refresh queue] file_id=' . $fileId
                . ' enqueue failed: ' . $error->getMessage());
            return 0;
        }
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
            'SELECT 1 FROM ue_dependency_links l'
            . ' JOIN ue_terms t ON t.id=l.required_package_term_id'
            . ' JOIN ue_files f ON f.id=l.file_id'
            . ' WHERE t.value_hash=? AND t.value_length=?'
            . ' AND l.file_id<>? AND f.game_id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([
            md5($packageName, true),
            strlen($packageName),
            $newFileId,
            $gameId,
        ]);
        return $statement->fetchColumn() !== false;
    }

    private static function isActiveRefreshJob(PDO $db, int $fileId): bool
    {
        try {
            [$dedupeKey, $chainPattern] = self::chainKeys($fileId);
            $statement = $db->prepare(
                'SELECT 1 FROM ue_background_jobs'
                . ' WHERE job_type=? AND status="running"'
                . ' AND (dedupe_key=? OR dedupe_key LIKE ?) LIMIT 1'
            );
            $statement->execute([
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                $dedupeKey,
                $chainPattern,
            ]);
            return $statement->fetchColumn() !== false;
        } catch (Throwable) {
            // Missing/legacy queue infrastructure leaves no active chain.
        }

        return false;
    }

    private static function existingRefreshJobId(PDO $db, int $fileId): int
    {
        try {
            [$dedupeKey, $chainPattern] = self::chainKeys($fileId);
            $statement = $db->prepare(
                'SELECT id FROM ue_background_jobs'
                . ' WHERE job_type=? AND status IN ("queued","running")'
                . ' AND (dedupe_key=? OR dedupe_key LIKE ?)'
                . ' ORDER BY id DESC LIMIT 1'
            );
            $statement->execute([
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                $dedupeKey,
                $chainPattern,
            ]);
            return max(0, (int)($statement->fetchColumn() ?: 0));
        } catch (Throwable) {
            // Continue to durable enqueue when queue inspection fails.
        }

        return 0;
    }

    private static function enqueueRefresh(
        PDO $db,
        int $fileId,
        int $gameId,
        string $packageName,
        bool $sourceSummaryReady
    ): int {
        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog'));
        if ($queueName === '') {
            $queueName = 'catalog';
        }

        try {
            $jobId = (new PdoJobQueue($db))->enqueue(
                $queueName,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'package_name' => $packageName,
                    'source_summary_ready' => $sourceSummaryReady,
                ],
                40,
                null,
                self::dedupeKey($fileId),
                null,
                3
            );
        } catch (Throwable $error) {
            error_log('[UnrealDB dependency refresh queue] file_id=' . $fileId
                . ' enqueue failed: ' . $error->getMessage());
            return 0;
        }

        if ($config !== []) {
            try {
                $launcher = new CatalogDetachedWorker($config);
                $desiredWorkers = $launcher->configuredWorkerCount();
                $worker = $launcher->status($queueName, false);
                $activeOrLaunching = max(0, (int)($worker['active_count'] ?? 0))
                    + max(0, (int)($worker['launching_count'] ?? 0));
                if ($activeOrLaunching < $desiredWorkers) {
                    $launcher->start($queueName, 10000, $desiredWorkers);
                }
            } catch (Throwable $workerError) {
                error_log('[UnrealDB dependency refresh worker] job_id=' . $jobId
                    . ' launch failed; queued job remains durable: ' . $workerError->getMessage());
            }
        }

        return $jobId;
    }

    /** @param list<string> $additionalPackageNames @return list<string> */
    private static function packageNames(string $primary, array $additionalPackageNames): array
    {
        $names = [];
        foreach (array_merge([$primary], $additionalPackageNames) as $name) {
            $name = trim((string)$name);
            if ($name === '' || strlen($name) > 255) {
                continue;
            }
            $names[strtolower($name)] = $name;
        }
        return array_values($names);
    }

    /** @return array{0:string,1:string} */
    private static function chainKeys(int $fileId): array
    {
        $dedupeKey = self::dedupeKey($fileId);
        // Match legacy :offset: continuations, batch children and rename refreshes.
        return [$dedupeKey, $dedupeKey . ':%'];
    }

    private static function dedupeKey(int $fileId): string
    {
        return 'rebuild-affected-file:' . max(1, $fileId);
    }

    /**
     * @param list<mixed> $args
     * @param array<int, true> $fileIds
     */
    private static function collectFileIds(PDO $db, string $sql, array $args, array &$fileIds): void
    {
        $statement = $db->prepare($sql);
        $statement->execute($args);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fileId = (int)($row['file_id'] ?? 0);
            if ($fileId > 0) {
                $fileIds[$fileId] = true;
            }
        }
    }
}
