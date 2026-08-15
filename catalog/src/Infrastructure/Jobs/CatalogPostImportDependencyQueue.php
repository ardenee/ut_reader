<?php
/**
 * Durable post-import dependency queue adapter.
 *
 * Queue persistence is an Infrastructure concern. Application import workflows
 * depend on an abstract dependency-publication boundary rather than PDO/job rows.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

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
