<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes durable source-scan jobs.
 * Why: The job handler should translate a claimed job into source-scan use cases, not own filesystem traversal or scan implementation.
 * Role: Thin job orchestration over CatalogSourcePakQueueService and CatalogSourceScanRunner.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourcePakQueueService;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceScanRunner;

final class CatalogSourceScanJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::SOURCE_SCAN;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceId = (int)($job->payload['source_id'] ?? 0);
        if ($sourceId < 1) {
            throw new \RuntimeException('A valid source_id is required for a source scan job.');
        }

        $importUnknown = filter_var($job->payload['import_unknown'] ?? false, FILTER_VALIDATE_BOOL);
        $strictProfile = !array_key_exists('strict_profile', $job->payload)
            || filter_var($job->payload['strict_profile'], FILTER_VALIDATE_BOOL);
        $userIdValue = $job->payload['user_id'] ?? $job->payload['created_by_user_id'] ?? null;
        $userId = (int)$userIdValue > 0 ? (int)$userIdValue : null;

        $containerResult = (new CatalogSourcePakQueueService($this->db, $this->config))->queue(
            $sourceId,
            $strictProfile,
            $userId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue($progress);
            }
        );

        $result = (new CatalogSourceScanRunner($this->db, $this->config))->run(
            $sourceId,
            $importUnknown,
            $strictProfile,
            $userId,
            static function (array $progress) use ($context, $containerResult): void {
                $progress += [
                    'pak_jobs_queued' => $containerResult['pak_jobs_queued'],
                    'pak_job_errors' => count($containerResult['pak_job_errors']),
                ];
                if ((string)($progress['stage'] ?? '') === 'complete') {
                    $context->checkpoint($progress);
                    return;
                }
                $context->heartbeatIfDue($progress);
            }
        );

        return ['operation' => 'source_scan', 'source_id' => $sourceId] + $containerResult + $result;
    }
}
