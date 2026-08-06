<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Queues one ordered post-import pipeline.
 *
 * The exact-file dependency job now reconciles the provider projection, rebuilds
 * the source summary and conditionally queues affected-file work after the source
 * dependency rows are authoritative. This avoids three independent jobs racing
 * to publish the same summary and game counters.
 */
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

        $fileJobId = (new PdoJobQueue($db))->enqueue(
            $queueName,
            JobType::REBUILD_FILE_DEPENDENCIES,
            [
                'file_id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
                'post_import' => true,
            ],
            20,
            null,
            'rebuild-file-dependencies:' . $fileId,
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
            // The exact-file pipeline is already durable. Worker lifecycle can be
            // repaired from Background Jobs without delaying or failing import.
            $workerError = trim($error->getMessage());
            error_log('[UnrealDB post-import pipeline] file_id=' . $fileId
                . ' worker bootstrap failed: ' . $workerError);
        }

        return [
            'search_job_id' => 0,
            'file_job_id' => $fileJobId,
            'affected_job_id' => 0,
            'worker_started' => $workerStarted,
            'worker_error' => $workerError,
        ];
    }
}
