<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Enqueues deduplicated search-index maintenance without blocking imports. */
final class CatalogSearchIndexQueue
{
    /** @param array<string,mixed>|null $config */
    public static function enqueueFile(
        PDO $db,
        int $fileId,
        ?array $config = null,
        ?int $createdBy = null
    ): int {
        if ($fileId < 1) {
            return 0;
        }

        try {
            $config ??= function_exists('catalog_config') ? \catalog_config() : [];
            $queueName = trim((string)($config['queue']['name'] ?? 'catalog'));
            if ($queueName === '') {
                $queueName = 'catalog';
            }

            $jobId = (new PdoJobQueue($db))->enqueue(
                $queueName,
                JobType::REBUILD_FILE_SEARCH_INDEX,
                ['file_id' => $fileId],
                60,
                null,
                'search-index-file:' . $fileId,
                $createdBy,
                3
            );

            $deferWorkerStart = !empty($config['queue']['defer_worker_start']);
            if ($config !== [] && !$deferWorkerStart) {
                try {
                    $launcher = new CatalogDetachedWorker($config);
                    $worker = $launcher->status($queueName);
                    if ((int)($worker['active_count'] ?? 0) + (int)($worker['launching_count'] ?? 0) === 0) {
                        $launcher->start($queueName, 10000);
                    }
                } catch (Throwable $workerError) {
                    error_log(
                        '[UnrealDB search index worker] job_id=' . $jobId
                        . ' launch failed; queued job remains durable: ' . $workerError->getMessage()
                    );
                }
            }

            return $jobId;
        } catch (Throwable $error) {
            error_log(
                '[UnrealDB search index queue] file_id=' . $fileId
                . ' enqueue failed: ' . $error->getMessage()
            );
            return 0;
        }
    }
}
