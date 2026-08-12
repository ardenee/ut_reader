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
    private const WORKFLOW_VERSION = 2;

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
        $resume = $context->resumeProgress();

        $containersComplete = (int)($resume['workflow_version'] ?? 0) >= self::WORKFLOW_VERSION
            && !empty($resume['source_containers_complete']);
        if ($containersComplete) {
            $containerResult = [
                'pak_jobs_queued' => max(0, (int)($resume['pak_jobs_queued'] ?? 0)),
                'pak_job_ids' => is_array($resume['pak_job_ids'] ?? null) ? array_values($resume['pak_job_ids']) : [],
                'pak_job_errors' => is_array($resume['pak_job_errors'] ?? null) ? array_values($resume['pak_job_errors']) : [],
            ];
        } else {
            $containerResult = (new CatalogSourcePakQueueService($this->db, $this->config))->queue(
                $sourceId,
                $strictProfile,
                $userId,
                static function (array $progress) use ($context): void {
                    $progress['workflow_version'] = self::WORKFLOW_VERSION;
                    $progress['source_containers_complete'] = false;
                    $context->heartbeatIfDue($progress);
                }
            );
            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'source_scan_containers_complete',
                'done' => 0,
                'total' => 1,
                'percent' => 0,
                'message' => 'Container queue preparation complete; starting/resuming loose package scan.',
                'source_containers_complete' => true,
                'pak_jobs_queued' => (int)$containerResult['pak_jobs_queued'],
                'pak_job_ids' => $containerResult['pak_job_ids'],
                'pak_job_errors' => $containerResult['pak_job_errors'],
            ]);
            $resume = $context->resumeProgress();
        }

        $result = (new CatalogSourceScanRunner($this->db, $this->config))->run(
            $sourceId,
            $importUnknown,
            $strictProfile,
            $userId,
            static function (array $progress) use ($context, $containerResult): void {
                $progress += [
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'source_containers_complete' => true,
                    'pak_jobs_queued' => $containerResult['pak_jobs_queued'],
                    'pak_job_ids' => $containerResult['pak_job_ids'],
                    'pak_job_errors' => $containerResult['pak_job_errors'],
                ];
                $stage = (string)($progress['stage'] ?? '');
                // Discovery can be repeated safely and must never overwrite the
                // last completed-file cursor. Once a file completes, checkpoint
                // immediately so a process/server restart resumes after it.
                if ($stage === 'scanning' || $stage === 'complete') {
                    $context->checkpoint($progress);
                    return;
                }
                $context->heartbeatIfDue($progress);
            },
            $resume
        );

        return [
            'operation' => 'source_scan',
            'workflow_version' => self::WORKFLOW_VERSION,
            'source_id' => $sourceId,
        ] + $containerResult + $result;
    }
}
