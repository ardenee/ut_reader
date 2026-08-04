<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/**
 * Rebuilds compact provider, dependency-summary and game-stat projections.
 *
 * The historical job type is retained so already queued jobs continue to run
 * after the retired search projection is removed.
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

        $gameStatement = $this->db->prepare('SELECT game_id FROM ue_files WHERE id=?');
        $gameStatement->execute([$fileId]);
        $gameId = (int)($gameStatement->fetchColumn() ?: 0);

        $context->checkpoint([
            'stage' => 'package_providers',
            'done' => 0,
            'total' => 3,
            'percent' => 0,
            'message' => 'Reconciling package provider rows.',
            'file_id' => $fileId,
        ]);
        (new PdoPackageProviderRepository($this->db))->reconcileFile($fileId);

        $context->checkpoint([
            'stage' => 'dependency_summary',
            'done' => 1,
            'total' => 3,
            'percent' => 34,
            'message' => 'Rebuilding compact package dependency summary.',
            'file_id' => $fileId,
        ]);
        $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);

        $context->checkpoint([
            'stage' => 'game_stats',
            'done' => 2,
            'total' => 3,
            'percent' => 67,
            'message' => 'Refreshing cached game counters.',
            'file_id' => $fileId,
            'dependency_summary_rows' => (int)$summary['summary_rows'],
        ]);
        $gameStats = $gameId > 0
            ? (new PdoGameCatalogStats($this->db))->rebuildGame($gameId)
            : null;

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 3,
            'total' => 3,
            'percent' => 100,
            'message' => 'Compact provider, dependency and game-counter projections rebuilt.',
            'file_id' => $fileId,
            'dependency_summary_rows' => (int)$summary['summary_rows'],
            'game_id' => $gameId,
        ]);

        return [
            'operation' => 'rebuild_file_search_index',
            'file_id' => $fileId,
            'package_providers_reconciled' => true,
            'dependency_summary_rows' => (int)$summary['summary_rows'],
            'dependency_summary_available' => (bool)$summary['available'],
            'game_id' => $gameId,
            'game_stats_refreshed' => $gameStats !== null,
            'compact_search_source' => true,
        ];
    }
}
