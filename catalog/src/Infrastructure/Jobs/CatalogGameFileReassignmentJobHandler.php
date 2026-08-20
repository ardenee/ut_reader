<?php
/**
 * Durable verified-file reassignment workflow.
 *
 * The coordinator snapshots/accepts the selected source set, plans deterministic
 * bounded child batches and then waits without holding a worker slot. Each child
 * checkpoints after every file and records ordinary file failures instead of
 * aborting later files in the batch.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Games\CatalogVerifiedFileReassignmentService;
use UnrealDb\Catalog\Infrastructure\Games\PdoGameFileReassignmentSelectionQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;

final class CatalogGameFileReassignmentJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 1;
    private const PLAN_ID_WINDOW = 5000;
    private const CHILD_ID_SPAN = 100;
    private const FAILURE_SAMPLE_LIMIT = 50;
    private const AGGREGATE_FAILURE_SAMPLE_LIMIT = 100;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array(
            $jobType,
            [JobType::GAME_FILE_REASSIGN, JobType::GAME_FILE_REASSIGN_BATCH],
            true
        );
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return $job->type === JobType::GAME_FILE_REASSIGN_BATCH
            ? $this->runBatch($job, $context)
            : $this->runCoordinator($job, $context);
    }

    /** @return array<string,mixed> */
    private function runCoordinator(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $this->commonPayload($job->payload);
        $scope = strtolower(trim((string)($job->payload['scope'] ?? 'selected')));
        if (!in_array($scope, ['selected', 'matching'], true)) {
            throw new \RuntimeException('Game file reassignment scope must be selected or matching.');
        }

        $resume = $context->resumeProgress();
        if ((int)($resume['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION) {
            $resume = [];
        }
        $stage = trim((string)($resume['stage'] ?? '')) ?: 'plan';
        $requestedTotal = $scope === 'selected'
            ? count((array)($job->payload['file_ids'] ?? []))
            : max(0, (int)($job->payload['snapshot_total'] ?? 0));

        if ($requestedTotal < 1) {
            return [
                'operation' => 'game_file_reassign',
                'status' => 'empty',
                'source_game_id' => $payload['source_game_id'],
                'target_game_id' => $payload['target_game_id'],
                'requested_files' => 0,
                'processed_files' => 0,
                'succeeded_files' => 0,
                'failed_files' => 0,
                'skipped_files' => 0,
                'message' => 'No verified files remained to move.',
            ];
        }

        if ($stage === 'plan') {
            if ($scope === 'selected') {
                $ids = array_values(array_unique(array_filter(
                    array_map('intval', (array)($job->payload['file_ids'] ?? [])),
                    static fn(int $id): bool => $id > 0
                )));
                $units = $this->unitsForIds($ids, $job, $payload);
                if ($units !== []) {
                    (new PdoJobQueue($this->db))->enqueueWorkflowUnits(
                        $job->queue,
                        JobType::GAME_FILE_REASSIGN_BATCH,
                        $units,
                        15,
                        null,
                        $payload['requested_by'] > 0 ? $payload['requested_by'] : null,
                        3,
                        $job->id
                    );
                }
                $context->checkpoint($this->progress(
                    'wait',
                    20,
                    'Selected file batches are planned; waiting for reassignment work.',
                    ['requested_total' => $requestedTotal]
                ));
                $stage = 'wait';
            } else {
                $snapshotMaxId = max(0, (int)($job->payload['snapshot_max_id'] ?? 0));
                $filters = is_array($job->payload['filters'] ?? null) ? $job->payload['filters'] : [];
                $cursor = max(0, (int)($resume['cursor_id'] ?? 0));
                if ($snapshotMaxId > 0 && $cursor < $snapshotMaxId) {
                    $windowEnd = min($snapshotMaxId, $cursor + self::PLAN_ID_WINDOW);
                    $ids = (new PdoGameFileReassignmentSelectionQuery($this->db))->page(
                        $payload['source_game_id'],
                        $filters,
                        $cursor,
                        $windowEnd,
                        self::PLAN_ID_WINDOW
                    );
                    $units = $this->unitsForIds($ids, $job, $payload);
                    if ($units !== []) {
                        (new PdoJobQueue($this->db))->enqueueWorkflowUnits(
                            $job->queue,
                            JobType::GAME_FILE_REASSIGN_BATCH,
                            $units,
                            15,
                            null,
                            $payload['requested_by'] > 0 ? $payload['requested_by'] : null,
                            3,
                            $job->id
                        );
                    }
                    $planPercent = min(20, (int)floor(($windowEnd * 20) / max(1, $snapshotMaxId)));
                    $context->defer(1, $this->progress(
                        'plan',
                        $planPercent,
                        'Planning matching verified files through file #' . number_format($windowEnd)
                            . ' of snapshot maximum #' . number_format($snapshotMaxId) . '.',
                        [
                            'cursor_id' => $windowEnd,
                            'requested_total' => $requestedTotal,
                            'snapshot_max_id' => $snapshotMaxId,
                        ]
                    ));
                }
                $context->checkpoint($this->progress(
                    'wait',
                    20,
                    'All matching file batches are planned; waiting for reassignment work.',
                    [
                        'cursor_id' => $snapshotMaxId,
                        'requested_total' => $requestedTotal,
                        'snapshot_max_id' => $snapshotMaxId,
                    ]
                ));
                $stage = 'wait';
            }
        }

        if ($stage !== 'wait') {
            throw new \RuntimeException('Unknown game-file reassignment workflow stage: ' . $stage);
        }

        $children = (new PdoWorkflowChildStateQuery($this->db))->fetch($job->id, 'game-file-reassign:batch:');
        $aggregate = $this->aggregateChildren($job->id);
        $finished = $aggregate['processed_files'];
        $percent = 20 + (int)floor(78 * min($requestedTotal, $finished) / max(1, $requestedTotal));
        if (($children['queued'] + $children['running']) > 0) {
            $context->defer(2, $this->progress(
                'wait',
                min(98, $percent),
                'File reassignment: ' . number_format($finished) . '/' . number_format($requestedTotal)
                    . ' finished; ' . number_format($children['running']) . ' batch(es) running, '
                    . number_format($children['queued']) . ' queued.',
                [
                    'requested_total' => $requestedTotal,
                    'children' => $children,
                    'aggregate' => $aggregate,
                ]
            ));
        }

        $problemBatches = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
        $notPlanned = max(0, $requestedTotal - $aggregate['processed_files']);
        $destination = $payload['target_game_id'] === 0
            ? 'Unverified Files'
            : ('game #' . $payload['target_game_id']);
        $message = 'File reassignment to ' . $destination . ' complete: '
            . number_format($aggregate['succeeded_files']) . ' moved, '
            . number_format($aggregate['failed_files']) . ' failed, '
            . number_format($aggregate['skipped_files']) . ' skipped.';
        if ($notPlanned > 0) {
            $message .= ' ' . number_format($notPlanned)
                . ' snapshot file(s) changed before a completed batch reported them.';
        }
        if ($problemBatches > 0) {
            $message .= ' ' . number_format($problemBatches) . ' batch job(s) ended abnormally.';
        }

        $context->checkpoint($this->progress('complete', 100, $message, [
            'requested_total' => $requestedTotal,
            'children' => $children,
            'aggregate' => $aggregate,
            'problem_batches' => $problemBatches,
            'not_planned' => $notPlanned,
        ]));

        return [
            'operation' => 'game_file_reassign',
            'workflow_version' => self::WORKFLOW_VERSION,
            'source_game_id' => $payload['source_game_id'],
            'target_game_id' => $payload['target_game_id'],
            'requested_files' => $requestedTotal,
            'processed_files' => $aggregate['processed_files'],
            'succeeded_files' => $aggregate['succeeded_files'],
            'failed_files' => $aggregate['failed_files'],
            'skipped_files' => $aggregate['skipped_files'],
            'problem_batches' => $problemBatches,
            'not_planned' => $notPlanned,
            'failure_samples' => $aggregate['failure_samples'],
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function runBatch(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $this->commonPayload($job->payload);
        $items = array_values(array_filter(
            array_map('intval', (array)($job->payload['file_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        ));
        if ($items === [] || count($items) > self::CHILD_ID_SPAN) {
            throw new \RuntimeException('Game file reassignment batch is empty or exceeds 100 files.');
        }

        $resume = $context->resumeProgress();
        $done = max(0, min(count($items), (int)($resume['done'] ?? 0)));
        $succeeded = max(0, (int)($resume['succeeded_files'] ?? 0));
        $failed = max(0, (int)($resume['failed_files'] ?? 0));
        $skipped = max(0, (int)($resume['skipped_files'] ?? 0));
        $failureSamples = is_array($resume['failure_samples'] ?? null)
            ? array_slice(array_values($resume['failure_samples']), 0, self::FAILURE_SAMPLE_LIMIT)
            : [];
        $service = new CatalogVerifiedFileReassignmentService($this->db, $this->config);
        $total = count($items);

        for ($index = $done; $index < $total; $index++) {
            $fileId = (int)$items[$index];
            $name = 'file #' . $fileId;
            try {
                $progress = function (array $state) use (
                    $context,
                    $index,
                    $total,
                    $fileId,
                    &$succeeded,
                    &$failed,
                    &$skipped,
                    &$failureSamples
                ): void {
                    $filePercent = max(0, min(100, (int)($state['percent'] ?? 0)));
                    $overall = (int)floor((($index + ($filePercent / 100)) * 100) / max(1, $total));
                    $context->heartbeatIfDue([
                        'workflow_version' => self::WORKFLOW_VERSION,
                        'stage' => 'batch_file',
                        'done' => $index,
                        'total' => $total,
                        'percent' => $overall,
                        'current_file_id' => $fileId,
                        'file_percent' => $filePercent,
                        'message' => trim((string)($state['message'] ?? '')) ?: ('Moving file #' . $fileId),
                        'succeeded_files' => $succeeded,
                        'failed_files' => $failed,
                        'skipped_files' => $skipped,
                        'failure_samples' => $failureSamples,
                    ]);
                };
                $result = $service->move(
                    $fileId,
                    $payload['target_game_id'],
                    $payload['requested_by'] > 0 ? $payload['requested_by'] : null,
                    $progress
                );
                $name = trim((string)($result['original_name'] ?? '')) ?: $name;
                if (strtolower((string)($result['status'] ?? '')) === 'skipped') {
                    $skipped++;
                } else {
                    $succeeded++;
                }
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (Throwable $error) {
                $failed++;
                $this->rememberFailure(
                    $failureSamples,
                    $name,
                    trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error)
                );
            }

            $done = $index + 1;
            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'batch',
                'done' => $done,
                'total' => $total,
                'percent' => (int)floor($done * 100 / max(1, $total)),
                'current_file_id' => $fileId,
                'message' => 'Processed ' . number_format($done) . '/' . number_format($total)
                    . ' file(s) in this reassignment batch.',
                'succeeded_files' => $succeeded,
                'failed_files' => $failed,
                'skipped_files' => $skipped,
                'failure_samples' => $failureSamples,
            ]);
        }

        return [
            'operation' => 'game_file_reassign_batch',
            'source_game_id' => $payload['source_game_id'],
            'target_game_id' => $payload['target_game_id'],
            'requested_files' => $total,
            'processed_files' => $done,
            'succeeded_files' => $succeeded,
            'failed_files' => $failed,
            'skipped_files' => $skipped,
            'failure_samples' => $failureSamples,
        ];
    }

    /**
     * @param list<int> $ids
     * @param array{source_game_id:int,target_game_id:int,requested_by:int} $payload
     * @return list<array{payload:array<string,mixed>,workflow_unit_key:string}>
     */
    private function unitsForIds(array $ids, ClaimedJob $job, array $payload): array
    {
        $buckets = [];
        foreach ($ids as $id) {
            $id = max(1, (int)$id);
            $start = intdiv($id - 1, self::CHILD_ID_SPAN) * self::CHILD_ID_SPAN + 1;
            $buckets[$start][] = $id;
        }
        ksort($buckets, SORT_NUMERIC);

        $units = [];
        foreach ($buckets as $start => $fileIds) {
            $end = $start + self::CHILD_ID_SPAN - 1;
            sort($fileIds, SORT_NUMERIC);
            $units[] = [
                'workflow_unit_key' => 'game-file-reassign:batch:' . $start . '-' . $end,
                'payload' => [
                    'source_game_id' => $payload['source_game_id'],
                    'target_game_id' => $payload['target_game_id'],
                    'requested_by' => $payload['requested_by'],
                    'file_ids' => array_values($fileIds),
                    'batch_start_id' => $start,
                    'batch_end_id' => $end,
                    'workflow_parent_job_id' => $job->id,
                ],
            ];
        }
        return $units;
    }

    /** @param array<string,mixed> $payload @return array{source_game_id:int,target_game_id:int,requested_by:int} */
    private function commonPayload(array $payload): array
    {
        $sourceGameId = max(0, (int)($payload['source_game_id'] ?? 0));
        $targetGameId = (int)($payload['target_game_id'] ?? -1);
        if ($sourceGameId < 1) {
            throw new \RuntimeException('Game file reassignment requires a source game.');
        }
        if ($targetGameId < 0 || $targetGameId === $sourceGameId) {
            throw new \RuntimeException('Game file reassignment requires Unverified Files or a different target game.');
        }
        return [
            'source_game_id' => $sourceGameId,
            'target_game_id' => $targetGameId,
            'requested_by' => max(0, (int)($payload['requested_by'] ?? 0)),
        ];
    }

    /** @return array{processed_files:int,succeeded_files:int,failed_files:int,skipped_files:int,failure_samples:list<array<string,string>>} */
    private function aggregateChildren(int $parentJobId): array
    {
        $state = [
            'processed_files' => 0,
            'succeeded_files' => 0,
            'failed_files' => 0,
            'skipped_files' => 0,
            'failure_samples' => [],
        ];
        $statement = $this->db->prepare(
            'SELECT status,result_json,last_error,workflow_unit_key FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key LIKE "game-file-reassign:batch:%"'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ((string)($row['status'] ?? '') !== 'completed') {
                $status = strtolower((string)($row['status'] ?? ''));
                if (in_array($status, ['failed', 'dead_letter', 'cancelled'], true)) {
                    $message = trim((string)($row['last_error'] ?? '')) ?: ('Batch ' . $status);
                    $this->rememberFailure(
                        $state['failure_samples'],
                        (string)($row['workflow_unit_key'] ?? 'batch'),
                        $message,
                        self::AGGREGATE_FAILURE_SAMPLE_LIMIT
                    );
                }
                continue;
            }
            $decoded = json_decode((string)($row['result_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $state['processed_files'] += max(0, (int)($decoded['processed_files'] ?? 0));
            $state['succeeded_files'] += max(0, (int)($decoded['succeeded_files'] ?? 0));
            $state['failed_files'] += max(0, (int)($decoded['failed_files'] ?? 0));
            $state['skipped_files'] += max(0, (int)($decoded['skipped_files'] ?? 0));
            foreach ((array)($decoded['failure_samples'] ?? []) as $sample) {
                if (!is_array($sample) || count($state['failure_samples']) >= self::AGGREGATE_FAILURE_SAMPLE_LIMIT) {
                    break;
                }
                $state['failure_samples'][] = [
                    'file' => (string)($sample['file'] ?? 'file'),
                    'error' => (string)($sample['error'] ?? 'Unknown failure'),
                ];
            }
        }
        return $state;
    }

    /** @param list<array<string,string>> $samples */
    private function rememberFailure(
        array &$samples,
        string $file,
        string $error,
        int $limit = self::FAILURE_SAMPLE_LIMIT
    ): void {
        if (count($samples) >= $limit) {
            return;
        }
        $samples[] = [
            'file' => mb_substr($file, 0, 255, 'UTF-8'),
            'error' => mb_substr($error, 0, 1000, 'UTF-8'),
        ];
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(string $stage, int $percent, string $message, array $extra = []): array
    {
        return $extra + [
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ];
    }
}
