<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogJobWorkerFactory` for catalog job worker factory.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogJobWorkerFactory
{
    /** @param array<string,mixed> $config */
    public static function create(
        PDO $db,
        array $config,
        string $queueName,
        string $workerId,
        int $leaseSeconds
    ): JobWorker {
        self::raiseWorkerMemoryLimit($config);

        $trustedImportConfig = $config;
        $ingressLimit = max(1, (int)($config['max_upload_bytes'] ?? (256 * 1024 * 1024)));
        $defaultRedirectLimit = $ingressLimit > intdiv(PHP_INT_MAX, 8)
            ? PHP_INT_MAX
            : $ingressLimit * 8;
        $trustedImportConfig['ingress_max_upload_bytes'] = $ingressLimit;
        $trustedImportConfig['max_redirect_output_bytes'] = max(
            $ingressLimit,
            (int)($config['max_redirect_output_bytes'] ?? $defaultRedirectLimit)
        );
        $trustedImportConfig['max_upload_bytes'] = PHP_INT_MAX;

        $logging = new CatalogJobLoggingSettingsStore($db);
        $eventLog = new CatalogJobEventLog($config, $logging);
        $eventAppender = static function (int $jobId, array $event) use ($eventLog): void {
            $eventLog->append($jobId, $event);
        };
        $diagnosticEnabled = static fn(): bool => $logging->enabled('worker_diagnostics', false);
        $failureReporter = static function (ClaimedJob $job, \Throwable $error, string $disposition): void {
            CatalogSystemErrorRecorder::record([
                'source_kind' => 'background-job',
                'severity' => 'error',
                'error_type' => get_class($error),
                'message' => $job->type . ' #' . $job->id . ' ' . $disposition . ': ' . $error->getMessage(),
                'source_file' => $error->getFile(),
                'source_line' => $error->getLine(),
                'trace_text' => $error->getTraceAsString(),
                'context' => [
                    'job_id' => $job->id,
                    'job_type' => $job->type,
                    'attempt' => $job->attempt,
                    'max_attempts' => $job->maxAttempts,
                    'disposition' => $disposition,
                    'resource_class' => $job->resourceClass,
                    'concurrency_key' => $job->concurrencyKey,
                ],
            ]);
        };

        $bucketUpload = new CatalogBucketUploadJobHandler($db, $trustedImportConfig);
        $metadataRepair = new CatalogUnverifiedMetadataRepairJobHandler($db, $trustedImportConfig);
        $unverifiedMatchRefresh = new CatalogUnverifiedGameMatchRefreshJobHandler($db, $trustedImportConfig);
        $crossGameCopyBatch = new CatalogCrossGameCopyBatchJobHandler($db, $trustedImportConfig);
        $bucketRedirect = new CatalogBucketRedirectJobHandler($db, $trustedImportConfig);
        $pakImport = new CatalogPakImportJobHandler($db, $trustedImportConfig);
        $packageImport = new CatalogNonBlockingImportJobHandler(
            new CatalogStagedImportJobHandler($db, $trustedImportConfig),
            $db,
            $trustedImportConfig
        );
        $sourceScan = new CatalogSourceScanJobHandler($db, $trustedImportConfig);
        $storageMaintenance = new CatalogStorageMaintenanceJobHandler($db, $config);
        $duplicateCleanup = new UnverifiedDuplicateCleanupJobHandler($db, $config);
        $generatedPackage = new GeneratedPackageJobHandler($db, $config);
        $backupExport = new GameBackupExportJobHandler($db, $config);
        $backupImport = new GameBackupImportCleanupJobHandler(
            new GameBackupImportJobHandler($db, $trustedImportConfig),
            $trustedImportConfig
        );
        $searchIndex = new CatalogSearchIndexJobHandler($db);
        $projectionReconciliation = new CatalogProjectionReconciliationJobHandler($db, $config);
        $dependencyRefresh = new CatalogDependencyRefreshJobHandler($db, $config);
        $affectedDependencyRefresh = new CatalogAffectedDependencyRefreshJobHandler($db, $config);
        $maintenance = new CatalogMaintenanceJobHandler($db, $config);
        $fullSync = new CatalogFullSyncJobHandler($db, $trustedImportConfig);
        $fullSyncUnit = new CatalogFullSyncUnitJobHandler($db, $trustedImportConfig);

        $handlersByType = [
            JobType::FULL_SYNC_GAME => $fullSync,
            JobType::FULL_SYNC_FILE => $fullSyncUnit,
            JobType::FULL_SYNC_DEPENDENCY_FILE => $fullSyncUnit,
            JobType::REBUILD_GAME_DEPENDENCIES => $dependencyRefresh,
            JobType::REBUILD_FILE_DEPENDENCIES => $dependencyRefresh,
            JobType::REBUILD_AFFECTED_DEPENDENCIES => $affectedDependencyRefresh,
            JobType::REBUILD_FILE_SEARCH_INDEX => $searchIndex,
            JobType::RECONCILE_CATALOG_PROJECTIONS => $projectionReconciliation,
            JobType::REPAIR_SOURCE_IDENTITY_FILE => $maintenance,
            JobType::REPAIR_SOURCE_IDENTITY_GAME => $maintenance,
            JobType::SOURCE_SCAN => $sourceScan,
            JobType::CLEAN_UNVERIFIED_DUPLICATES => $duplicateCleanup,
            JobType::REPAIR_UNVERIFIED_METADATA => $metadataRepair,
            JobType::REFRESH_UNVERIFIED_GAME_MATCHES => $unverifiedMatchRefresh,
            JobType::CROSS_GAME_COPY_BATCH => $crossGameCopyBatch,
            JobType::GENERATE_MOD_PACKAGE => $generatedPackage,
            JobType::EXPORT_GAME_BACKUP => $backupExport,
            JobType::IMPORT_GAME_BACKUP => $backupImport,
            JobType::IMPORT_STAGED_PACKAGE => $packageImport,
            JobType::IMPORT_STAGED_PAK => $pakImport,
            JobType::PREPARE_BUCKET_REDIRECT => $bucketRedirect,
            JobType::PROCESS_BUCKET_UPLOAD => $bucketUpload,
            JobType::RECONCILE_UNVERIFIED_STORAGE => $storageMaintenance,
            JobType::PRUNE_STALE_ARTIFACTS => $storageMaintenance,
            JobType::PRUNE_UPLOAD_PROGRESS => $maintenance,
        ];

        return new JobWorker(
            new PdoJobQueue($db),
            $handlersByType,
            $queueName,
            $workerId,
            $leaseSeconds,
            $eventAppender,
            $diagnosticEnabled,
            $failureReporter
        );
    }

    /** @param array<string,mixed> $config */
    private static function raiseWorkerMemoryLimit(array $config): void
    {
        $target = trim((string)($config['queue']['worker_memory_limit'] ?? '512M'));
        if ($target === '' || $target === '-1') {
            return;
        }

        $targetBytes = self::memoryBytes($target);
        if ($targetBytes < 1) {
            return;
        }
        $current = trim((string)ini_get('memory_limit'));
        $currentBytes = self::memoryBytes($current);
        if ($current === '-1' || ($currentBytes > 0 && $currentBytes >= $targetBytes)) {
            return;
        }

        @ini_set('memory_limit', $target);
    }

    private static function memoryBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? PHP_INT_MAX : 0;
        }
        if (!preg_match('/^(\d+)([KMGTP]?)$/i', $value, $match)) {
            return 0;
        }
        $bytes = (int)$match[1];
        $power = match (strtoupper((string)$match[2])) {
            'K' => 1,
            'M' => 2,
            'G' => 3,
            'T' => 4,
            'P' => 5,
            default => 0,
        };
        for ($index = 0; $index < $power; $index++) {
            if ($bytes > intdiv(PHP_INT_MAX, 1024)) {
                return PHP_INT_MAX;
            }
            $bytes *= 1024;
        }
        return $bytes;
    }
}
