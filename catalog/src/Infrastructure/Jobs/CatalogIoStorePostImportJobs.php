<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use InvalidArgumentException;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/**
 * Durable UE5 IoStore import/restore follow-ups. Existing active dedupe
 * keys make retries idempotent; the dependency job waits for all publication.
 * Never create a per-member dependency job or treat metadata-only ScriptObjects
 * as standalone packages.
 */
final class CatalogIoStorePostImportJobs
{
    public function __construct(private readonly JobQueue $queue) {}

    /** @return array{name_job_id:int,publish_job_id:int,dependency_job_id:?int} */
    public function enqueue(string $queueName, int $containerId, int $packageCount): array
    {
        if ($containerId < 1 || $packageCount < 0) {
            throw new InvalidArgumentException('Valid retained container and package count are required.');
        }
        $nameId=$this->queue->enqueue(
            $queueName,JobType::INDEX_IOSTORE_PACKAGE_NAMES,
            ['container_id'=>$containerId],110,null,'iostore-name-index-'.$containerId,null,3
        );
        $publishId=$this->queue->enqueue(
            $queueName,JobType::PUBLISH_IOSTORE_PACKAGES,
            ['container_id'=>$containerId],110,null,'iostore-publish-'.$containerId,null,3
        );
        // Enqueue from the intake job rather than depending solely on the
        // final publication callback. The handler defers while source packages
        // are not yet published, and fails visibly if name indexing failed.
        $dependencyId=$packageCount>0
            ? $this->queue->enqueue(
                $queueName,JobType::INDEX_IOSTORE_DEPENDENCIES,
                ['container_id'=>$containerId],25,null,'iostore-dependency-'.$containerId,null,3
            )
            : null;
        return ['name_job_id'=>$nameId,
            'publish_job_id'=>$publishId,'dependency_job_id'=>$dependencyId];
    }
}
