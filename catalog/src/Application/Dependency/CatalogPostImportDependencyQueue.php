<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Queues one ordered post-import pipeline.
 *
 * The exact-file dependency job reconciles the provider projection, rebuilds the
 * source summary and conditionally queues affected-file work after the source
 * dependency rows are authoritative. Worker lifecycle is deliberately excluded
 * from the HTTP import request so a stopped or partial pool cannot delay files.
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

        return [
            'search_job_id' => 0,
            'file_job_id' => $fileJobId,
            'affected_job_id' => 0,
            'worker_started' => false,
            'worker_error' => '',
        ];
    }
}
