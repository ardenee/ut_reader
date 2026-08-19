<?php
/**
 * Durable bulk cleanup for terminal background-job history.
 *
 * The HTTP layer snapshots the eligible terminal job IDs and enqueues this one
 * bounded worker job. Large workflow trees are drained leaf-first in bounded
 * batches before their operator-visible parent is removed, avoiding enormous
 * InnoDB ON DELETE CASCADE transactions with no heartbeat.
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
    private const MAX_WORKFLOW_ROWS_PER_CLAIM = 100000;
    private const MAX_STACK_DEPTH = 64;

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
        $deletedWorkflowUnits = max(0, (int)($resume['deleted_workflow_units'] ?? 0));
        $deletedStagedFiles = max(0, (int)($resume['deleted_staged_files'] ?? 0));
        $deletedStagedBytes = max(0, (int)($resume['deleted_staged_bytes'] ?? 0));
        $skipped = max(0, (int)($resume['skipped'] ?? 0));
        $activeRootId = max(0, (int)($resume['cleanup_root_id'] ?? 0));
        $stack = $this->stackIds($resume['cleanup_stack'] ?? []);

        if ($ids === []) {
            $message = 'Background-job history cleanup snapshot contains no terminal jobs.';
            $context->checkpoint($this->progress(100, $message, [
                'stage' => 'complete',
                'snapshot_offset' => 0,
                'snapshot_total' => 0,
                'requested' => $requested,
                'deleted_jobs' => 0,
                'deleted_workflow_units' => 0,
                'deleted_staged_files' => 0,
                'deleted_staged_bytes' => 0,
                'skipped' => 0,
                'limited' => $limited,
            ]));
            return $this->result($targetQueue, $requested, 0, 0, 0, 0, 0, $limited);
        }

        $cleanup = new CatalogBackgroundJobCleanup($this->db, $this->config);
        $pruner = new CatalogBackgroundJobSubtreePruner($this->db);
        $workflowRowsThisClaim = 0;
        $rootsThisClaim = 0;

        while ($offset < count($ids) && $rootsThisClaim < self::BATCH_SIZE) {
            $rootId = (int)$ids[$offset];
            if ($activeRootId !== $rootId || $stack === []) {
                $activeRootId = $rootId;
                $stack = [$rootId];
            }

            $currentId = (int)end($stack);
            if ($currentId < 1 || !$pruner->exists($currentId)) {
                array_pop($stack);
                if ($currentId === $rootId || $stack === []) {
                    $skipped++;
                    $offset++;
                    $rootsThisClaim++;
                    $activeRootId = 0;
                    $stack = [];
                }
                continue;
            }

            $children = $pruner->childPage($currentId);
            if ($children['leaf_ids'] !== []) {
                $batchDeleted = $pruner->deleteLeafRows($children['leaf_ids']);
                $deletedWorkflowUnits += $batchDeleted;
                $workflowRowsThisClaim += $batchDeleted;
                $progress = $this->progress(
                    $this->rootPercent($offset, count($ids), false),
                    'Draining hidden workflow history under job #' . $rootId . ': '
                        . number_format($deletedWorkflowUnits) . ' child execution row(s) removed; '
                        . ($offset + 1) . '/' . count($ids) . ' parent job(s) in the snapshot.',
                    $this->state(
                        'cleanup_children',
                        $offset,
                        count($ids),
                        $requested,
                        $deletedJobs,
                        $deletedWorkflowUnits,
                        $deletedStagedFiles,
                        $deletedStagedBytes,
                        $skipped,
                        $limited,
                        $rootId,
                        $stack
                    )
                );
                $context->checkpoint($progress);
                if ($workflowRowsThisClaim >= self::MAX_WORKFLOW_ROWS_PER_CLAIM) {
                    $context->defer(1, $progress);
                }
                continue;
            }

            if ($children['branch_ids'] !== []) {
                $next = (int)$children['branch_ids'][0];
                if (count($stack) >= self::MAX_STACK_DEPTH) {
                    throw new \RuntimeException(
                        'Background-job workflow cleanup exceeded the supported tree depth at job #' . $next . '.'
                    );
                }
                $stack[] = $next;
                continue;
            }

            // This node has no children. Hidden descendants are removed directly;
            // only snapshot roots go through the normal cleanup object so retained
            // staged sources and the root event log keep their established cleanup.
            if ($currentId !== $rootId) {
                $batchDeleted = $pruner->deleteLeafRows([$currentId]);
                $deletedWorkflowUnits += $batchDeleted;
                $workflowRowsThisClaim += $batchDeleted;
                array_pop($stack);
                if ($workflowRowsThisClaim >= self::MAX_WORKFLOW_ROWS_PER_CLAIM) {
                    $progress = $this->progress(
                        $this->rootPercent($offset, count($ids), false),
                        'Draining hidden workflow history under job #' . $rootId . ': '
                            . number_format($deletedWorkflowUnits) . ' child execution row(s) removed.',
                        $this->state(
                            'cleanup_children',
                            $offset,
                            count($ids),
                            $requested,
                            $deletedJobs,
                            $deletedWorkflowUnits,
                            $deletedStagedFiles,
                            $deletedStagedBytes,
                            $skipped,
                            $limited,
                            $rootId,
                            $stack
                        )
                    );
                    $context->checkpoint($progress);
                    $context->defer(1, $progress);
                }
                continue;
            }

            $batch = $cleanup->deleteTerminalJobs([$rootId], $targetQueue);
            $batchDeleted = max(0, (int)($batch['deleted_jobs'] ?? 0));
            $deletedJobs += $batchDeleted;
            $deletedStagedFiles += max(0, (int)($batch['deleted_staged_files'] ?? 0));
            $deletedStagedBytes += max(0, (int)($batch['deleted_staged_bytes'] ?? 0));
            if ($batchDeleted < 1) {
                $skipped++;
            }
            $offset++;
            $rootsThisClaim++;
            $activeRootId = 0;
            $stack = [];

            $context->checkpoint($this->progress(
                $this->rootPercent($offset, count($ids), true),
                'Background-job history cleanup: ' . $offset . '/' . count($ids)
                    . ' parent job(s) processed; ' . $deletedJobs . ' deleted, ' . $skipped . ' skipped; '
                    . number_format($deletedWorkflowUnits) . ' hidden workflow row(s) drained.',
                $this->state(
                    $offset >= count($ids) ? 'complete' : 'cleanup_batch',
                    $offset,
                    count($ids),
                    $requested,
                    $deletedJobs,
                    $deletedWorkflowUnits,
                    $deletedStagedFiles,
                    $deletedStagedBytes,
                    $skipped,
                    $limited,
                    0,
                    []
                )
            ));
        }

        $progress = $this->progress(
            $this->rootPercent($offset, count($ids), true),
            'Background-job history cleanup: ' . $offset . '/' . count($ids)
                . ' parent job(s) processed; ' . $deletedJobs . ' deleted, ' . $skipped . ' skipped; '
                . number_format($deletedWorkflowUnits) . ' hidden workflow row(s) drained.',
            $this->state(
                $offset >= count($ids) ? 'complete' : 'cleanup_batch',
                $offset,
                count($ids),
                $requested,
                $deletedJobs,
                $deletedWorkflowUnits,
                $deletedStagedFiles,
                $deletedStagedBytes,
                $skipped,
                $limited,
                $activeRootId,
                $stack
            )
        );

        if ($offset < count($ids)) {
            $context->defer(1, $progress);
        }

        $progress['percent'] = 100;
        $progress['done'] = 100;
        $progress['total'] = 100;
        $progress['stage'] = 'complete';
        $progress['cleanup_root_id'] = 0;
        $progress['cleanup_stack'] = [];
        $progress['message'] = 'Background-job history cleanup complete: ' . $deletedJobs . ' job(s) deleted, '
            . $skipped . ' skipped, ' . number_format($deletedWorkflowUnits) . ' hidden workflow row(s) drained, '
            . $deletedStagedFiles . ' retained staged file(s) removed.'
            . ($limited ? ' The 10,000-job snapshot limit was reached; run cleanup again for the remainder.' : '');
        $context->checkpoint($progress);

        return $this->result(
            $targetQueue,
            $requested,
            $deletedJobs,
            $deletedWorkflowUnits,
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

    /** @return list<int> */
    private function stackIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = array_values(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        ));
        return array_slice($ids, -self::MAX_STACK_DEPTH);
    }

    private function rootPercent(int $offset, int $total, bool $completedBoundary): int
    {
        if ($total < 1) {
            return 100;
        }
        $numerator = $completedBoundary ? $offset : min($total, $offset + 1);
        $percent = (int)floor(($numerator * 100) / $total);
        return max($offset < $total ? 1 : 100, min(100, $percent));
    }

    /**
     * @param list<int> $stack
     * @return array<string,mixed>
     */
    private function state(
        string $stage,
        int $offset,
        int $total,
        int $requested,
        int $deletedJobs,
        int $deletedWorkflowUnits,
        int $deletedStagedFiles,
        int $deletedStagedBytes,
        int $skipped,
        bool $limited,
        int $rootId,
        array $stack
    ): array {
        return [
            'stage' => $stage,
            'snapshot_offset' => $offset,
            'snapshot_total' => $total,
            'requested' => $requested,
            'deleted_jobs' => $deletedJobs,
            'deleted_workflow_units' => $deletedWorkflowUnits,
            'deleted_staged_files' => $deletedStagedFiles,
            'deleted_staged_bytes' => $deletedStagedBytes,
            'skipped' => $skipped,
            'limited' => $limited,
            'cleanup_root_id' => $rootId,
            'cleanup_stack' => $stack,
        ];
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
        int $deletedWorkflowUnits,
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
            'deleted_workflow_units' => $deletedWorkflowUnits,
            'deleted_staged_files' => $deletedStagedFiles,
            'deleted_staged_bytes' => $deletedStagedBytes,
            'skipped' => $skipped,
            'limited' => $limited,
            'message' => 'Removed ' . $deletedJobs . ' terminal background job(s), drained '
                . number_format($deletedWorkflowUnits) . ' hidden workflow row(s), and removed '
                . $deletedStagedFiles . ' retained staged file(s).',
        ];
    }
}
