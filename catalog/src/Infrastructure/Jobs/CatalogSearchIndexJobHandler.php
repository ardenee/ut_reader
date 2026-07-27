<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoSearchDocumentIndexer;

/** Rebuilds compact search and package-dependency projections after a file changes. */
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
            throw new \RuntimeException('Search index refresh requires a positive file_id.');
        }

        $context->checkpoint([
            'stage' => 'search_index',
            'done' => 0,
            'total' => 2,
            'percent' => 0,
            'message' => 'Rebuilding file search documents.',
            'file_id' => $fileId,
        ]);

        $result = (new PdoSearchDocumentIndexer($this->db))->rebuildFile($fileId);
        $context->checkpoint([
            'stage' => 'dependency_summary',
            'done' => 1,
            'total' => 2,
            'percent' => 50,
            'message' => 'Rebuilding package dependency summary.',
            'file_id' => $fileId,
            'documents' => (int)$result['total'],
        ]);
        $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);

        $context->checkpoint([
            'stage' => 'search_index',
            'done' => 2,
            'total' => 2,
            'percent' => 100,
            'message' => !empty($result['indexed'])
                ? 'Search documents and dependency summary rebuilt.'
                : 'Search documents and dependency summary removed because the file is no longer verified.',
            'file_id' => $fileId,
            'documents' => (int)$result['total'],
            'dependency_summary_rows' => (int)$summary['summary_rows'],
        ]);

        return ['operation' => 'rebuild_file_search_index'] + $result + [
            'dependency_summary_rows' => (int)$summary['summary_rows'],
            'dependency_summary_available' => (bool)$summary['available'],
        ];
    }
}
