<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogJobWorkerFactory` for catalog job worker factory.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page/API/job entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoArchiveParentLifecycleRepair;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoArchiveProfileMismatchOutcomeRepair;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoInvalidUeSystemErrorBackfill;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogJobWorkerFactory
{
    // Worker fingerprint marker: 20260820-game-file-reassignment-v1.
    /** @param array<string,mixed> $config */
    public static function create(
        PDO $db,
        array $config,
        string $queueName,
        string $workerId,
        int $leaseSeconds
    ): JobWorker {
        self::raiseWorkerMemoryLimit($config);

        // Durable rows persist the resource policy that existed when they were
        // queued. Repair queued rows on process startup so a code-level policy
        // correction applies to already-waiting work after a normal worker
        // restart; running rows retain their current ownership and finish
        // untouched.
        try {
            (new CatalogJobResourceLimitStore($db, $queueName))->synchronizeQueuedPolicies();
        } catch (\Throwable $error) {
            error_log('[UnrealDB worker policy sync] ' . $error->getMessage());
        }

        // Historical profile mismatches and deterministic invalid-package
        // outcomes were retained correctly but both were folded into generic
        // archive failure state. Reclassify only content-proven outcomes, then
        // re-run coordinator aggregation from existing child rows. No archive or
        // package source bytes are re-read here.
        try {
            $profileMismatchRepair = (new PdoArchiveProfileMismatchOutcomeRepair($db))->repair($queueName);
            if ($profileMismatchRepair['reclassified'] > 0 || $profileMismatchRepair['requeued'] > 0) {
                error_log('[UnrealDB archive outcome repair] Reclassified '
                    . (int)($profileMismatchRepair['profile_mismatch_reclassified'] ?? 0)
                    . ' profile mismatch and '
                    . (int)($profileMismatchRepair['invalid_ue_reclassified'] ?? 0)
                    . ' invalid UE child outcome(s); requeued '
                    . $profileMismatchRepair['requeued']
                    . ' coordinator(s) for ledger-only aggregation.');
            }
        } catch (\Throwable $error) {
            error_log('[UnrealDB archive outcome classification repair] ' . $error->getMessage());
        }

        // Invalid Unreal package content is a System Error/data-quality problem,
        // not retryable archive work. Backfill historical completed child outcomes
        // once from durable metadata; no archive/package bytes are reopened.
        try {
            $invalidUeBackfill = (new PdoInvalidUeSystemErrorBackfill($db))->run($queueName);
            if ($invalidUeBackfill['recorded'] > 0 || $invalidUeBackfill['failed'] > 0) {
                error_log('[UnrealDB invalid UE System Error backfill] Recorded '
                    . $invalidUeBackfill['recorded'] . ' invalid UE file error(s); '
                    . $invalidUeBackfill['failed'] . ' persistence failure(s).');
            }
        } catch (\Throwable $error) {
            error_log('[UnrealDB invalid UE System Error backfill] ' . $error->getMessage());
        }

        // Older archive coordinators completed their parent row immediately after
        // enqueueing children. Reopen only those completed parents that still have
        // queued/running children so deploying the corrected lifecycle also repairs
        // work that was already in flight. The operation is idempotent and bounded.
        try {
            $reopenedArchiveParents = (new PdoArchiveParentLifecycleRepair($db))
                ->reopenCompletedParentsWithActiveChildren($queueName);
            if ($reopenedArchiveParents > 0) {
                error_log('[UnrealDB archive lifecycle] Reopened ' . $reopenedArchiveParents
                    . ' completed archive parent(s) that still had active children.');
            }
        } catch (\Throwable $error) {
            error_log('[UnrealDB archive lifecycle repair] ' . $error->getMessage());
        }

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
        $sourceContextResolver = new CatalogJobSourceContextResolver($db, $config);
        $failureReporter = static function (
            ClaimedJob $job,
            \Throwable $error,
            string $disposition
        ) use ($sourceContextResolver): void {
            if ($disposition === 'retry_queued' && PdoContention::retryable($error)) {
                return;
            }

            $context = [
                'job_id' => $job->id,
                'job_type' => $job->type,
                'attempt' => $job->attempt,
                'max_attempts' => $job->maxAttempts,
                'disposition' => $disposition,
                'resource_class' => $job->resourceClass,
                'concurrency_key' => $job->concurrencyKey,
            ];
            try {
                // Failure provenance is diagnostic-only. If a source has already
                // been cleaned up, preserve the actual job failure rather than
                // allowing diagnostic enrichment to interfere with queue state.
                $context = $context + $sourceContextResolver->forClaimedJob($job);
            } catch (\Throwable $sourceError) {
                $context['source_context_error'] = trim($sourceError->getMessage()) !== ''
                    ? trim($sourceError->getMessage())
                    : get_class($sourceError);
            }

            // The table already has type/source columns and the structured
            // context retains job, archive and path provenance. Keep the visible
            // sentence to the actual cause; queue terms such as "dead_letter"
            // and temporary storage paths are not useful error wording.
            $message = trim($error->getMessage());
            if ($message === '') {
                $message = get_class($error) . ' did not provide an error message.';
            }

            CatalogSystemErrorRecorder::record([
                'source_kind' => 'background-job',
                'severity' => 'error',
                'error_type' => get_class($error),
                'message' => $message,
                'source_file' => $error->getFile(),
                'source_line' => $error->getLine(),
                'trace_text' => $error->getTraceAsString(),
                'context' => $context,
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
            JobType::REBUILD_AFFECTED_DEPENDENCIES => static fn() => new CatalogNonBlockingAffectedDependencyJobHandler($db, $config),
            JobType::REBUILD_FILE_SEARCH_INDEX => static fn() => new CatalogSearchIndexJobHandler($db),
            JobType::SCAN_POSSIBLE_MISNAMED_FILES => static fn() => new CatalogMisnamedFileScanJobHandler($db),
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
            JobType::UNVERIFIED_BULK_ACTION => static fn() => new CatalogUnverifiedBulkActionJobHandler($db, $trustedImportConfig),
            JobType::UNVERIFIED_BULK_ACTION_BATCH => static fn() => new CatalogUnverifiedBulkActionJobHandler($db, $trustedImportConfig),
            JobType::GAME_FILE_REASSIGN => static fn() => new CatalogGameFileReassignmentJobHandler($db, $trustedImportConfig),
            JobType::GAME_FILE_REASSIGN_BATCH => static fn() => new CatalogGameFileReassignmentJobHandler($db, $trustedImportConfig),
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
            JobType::IMPORT_STAGED_PACKAGE => static fn() => new CatalogArchiveMemberContentRoutingJobHandler(
                new CatalogUnsupportedRedirectExclusionJobHandler(
                    new CatalogNonBlockingImportJobHandler(
                        new CatalogStagedImportJobHandler($db, $trustedImportConfig),
                        $db,
                        $trustedImportConfig
                    ),
                    $db,
                    $trustedImportConfig
                ),
                $db,
                $trustedImportConfig
            ),
            JobType::IMPORT_STAGED_PAK => static fn() => new CatalogPakImportJobHandler($db, $trustedImportConfig),
            JobType::IMPORT_STAGED_PAK_ENTRY => static fn() => new CatalogPakImportJobHandler($db, $trustedImportConfig),
            JobType::IMPORT_STAGED_ARCHIVE => static fn() => new CatalogArchiveWorkflowJobHandler($db, $trustedImportConfig),
            JobType::PREPARE_BUCKET_REDIRECT => static fn() => new CatalogUnsupportedRedirectExclusionJobHandler(
                new CatalogBucketRedirectJobHandler($db, $trustedImportConfig),
                $db,
                $trustedImportConfig
            ),
            JobType::PROCESS_BUCKET_UPLOAD => static fn() => new CatalogUnsupportedRedirectExclusionJobHandler(
                new CatalogBucketUploadJobHandler($db, $trustedImportConfig),
                $db,
                $trustedImportConfig
            ),
            JobType::PROCESS_PUBLIC_UPLOAD => static fn() => new CatalogPublicUploadJobHandler($db, $trustedImportConfig),
            JobType::PROCESS_BUCKET_ARCHIVE => static fn() => new CatalogArchiveWorkflowJobHandler($db, $trustedImportConfig),
            JobType::PROCESS_BUCKET_STAGED_PACKAGE => static fn() => new CatalogArchiveMemberContentRoutingJobHandler(
                new CatalogUnsupportedRedirectExclusionJobHandler(
                    new CatalogBucketStagedPackageJobHandler($db, $trustedImportConfig),
                    $db,
                    $trustedImportConfig
                ),
                $db,
                $trustedImportConfig
            ),
            JobType::RECONCILE_UNVERIFIED_STORAGE => static fn() => new CatalogStorageMaintenanceJobHandler($db, $config),
            JobType::PRUNE_STALE_ARTIFACTS => static fn() => new CatalogStorageMaintenanceJobHandler($db, $config),
            JobType::PRUNE_PUBLIC_UPLOADS => static fn() => new CatalogPublicUploadMaintenanceJobHandler($db, $config),
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
