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
        // source, a PAK extraction, or a managed game backup. Those entry points
        // validate their own input before creating the worker job.
        $trustedImportConfig = $config;
        $trustedImportConfig['max_upload_bytes'] = PHP_INT_MAX;

        return new JobWorker(
            new WorkerJobQueue($db),
            [
                // PAK imports retain the original container and build entry/file
                // relationships, so they must be claimed before the generic
                // staged-import handler that also recognises IMPORT_STAGED_PAK.
                new CatalogPakImportJobHandler($db, $trustedImportConfig),
                new CatalogNonBlockingImportJobHandler(
                    new CatalogStagedImportJobHandler($db, $trustedImportConfig),
                    $config
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
            $leaseSeconds
        );
    }
}
