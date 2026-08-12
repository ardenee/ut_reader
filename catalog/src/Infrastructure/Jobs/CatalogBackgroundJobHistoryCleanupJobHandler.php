<?php
/**
 * Durable bulk cleanup for terminal background-job history.
 *
 * The HTTP layer snapshots the eligible terminal job IDs and enqueues this one
 * bounded worker job. Each claim removes at most BATCH_SIZE snapshot entries,
 * checkpoints the exact offset, and defers itself until the snapshot is exhausted.
 * A worker/server restart therefore resumes at the first unprocessed snapshot ID.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogBackgroundJobHistoryCleanupJobHandler implements JobHandler
{
    private const BATCH_SIZE = 200;
    private const MAX_SNAPSHOT_IDS = 10000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly \PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::CLEAN_BACKGROUND_JOB_HISTORY;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $targetQueue = trim((string)($job->payload['target_queue'] ?? ''));
        if ($targetQueue === '' || strlen($targetQueue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $targetQueue) !== 1) {
            throw new \InvalidArgumentException('Background-job history cleanup requires a valid target_queue.');
        }

        $ids = $this->snapshotIds($job->payload['job_ids'] ?? []);
        $requested = max(count($ids), (int)($job->payload['requested'] ?? count($ids)));
        $limited = !empty($job->payload['limited']);
        $resume = $context->resumeProgress();
        $offset = max(0, min(count($ids), (int)($resume['snapshot_offset'] ?? 0)));
        $deletedJobs = max(0, (int)($resume['deleted_jobs'] ?? 0));
        $deletedStagedFiles = max(0, (int)($resume['deleted_staged_files'] ?? 0));
        $deletedStagedBytes = max(0, (int)($resume['deleted_staged_bytes'] ?? 0));
        $skipped = max(0, (int)($resume['skipped'] ?? 0));

        if ($ids === []) {
            $message = 'Background-job history cleanup snapshot contains no terminal jobs.';
            $context->checkpoint($this->progress(100, $message, [
                'stage' => 'complete',
                'snapshot_offset' => 0,
                'snapshot_total' => 0,
                'requested' => $requested,
                'deleted_jobs' => 0,
                'deleted_staged_files' => 0,
                'deleted_staged_bytes' => 0,
                'skipped' => 0,
                'limited' => $limited,
            ]));
            return $this->result($targetQueue, $requested, 0, 0, 0, 0, $limited);
        }

        $slice = array_slice($ids, $offset, self::BATCH_SIZE);
        if ($slice !== []) {
            $context->checkpoint($this->progress(
                (int)floor(($offset * 100) / max(1, count($ids))),
                'Deleting terminal background-job history ' . ($offset + 1) . '-'
                    . min(count($ids), $offset + count($slice)) . ' of ' . count($ids) . '.',
                [
                    'stage' => 'cleanup_batch',
                    'snapshot_offset' => $offset,
                    'snapshot_total' => count($ids),
                    'requested' => $requested,
                    'deleted_jobs' => $deletedJobs,
                    'deleted_staged_files' => $deletedStagedFiles,
                    'deleted_staged_bytes' => $deletedStagedBytes,
                    'skipped' => $skipped,
                    'limited' => $limited,
                ]
            ));

            $batch = (new CatalogBackgroundJobCleanup($this->db, $this->config))
                ->deleteTerminalJobs($slice, $targetQueue);
            $batchDeleted = max(0, (int)($batch['deleted_jobs'] ?? 0));
            $deletedJobs += $batchDeleted;
            $deletedStagedFiles += max(0, (int)($batch['deleted_staged_files'] ?? 0));
            $deletedStagedBytes += max(0, (int)($batch['deleted_staged_bytes'] ?? 0));
            $skipped += max(0, count($slice) - $batchDeleted);
            $offset += count($slice);
        }

        $progress = $this->progress(
            (int)floor(($offset * 100) / max(1, count($ids))),
            'Background-job history cleanup: ' . $offset . '/' . count($ids)
                . ' snapshot IDs processed; ' . $deletedJobs . ' deleted, ' . $skipped . ' skipped.',
            [
                'stage' => $offset >= count($ids) ? 'complete' : 'cleanup_batch',
                'snapshot_offset' => $offset,
                'snapshot_total' => count($ids),
                'requested' => $requested,
                'deleted_jobs' => $deletedJobs,
                'deleted_staged_files' => $deletedStagedFiles,
                'deleted_staged_bytes' => $deletedStagedBytes,
                'skipped' => $skipped,
                'limited' => $limited,
            ]
        );

        if ($offset < count($ids)) {
            $context->defer(1, $progress);
        }

        $progress['percent'] = 100;
        $progress['done'] = 100;
        $progress['total'] = 100;
        $progress['stage'] = 'complete';
        $progress['message'] = 'Background-job history cleanup complete: ' . $deletedJobs . ' job(s) deleted, '
            . $skipped . ' skipped, ' . $deletedStagedFiles . ' retained staged file(s) removed.'
            . ($limited ? ' The 10,000-job snapshot limit was reached; run cleanup again for the remainder.' : '');
        $context->checkpoint($progress);

        return $this->result(
            $targetQueue,
            $requested,
            $deletedJobs,
            $deletedStagedFiles,
            $deletedStagedBytes,
            $skipped,
            $limited
        );
    }

    /** @return list<int> */
    private function snapshotIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Background-job cleanup snapshot must be an array.');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        )));
        if (count($ids) > self::MAX_SNAPSHOT_IDS) {
            throw new \InvalidArgumentException('Background-job cleanup snapshot exceeds 10,000 IDs.');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(int $percent, string $message, array $extra): array
    {
        $percent = max(0, min(100, $percent));
        return $extra + [
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function result(
        string $targetQueue,
        int $requested,
        int $deletedJobs,
        int $deletedStagedFiles,
        int $deletedStagedBytes,
        int $skipped,
        bool $limited
    ): array {
        return [
            'operation' => 'clean_background_job_history',
            'status' => 'completed',
            'target_queue' => $targetQueue,
            'requested' => $requested,
            'deleted_jobs' => $deletedJobs,
            'deleted_staged_files' => $deletedStagedFiles,
            'deleted_staged_bytes' => $deletedStagedBytes,
            'skipped' => $skipped,
            'limited' => $limited,
            'message' => 'Removed ' . $deletedJobs . ' terminal background job(s) and '
                . $deletedStagedFiles . ' retained staged file(s).',
        ];
    }
}
