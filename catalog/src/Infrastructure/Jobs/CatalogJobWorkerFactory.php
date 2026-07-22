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
        // max_upload_bytes is an HTTP/staging ingress limit, not a valid ceiling
        // for a package extracted from an already accepted PAK container. The
        // profiled uploader and PAK queue validate their own limits before this
        // worker starts, so extracted packages may use their real on-disk size.
        $pakImportConfig = $config;
        $pakImportConfig['max_upload_bytes'] = PHP_INT_MAX;

        return new JobWorker(
            new WorkerJobQueue($db),
            [
                // PAK imports retain the original container and build entry/file
                // relationships, so they must be claimed before the generic
                // staged-import handler that also recognises IMPORT_STAGED_PAK.
                new CatalogPakImportJobHandler($db, $pakImportConfig),
                new CatalogNonBlockingImportJobHandler(
                    new CatalogStagedImportJobHandler($db, $config),
                    $config
                ),
                new CatalogSourceScanJobHandler($db, $config),
                new CatalogStorageMaintenanceJobHandler($db, $config),
                new UnverifiedDuplicateCleanupJobHandler($db, $config),
                new GeneratedPackageJobHandler($db, $config),
                new GameBackupExportJobHandler($db, $config),
                new GameBackupJobHandler($db, $config),
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
