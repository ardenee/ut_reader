<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/**
 * Reconciles the package-provider projection.
 *
 * The historical job type is retained so old queue rows remain executable. It
 * no longer rebuilds dependency summaries or whole-game counters from partially
 * refreshed dependency data; ordered dependency jobs own those projections.
 */
final class CatalogSearchIndexJobHandler implements JobHandler
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REBUILD_FILE_SEARCH_INDEX;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = (int)($job->payload['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new \RuntimeException('Projection refresh requires a positive file_id.');
        }

        $context->checkpoint([
            'stage' => 'package_providers',
            'done' => 0,
            'total' => 1,
            'percent' => 0,
            'message' => 'Reconciling package provider rows.',
            'file_id' => $fileId,
        ]);
        (new PdoPackageProviderRepository($this->db))->reconcileFile($fileId);

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'message' => 'Package provider projection reconciled.',
            'file_id' => $fileId,
        ]);

        return [
            'operation' => 'rebuild_file_search_index',
            'file_id' => $fileId,
            'package_providers_reconciled' => true,
            'dependency_summary_rebuilt' => false,
            'game_stats_refreshed' => false,
            'compact_search_source' => true,
        ];
    }
}
