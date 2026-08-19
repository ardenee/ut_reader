<?php
/**
 * Durable all-matching actions for the Unverified Files page.
 *
 * The parent snapshots the current filtered id range, plans bounded child batches,
 * then waits without occupying a worker slot. Children checkpoint after each file,
 * continue past ordinary per-file failures and reuse the exact same single-file
 * application services as the browser-selected path.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Application\Unverified\CatalogUnverifiedActionService;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedActionSourceResolver;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedImporterAdapter;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueMutationService;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedBulkSelectionQuery;

final class CatalogUnverifiedBulkActionJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 1;
    private const PLAN_PAGE_SIZE = 1000;
    private const CHILD_BATCH_SIZE = 100;
    private const FAILURE_SAMPLE_LIMIT = 50;

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
            [JobType::UNVERIFIED_BULK_ACTION, JobType::UNVERIFIED_BULK_ACTION_BATCH],
            true
        );
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return $job->type === JobType::UNVERIFIED_BULK_ACTION_BATCH
            ? $this->runBatch($job, $context)
            : $this->runCoordinator($job, $context);
    }

    /** @return array<string,mixed> */
    private function runCoordinator(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $this->validatedCommonPayload($job->payload);
        $filters = is_array($job->payload['filters'] ?? null) ? $job->payload['filters'] : [];
        $snapshotMaxId = max(0, (int)($job->payload['snapshot_max_id'] ?? 0));
        $snapshotTotal = max(0, (int)($job->payload['snapshot_total'] ?? 0));
        if ($snapshotMaxId < 1 || $snapshotTotal < 1) {
            return [
                'operation' => 'unverified_bulk_action',
                'action' => $payload['action'],
                'matched_files' => 0,
                'processed_files' => 0,
                'succeeded_files' => 0,
                'failed_files' => 0,
                'skipped_files' => 0,
                'message' => 'No matching unverified files remained to process.',
            ];
        }

        $resume = $context->resumeProgress();
        if ((int)($resume['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION) {
            $resume = [];
        }
        $stage = trim((string)($resume['stage'] ?? '')) ?: 'plan';

        if ($stage === 'plan') {
            $selector = new PdoUnverifiedBulkSelectionQuery($this->db);
            $cursor = max(0, (int)($resume['cursor_id'] ?? 0));
            $planned = max(0, (int)($resume['planned_files'] ?? 0));
            $batchNumber = max(0, (int)($resume['planned_batches'] ?? 0));
            $rows = $selector->page($filters, $cursor, $snapshotMaxId, self::PLAN_PAGE_SIZE);

            if ($rows !== []) {
                $units = [];
                foreach (array_chunk($rows, self::CHILD_BATCH_SIZE) as $chunk) {
                    if ($chunk === []) {
                        continue;
                    }
                    $batchNumber++;
                    $firstId = (int)$chunk[0]['id'];
                    $lastId = (int)$chunk[count($chunk) - 1]['id'];
                    $units[] = [
                        'workflow_unit_key' => 'unverified:batch:' . $batchNumber . ':' . $firstId . '-' . $lastId,
                        'payload' => [
                            'action' => $payload['action'],
                            'target_game_id' => $payload['target_game_id'],
                            'allow_profile_override' => $payload['allow_profile_override'],
                            'requested_by' => $payload['requested_by'],
                            'items' => $chunk,
                            'batch_number' => $batchNumber,
                            'workflow_parent_job_id' => $job->id,
                        ],
                    ];
                }
                (new PdoJobQueue($this->db))->enqueueWorkflowUnits(
                    $job->queue,
                    JobType::UNVERIFIED_BULK_ACTION_BATCH,
                    $units,
                    20,
                    null,
                    $payload['requested_by'] > 0 ? $payload['requested_by'] : null,
                    3,
                    $job->id
                );
                $cursor = (int)$rows[count($rows) - 1]['id'];
                $planned += count($rows);
                $progress = $this->progress(
                    'plan',
                    min(20, (int)floor(($planned * 20) / max(1, $snapshotTotal))),
                    'Planned ' . number_format($planned) . '/' . number_format($snapshotTotal)
                        . ' matching unverified file(s) into ' . number_format($batchNumber) . ' durable batch(es).',
                    [
                        'cursor_id' => $cursor,
                        'planned_files' => $planned,
                        'planned_batches' => $batchNumber,
                        'snapshot_total' => $snapshotTotal,
                    ]
                );
                $context->defer(1, $progress);
            }

            $resume = $this->progress(
                'wait',
                20,
                'Bulk selection planning is complete; waiting for durable file batches.',
                [
                    'cursor_id' => $snapshotMaxId,
                    'planned_files' => $planned,
                    'planned_batches' => $batchNumber,
                    'snapshot_total' => $snapshotTotal,
                ]
            );
            $context->checkpoint($resume);
            $stage = 'wait';
        }

        if ($stage !== 'wait') {
            throw new \RuntimeException('Unknown unverified bulk workflow stage: ' . $stage);
        }

        $children = (new PdoWorkflowChildStateQuery($this->db))->fetch($job->id, 'unverified:batch:');
        $aggregate = $this->aggregateChildren($job->id);
        $finished = $aggregate['processed_files'];
        $percent = 20 + (int)floor((78 * $finished) / max(1, $snapshotTotal));

        if (($children['queued'] + $children['running']) > 0) {
            $context->defer(2, $this->progress(
                'wait',
                min(98, $percent),
                ucfirst($payload['action']) . ' all matching: ' . number_format($finished) . '/'
                    . number_format($snapshotTotal) . ' file(s) finished; '
                    . number_format($children['running']) . ' batch(es) running, '
                    . number_format($children['queued']) . ' queued.',
                [
                    'snapshot_total' => $snapshotTotal,
                    'children' => $children,
                    'aggregate' => $aggregate,
                ]
            ));
        }

        $problemBatches = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
        $message = ucfirst($payload['action']) . ' all matching complete: '
            . number_format($aggregate['succeeded_files']) . ' succeeded, '
            . number_format($aggregate['failed_files']) . ' failed, '
            . number_format($aggregate['skipped_files']) . ' skipped.';
        if ($problemBatches > 0) {
            $message .= ' ' . number_format($problemBatches)
                . ' batch job(s) ended before returning a normal per-file result; completed batches were retained.';
        }

        $context->checkpoint($this->progress('complete', 100, $message, [
            'snapshot_total' => $snapshotTotal,
            'children' => $children,
            'aggregate' => $aggregate,
            'problem_batches' => $problemBatches,
        ]));

        return [
            'operation' => 'unverified_bulk_action',
            'workflow_version' => self::WORKFLOW_VERSION,
            'action' => $payload['action'],
            'target_game_id' => $payload['target_game_id'],
            'matched_files' => $snapshotTotal,
            'processed_files' => $aggregate['processed_files'],
            'succeeded_files' => $aggregate['succeeded_files'],
            'failed_files' => $aggregate['failed_files'],
            'skipped_files' => $aggregate['skipped_files'],
            'problem_batches' => $problemBatches,
            'children' => $children,
            'failure_samples' => $aggregate['failure_samples'],
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function runBatch(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $this->validatedCommonPayload($job->payload);
        $items = is_array($job->payload['items'] ?? null) ? array_values($job->payload['items']) : [];
        if ($items === [] || count($items) > self::CHILD_BATCH_SIZE) {
            throw new \RuntimeException('Unverified bulk batch payload is empty or exceeds its bounded size.');
        }

        $resume = $context->resumeProgress();
        $done = max(0, min(count($items), (int)($resume['done'] ?? 0)));
        $succeeded = max(0, (int)($resume['succeeded_files'] ?? 0));
        $failed = max(0, (int)($resume['failed_files'] ?? 0));
        $skipped = max(0, (int)($resume['skipped_files'] ?? 0));
        $failureSamples = is_array($resume['failure_samples'] ?? null)
            ? array_slice(array_values($resume['failure_samples']), 0, self::FAILURE_SAMPLE_LIMIT)
            : [];

        $resolver = new CatalogUnverifiedActionSourceResolver($this->db, $this->config);
        $service = new CatalogUnverifiedActionService(
            new CatalogUnverifiedQueueMutationService($this->db, $this->config),
            new CatalogUnverifiedImporterAdapter($this->db, $this->config)
        );
        $total = count($items);

        for ($index = $done; $index < $total; $index++) {
            $item = is_array($items[$index]) ? $items[$index] : [];
            $token = trim((string)($item['token'] ?? ''));
            $name = trim((string)($item['original_name'] ?? '')) ?: ('file #' . (int)($item['id'] ?? 0));
            if ($token === '') {
                $failed++;
                $this->rememberFailure($failureSamples, $name, 'Missing durable queue token.');
            } else {
                try {
                    $source = $resolver->resolve($token);
                    $emit = function (string $stage, int $filePercent, string $message) use (
                        $context,
                        $index,
                        $total,
                        $name,
                        $succeeded,
                        $failed,
                        $skipped,
                        $failureSamples
                    ): void {
                        $overall = (int)floor((($index + (max(0, min(100, $filePercent)) / 100)) * 100) / max(1, $total));
                        $context->heartbeatIfDue([
                            'workflow_version' => self::WORKFLOW_VERSION,
                            'stage' => 'batch_file',
                            'done' => $index,
                            'total' => $total,
                            'percent' => $overall,
                            'current_file' => $name,
                            'file_percent' => max(0, min(100, $filePercent)),
                            'message' => $message,
                            'succeeded_files' => $succeeded,
                            'failed_files' => $failed,
                            'skipped_files' => $skipped,
                            'failure_samples' => $failureSamples,
                        ]);
                    };
                    $service->execute(
                        $payload['action'],
                        $source,
                        $payload['target_game_id'],
                        $payload['requested_by'] > 0 ? $payload['requested_by'] : null,
                        $payload['allow_profile_override'],
                        $emit
                    );
                    $succeeded++;
                } catch (JobCancellationRequested $error) {
                    throw $error;
                } catch (Throwable $error) {
                    $message = trim($error->getMessage());
                    if ($this->isAlreadyGone($message)) {
                        $skipped++;
                    } else {
                        $failed++;
                        $this->rememberFailure($failureSamples, $name, $message !== '' ? $message : get_class($error));
                    }
                }
            }

            $done = $index + 1;
            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'batch',
                'done' => $done,
                'total' => $total,
                'percent' => (int)floor(($done * 100) / max(1, $total)),
                'current_file' => $name,
                'message' => 'Processed ' . number_format($done) . '/' . number_format($total)
                    . ' file(s) in this bulk batch.',
                'succeeded_files' => $succeeded,
                'failed_files' => $failed,
                'skipped_files' => $skipped,
                'failure_samples' => $failureSamples,
            ]);
        }

        return [
            'operation' => 'unverified_bulk_action_batch',
            'action' => $payload['action'],
            'requested_files' => $total,
            'processed_files' => $done,
            'succeeded_files' => $succeeded,
            'failed_files' => $failed,
            'skipped_files' => $skipped,
            'failure_samples' => $failureSamples,
        ];
    }

    /** @param array<string,mixed> $payload @return array{action:string,target_game_id:int,allow_profile_override:bool,requested_by:int} */
    private function validatedCommonPayload(array $payload): array
    {
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        if (!in_array($action, ['import', 'move', 'delete'], true)) {
            throw new \RuntimeException('Unverified bulk action must be import, move or delete.');
        }
        $targetGameId = (int)($payload['target_game_id'] ?? 0);
        if ($action === 'move' && $targetGameId < 1) {
            throw new \RuntimeException('Moving all matching files requires one target game.');
        }
        if ($action === 'import' && $targetGameId < 1 && $targetGameId !== -1) {
            throw new \RuntimeException('Importing all matching files requires a target game or All exact compatible games.');
        }
        return [
            'action' => $action,
            'target_game_id' => $targetGameId,
            'allow_profile_override' => !empty($payload['allow_profile_override']),
            'requested_by' => max(0, (int)($payload['requested_by'] ?? 0)),
        ];
    }

    /** @return array{processed_files:int,succeeded_files:int,failed_files:int,skipped_files:int,failure_samples:list<array<string,string>>} */
    private function aggregateChildren(int $parentJobId): array
    {
        $aggregate = [
            'processed_files' => 0,
            'succeeded_files' => 0,
            'failed_files' => 0,
            'skipped_files' => 0,
            'failure_samples' => [],
        ];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "unverified:batch:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            $aggregate['processed_files'] += max(0, (int)($result['processed_files'] ?? 0));
            $aggregate['succeeded_files'] += max(0, (int)($result['succeeded_files'] ?? 0));
            $aggregate['failed_files'] += max(0, (int)($result['failed_files'] ?? 0));
            $aggregate['skipped_files'] += max(0, (int)($result['skipped_files'] ?? 0));
            foreach ((array)($result['failure_samples'] ?? []) as $failure) {
                if (count($aggregate['failure_samples']) >= self::FAILURE_SAMPLE_LIMIT || !is_array($failure)) {
                    break;
                }
                $aggregate['failure_samples'][] = [
                    'file' => (string)($failure['file'] ?? ''),
                    'error' => (string)($failure['error'] ?? ''),
                ];
            }
        }
        return $aggregate;
    }

    /** @param list<array<string,string>> $samples */
    private function rememberFailure(array &$samples, string $file, string $error): void
    {
        if (count($samples) >= self::FAILURE_SAMPLE_LIMIT) {
            return;
        }
        $samples[] = [
            'file' => $file,
            'error' => mb_strlen($error, 'UTF-8') > 500 ? mb_substr($error, 0, 500, 'UTF-8') : $error,
        ];
    }

    private function isAlreadyGone(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'no longer available')
            || str_contains($message, 'no longer exists')
            || str_contains($message, 'source game no longer exists');
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(string $stage, int $percent, string $message, array $extra = []): array
    {
        $percent = max(0, min(100, $percent));
        return $extra + [
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => $stage,
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'message' => $message,
        ];
    }
}
