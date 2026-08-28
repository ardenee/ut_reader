<?php
/**
 * Durable bulk cleanup for terminal background-job history.
 *
 * Workflow descendants are drained leaf-first in bounded batches. Every deleted
 * row still passes through direct staged-source cleanup. One claim can consume a
 * full 10,000-root snapshot while periodic heartbeats keep cancellation responsive;
 * retention then continues under the original fixed cutoff until no eligible
 * history remains.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogBackgroundJobHistoryCleanupJobHandler implements JobHandler
{
    private const BATCH_SIZE = 10000;
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

        $retentionCutoff = trim((string)($job->payload['retention_cutoff'] ?? ''));
        $autoContinue = $retentionCutoff !== '' && !empty($job->payload['retention_auto_continue']);
        if ($retentionCutoff !== '' && !$this->validCutoff($retentionCutoff)) {
            throw new \InvalidArgumentException('Background-job retention cutoff is invalid.');
        }

        $resume = $context->resumeProgress();
        $ids = $this->snapshotIds(
            is_array($resume['snapshot_ids'] ?? null)
                ? $resume['snapshot_ids']
                : ($job->payload['job_ids'] ?? [])
        );
        $snapshotBatch = max(1, (int)($resume['snapshot_batch'] ?? 1));
        $requested = max(
            count($ids),
            (int)($job->payload['requested'] ?? count($ids)),
            (int)($resume['requested'] ?? 0)
        );
        $limited = array_key_exists('limited', $resume)
            ? !empty($resume['limited'])
            : !empty($job->payload['limited']);

        $offset = max(0, min(count($ids), (int)($resume['snapshot_offset'] ?? 0)));
        $deletedJobs = max(0, (int)($resume['deleted_jobs'] ?? 0));
        $deletedWorkflowUnits = max(0, (int)($resume['deleted_workflow_units'] ?? 0));
        $deletedStagedFiles = max(0, (int)($resume['deleted_staged_files'] ?? 0));
        $deletedStagedBytes = max(0, (int)($resume['deleted_staged_bytes'] ?? 0));
        $skipped = max(0, (int)($resume['skipped'] ?? 0));
        $activeRootId = max(0, (int)($resume['cleanup_root_id'] ?? 0));
        $stack = $this->stackIds($resume['cleanup_stack'] ?? []);

        if ($ids === []) {
            $progress = $this->progress(100, 'Background-job history cleanup contains no eligible terminal jobs.', $this->state(
                'complete',
                0,
                0,
                $requested,
                $deletedJobs,
                $deletedWorkflowUnits,
                $deletedStagedFiles,
                $deletedStagedBytes,
                $skipped,
                false,
                0,
                [],
                [],
                $snapshotBatch,
                $retentionCutoff
            ));
            $context->checkpoint($progress);
            return $this->result(
                $targetQueue,
                $requested,
                $deletedJobs,
                $deletedWorkflowUnits,
                $deletedStagedFiles,
                $deletedStagedBytes,
                $skipped,
                false,
                $snapshotBatch,
                $retentionCutoff
            );
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
            if ($currentId < 1) {
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
                $batch = $cleanup->deleteWorkflowJobs($children['leaf_ids']);
                $batchDeleted = max(0, (int)($batch['deleted_jobs'] ?? 0));
                $deletedWorkflowUnits += $batchDeleted;
                $deletedStagedFiles += max(0, (int)($batch['deleted_staged_files'] ?? 0));
                $deletedStagedBytes += max(0, (int)($batch['deleted_staged_bytes'] ?? 0));
                $workflowRowsThisClaim += $batchDeleted;

                $progress = $this->progress(
                    $this->percent($deletedJobs + $skipped, $requested, false),
                    'Draining workflow history under job #' . $rootId . ': '
                        . number_format($deletedWorkflowUnits) . ' child row(s) removed; '
                        . $deletedStagedFiles . ' staged source(s), ' . $this->formatBytes($deletedStagedBytes) . ' reclaimed.',
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
                        $stack,
                        $ids,
                        $snapshotBatch,
                        $retentionCutoff
                    )
                );
                $context->heartbeatIfDue($progress);
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

            if ($currentId !== $rootId) {
                $batch = $cleanup->deleteWorkflowJobs([$currentId]);
                $batchDeleted = max(0, (int)($batch['deleted_jobs'] ?? 0));
                $deletedWorkflowUnits += $batchDeleted;
                $deletedStagedFiles += max(0, (int)($batch['deleted_staged_files'] ?? 0));
                $deletedStagedBytes += max(0, (int)($batch['deleted_staged_bytes'] ?? 0));
                $workflowRowsThisClaim += $batchDeleted;
                array_pop($stack);

                if ($workflowRowsThisClaim >= self::MAX_WORKFLOW_ROWS_PER_CLAIM) {
                    $progress = $this->progress(
                        $this->percent($deletedJobs + $skipped, $requested, false),
                        'Draining workflow history under job #' . $rootId . ': '
                            . number_format($deletedWorkflowUnits) . ' child row(s) removed; '
                            . $this->formatBytes($deletedStagedBytes) . ' staged bytes reclaimed.',
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
                            $stack,
                            $ids,
                            $snapshotBatch,
                            $retentionCutoff
                        )
                    );
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

            $context->heartbeatIfDue($this->progress(
                $this->percent($deletedJobs + $skipped, $requested, false),
                'Background-job history cleanup: batch ' . $snapshotBatch . ', '
                    . $offset . '/' . count($ids) . ' root job(s); '
                    . $deletedJobs . ' roots and ' . number_format($deletedWorkflowUnits) . ' child row(s) deleted; '
                    . $deletedStagedFiles . ' staged source(s), ' . $this->formatBytes($deletedStagedBytes) . ' reclaimed.',
                $this->state(
                    'cleanup_batch',
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
                    [],
                    $ids,
                    $snapshotBatch,
                    $retentionCutoff
                )
            ));
        }

        $progress = $this->progress(
            $this->percent($deletedJobs + $skipped, $requested, false),
            'Background-job history cleanup: batch ' . $snapshotBatch . ', '
                . $offset . '/' . count($ids) . ' root job(s) processed; '
                . $deletedJobs . ' roots and ' . number_format($deletedWorkflowUnits) . ' child row(s) deleted; '
                . $this->formatBytes($deletedStagedBytes) . ' staged bytes reclaimed.',
            $this->state(
                'cleanup_batch',
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
                $stack,
                $ids,
                $snapshotBatch,
                $retentionCutoff
            )
        );

        if ($offset < count($ids)) {
            $context->defer(1, $progress);
        }

        if ($autoContinue) {
            $next = (new CatalogBackgroundJobHistoryCleanupQueue($this->db, $this->config))
                ->snapshotBefore($targetQueue, $retentionCutoff);
            if ($next['ids'] !== []) {
                $processedRoots = $deletedJobs + $skipped;
                $requested = max($requested, $processedRoots + (int)$next['requested']);
                $snapshotBatch++;
                $limited = !empty($next['limited']);
                $progress = $this->progress(
                    $this->percent($processedRoots, $requested, false),
                    'Continuing background-job retention cleanup with batch ' . $snapshotBatch
                        . ' (' . count($next['ids']) . ' root job(s)); '
                        . $this->formatBytes($deletedStagedBytes) . ' reclaimed so far.',
                    $this->state(
                        'next_snapshot',
                        0,
                        count($next['ids']),
                        $requested,
                        $deletedJobs,
                        $deletedWorkflowUnits,
                        $deletedStagedFiles,
                        $deletedStagedBytes,
                        $skipped,
                        $limited,
                        0,
                        [],
                        $next['ids'],
                        $snapshotBatch,
                        $retentionCutoff
                    )
                );
                $context->checkpoint($progress);
                $context->defer(1, $progress);
            }
            $limited = false;
        }

        $progress = $this->progress(100, 'Background-job history cleanup complete: '
            . $deletedJobs . ' root job(s) deleted, '
            . number_format($deletedWorkflowUnits) . ' child workflow row(s) deleted, '
            . $skipped . ' skipped, '
            . $deletedStagedFiles . ' staged source(s) removed, '
            . $this->formatBytes($deletedStagedBytes) . ' reclaimed.'
            . (!$autoContinue && $limited ? ' The immutable 10,000-job snapshot limit was reached.' : ''),
            $this->state(
                'complete',
                count($ids),
                count($ids),
                $requested,
                $deletedJobs,
                $deletedWorkflowUnits,
                $deletedStagedFiles,
                $deletedStagedBytes,
                $skipped,
                $limited,
                0,
                [],
                [],
                $snapshotBatch,
                $retentionCutoff
            )
        );
        $context->checkpoint($progress);

        return $this->result(
            $targetQueue,
            $requested,
            $deletedJobs,
            $deletedWorkflowUnits,
            $deletedStagedFiles,
            $deletedStagedBytes,
            $skipped,
            $limited,
            $snapshotBatch,
            $retentionCutoff
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

    private function percent(int $processedRoots, int $requested, bool $complete): int
    {
        if ($complete) {
            return 100;
        }
        if ($requested < 1) {
            return 1;
        }
        return max(1, min(99, (int)floor((max(0, $processedRoots) * 100) / $requested)));
    }

    private function validCutoff(string $cutoff): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cutoff, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d H:i:s') === $cutoff;
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? number_format($value, 0) : number_format($value, 2)) . ' ' . $units[$unit];
    }

    /**
     * @param list<int> $stack
     * @param list<int> $snapshotIds
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
        array $stack,
        array $snapshotIds,
        int $snapshotBatch,
        string $retentionCutoff
    ): array {
        return [
            'stage' => $stage,
            'snapshot_offset' => $offset,
            'snapshot_total' => $total,
            'snapshot_ids' => $snapshotIds,
            'snapshot_batch' => $snapshotBatch,
            'requested' => $requested,
            'deleted_jobs' => $deletedJobs,
            'deleted_workflow_units' => $deletedWorkflowUnits,
            'deleted_staged_files' => $deletedStagedFiles,
            'deleted_staged_bytes' => $deletedStagedBytes,
            'skipped' => $skipped,
            'limited' => $limited,
            'cleanup_root_id' => $rootId,
            'cleanup_stack' => $stack,
            'retention_cutoff' => $retentionCutoff,
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
        bool $limited,
        int $snapshotBatch,
        string $retentionCutoff
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
            'snapshot_batches' => $snapshotBatch,
            'retention_cutoff' => $retentionCutoff,
            'message' => 'Removed ' . $deletedJobs . ' root job(s) and '
                . number_format($deletedWorkflowUnits) . ' child workflow row(s); removed '
                . $deletedStagedFiles . ' staged source(s), reclaiming '
                . $this->formatBytes($deletedStagedBytes) . '.',
        ];
    }
}
