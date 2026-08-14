<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogJobWorkerFactory` for catalog job worker factory.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogJobWorkerFactory
{
    // Worker fingerprint marker: 20260814-lazy-handler-registry-v1.
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
            if ($disposition === 'retry_queued' && PdoContention::retryable($error)) {
                return;
            }
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

        // Register factories rather than eagerly constructing the entire job
        // catalogue in every worker process. JobWorker validates the route keys
        // immediately and instantiates only the handler type it actually claims.
        $handlersByType = [
            JobType::FULL_SYNC_GAME => static fn() => new CatalogFullSyncJobHandler($db, $trustedImportConfig),
            JobType::FULL_SYNC_FILE => static fn() => new CatalogFullSyncUnitJobHandler($db, $trustedImportConfig),
            JobType::FULL_SYNC_DEPENDENCY_FILE => static fn() => new CatalogFullSyncUnitJobHandler($db, $trustedImportConfig),
            JobType::REPAIR_COMPACT_METADATA_FILE => static fn() => new CatalogCompactMetadataRepairJobHandler($db, $trustedImportConfig),
            JobType::REBUILD_GAME_DEPENDENCIES => static fn() => new CatalogDependencyRefreshJobHandler($db, $config),
            JobType::REBUILD_FILE_DEPENDENCIES => static fn() => new CatalogDependencyRefreshJobHandler($db, $config),
            JobType::REBUILD_AFFECTED_DEPENDENCIES => static fn() => new CatalogAffectedDependencyRefreshJobHandler($db, $config),
            JobType::REBUILD_FILE_SEARCH_INDEX => static fn() => new CatalogSearchIndexJobHandler($db),
            JobType::RECONCILE_CATALOG_PROJECTIONS => static fn() => new CatalogProjectionReconciliationJobHandler($db, $config),
            JobType::RECONCILE_CATALOG_PROJECTION_FILE => static fn() => new CatalogProjectionReconciliationJobHandler($db, $config),
            JobType::REPAIR_SOURCE_IDENTITY_FILE => static fn() => new CatalogMaintenanceJobHandler($db, $config),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => static fn() => new CatalogMaintenanceJobHandler($db, $config),
            JobType::SOURCE_SCAN => static fn() => new CatalogSourceScanJobHandler($db, $trustedImportConfig),
            JobType::CLEAN_BACKGROUND_JOB_HISTORY => static fn() => new CatalogBackgroundJobHistoryCleanupJobHandler($db, $config),
            JobType::CLEAN_UNVERIFIED_DUPLICATES => static fn() => new UnverifiedDuplicateCleanupJobHandler($db, $config),
            JobType::HASH_UNVERIFIED_DUPLICATE => static fn() => new UnverifiedDuplicateCleanupJobHandler($db, $config),
            JobType::DELETE_UNVERIFIED_DUPLICATE => static fn() => new UnverifiedDuplicateCleanupJobHandler($db, $config),
            JobType::REPAIR_UNVERIFIED_METADATA => static fn() => new CatalogUnverifiedMetadataRepairJobHandler($db, $trustedImportConfig),
            JobType::REFRESH_UNVERIFIED_GAME_MATCHES => static fn() => new CatalogUnverifiedGameMatchRefreshJobHandler($db, $trustedImportConfig),
            JobType::CROSS_GAME_COPY_BATCH => static fn() => new CatalogCrossGameCopyBatchJobHandler($db, $trustedImportConfig),
            JobType::PROFILED_UPLOAD_BATCH => static fn() => new CatalogProfiledUploadBatchJobHandler($db, $trustedImportConfig),
            JobType::GENERATE_MOD_PACKAGE => static fn() => new GeneratedPackageJobHandler($db, $config),
            JobType::EXPORT_GAME_BACKUP => static fn() => new GameBackupExportJobHandler($db, $config),
            JobType::IMPORT_GAME_BACKUP => static fn() => new GameBackupImportCleanupJobHandler(
                new GameBackupImportJobHandler($db, $trustedImportConfig),
                $trustedImportConfig
            ),
            JobType::IMPORT_GAME_BACKUP_ENTRY => static fn() => new GameBackupImportCleanupJobHandler(
                new GameBackupImportJobHandler($db, $trustedImportConfig),
                $trustedImportConfig
            ),
            JobType::IMPORT_STAGED_PACKAGE => static fn() => new CatalogNonBlockingImportJobHandler(
                new CatalogStagedImportJobHandler($db, $trustedImportConfig),
                $db,
                $trustedImportConfig
            ),
            JobType::IMPORT_STAGED_PAK => static fn() => new CatalogPakImportJobHandler($db, $trustedImportConfig),
            JobType::IMPORT_STAGED_PAK_ENTRY => static fn() => new CatalogPakImportJobHandler($db, $trustedImportConfig),
            JobType::PREPARE_BUCKET_REDIRECT => static fn() => new CatalogBucketRedirectJobHandler($db, $trustedImportConfig),
            JobType::PROCESS_BUCKET_UPLOAD => static fn() => new CatalogBucketUploadJobHandler($db, $trustedImportConfig),
            JobType::RECONCILE_UNVERIFIED_STORAGE => static fn() => new CatalogStorageMaintenanceJobHandler($db, $config),
            JobType::PRUNE_STALE_ARTIFACTS => static fn() => new CatalogStorageMaintenanceJobHandler($db, $config),
            JobType::PRUNE_UPLOAD_PROGRESS => static fn() => new CatalogMaintenanceJobHandler($db, $config),
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
