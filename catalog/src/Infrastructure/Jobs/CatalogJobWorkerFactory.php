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
}
