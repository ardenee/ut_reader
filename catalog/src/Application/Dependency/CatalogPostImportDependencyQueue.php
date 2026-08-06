<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Search\CatalogSearchIndexQueue;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Queues post-import projection and dependency work without blocking the foreground request. */
final class CatalogPostImportDependencyQueue
{
    /**
     * @param array<string,mixed> $config
     * @return array{search_job_id:int,file_job_id:int,affected_job_id:int,worker_started:bool,worker_error:string}
     */
    public static function enqueue(
        PDO $db,
        array $config,
        int $fileId,
        int $gameId,
        string $packageName,
        ?int $createdBy = null
    ): array {
        if ($fileId < 1 || $gameId < 1) {
            throw new \InvalidArgumentException('Post-import dependency work requires valid file and game IDs.');
        }

        $queueName = trim((string)($config['queue']['name'] ?? 'catalog'));
        if ($queueName === '') {
            $queueName = 'catalog';
        }

        $searchJobId = CatalogSearchIndexQueue::enqueueFile($db, $fileId, $config, $createdBy);
        $queue = new PdoJobQueue($db);
        $fileJobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_FILE_DEPENDENCIES,
            [
                'file_id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
            ],
            20,
            null,
            'rebuild-file-dependencies:' . $fileId,
            $createdBy,
            3
        );
        $affectedJobId = $queue->enqueue(
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
            $createdBy,
            3
        );

        $workerStarted = false;
        $workerError = '';
        try {
            $launcher = new CatalogDetachedWorker($config);
            $status = $launcher->status($queueName);
            if ((int)($status['active_count'] ?? 0) + (int)($status['launching_count'] ?? 0) === 0) {
                $start = $launcher->start($queueName, 1000000);
                $workerStarted = !empty($start['started']);
            }
        } catch (Throwable $error) {
            // The jobs are already durable. Worker lifecycle can be repaired from
            // Background Jobs without making the import request fail or wait.
            $workerError = trim($error->getMessage());
            error_log('[UnrealDB post-import dependencies] file_id=' . $fileId
                . ' worker bootstrap failed: ' . $workerError);
        }

        return [
            'search_job_id' => $searchJobId,
            'file_job_id' => $fileJobId,
            'affected_job_id' => $affectedJobId,
            'worker_started' => $workerStarted,
            'worker_error' => $workerError,
        ];
    }
}
