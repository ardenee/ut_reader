<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\WorkerJobQueue;

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

        // max_upload_bytes protects browser/staging ingress. It must not reject a
        // trusted package after it has already entered durable staging, a local
        // source, a PAK extraction, or a managed game backup. Preserve the real
        // ingress limit separately so redirect decompression still has a finite,
        // configurable expansion ceiling rather than inheriting PHP_INT_MAX.
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

        $eventLog = new CatalogJobEventLog($config);
        $eventAppender = static function (int $jobId, array $event) use ($eventLog): void {
            $eventLog->append($jobId, $event);
        };

        return new JobWorker(
            new WorkerJobQueue($db),
            [
                // New Upload Bucket batches transfer every file first, then this
                // handler performs duplicate checks, decompression and inventory.
                new CatalogBucketUploadJobHandler($db, $trustedImportConfig),
                // Repair only incomplete Upload Bucket/unverified metadata without
                // moving or re-uploading the physical file.
                new CatalogUnverifiedMetadataRepairJobHandler($db, $trustedImportConfig),
                // Legacy redirect jobs remain readable/restartable after upgrades.
                new CatalogBucketRedirectJobHandler($db, $trustedImportConfig),
                // PAK imports retain the original container and build entry/file
                // relationships, so they must be claimed before the generic
                // staged-import handler that also recognises IMPORT_STAGED_PAK.
                new CatalogPakImportJobHandler($db, $trustedImportConfig),
                new CatalogNonBlockingImportJobHandler(
                    new CatalogStagedImportJobHandler($db, $trustedImportConfig),
                    $trustedImportConfig
                ),
                new CatalogSourceScanJobHandler($db, $trustedImportConfig),
                new CatalogStorageMaintenanceJobHandler($db, $config),
                new UnverifiedDuplicateCleanupJobHandler($db, $config),
                new GeneratedPackageJobHandler($db, $config),
                new GameBackupExportJobHandler($db, $config),
                new GameBackupJobHandler($db, $trustedImportConfig),
                // Search documents and the imported file's package summary are
                // maintained outside the package import transaction.
                new CatalogSearchIndexJobHandler($db),
                // Manual file/game rebuilds maintain detailed and summary rows in
                // one worker pass instead of creating a second queue per file.
                new CatalogDependencyRefreshJobHandler($db, $config),
                // Affected dependency refreshes continue past individual file
                // failures and report processed/failure counts in the job result.
                new CatalogAffectedDependencyRefreshJobHandler($db, $config),
                // CatalogMaintenanceJobHandler currently recognises every registered
                // JobType before dispatching its own subset, so it must remain last.
                new CatalogMaintenanceJobHandler($db, $config),
            ],
            $queueName,
            $workerId,
            $leaseSeconds,
            $eventAppender
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
