<?php
/**
 * Executes one durable Full Sync unit. A failed package affects only this row;
 * successful sibling units are never replayed by workflow recovery.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncDependencyBatchService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogFullSyncUnitJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::FULL_SYNC_FILE, JobType::FULL_SYNC_DEPENDENCY_FILE], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return $job->type === JobType::FULL_SYNC_FILE
            ? $this->reimport($job, $context)
            : $this->dependencies($job, $context);
    }

    /** @return array<string,mixed> */
    private function reimport(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positive($job->payload, 'game_id');
        $fileId = $this->positive($job->payload, 'file_id');
        $file = $this->file($gameId, $fileId);
        if ($file === null) {
            return [
                'operation' => 'full_sync_file',
                'game_id' => $gameId,
                'file_id' => $fileId,
                'status' => 'already_removed',
                'message' => 'The Full Sync file unit is already absent; no work remains.',
            ];
        }

        $name = (string)$file['original_name'];
        $context->checkpoint([
            'stage' => 'full_sync_file',
            'done' => 0,
            'total' => 1,
            'percent' => 5,
            'file_id' => $fileId,
            'file_name' => $name,
            'message' => 'Reimporting ' . $name . '.',
        ]);

        $maintenance = new CatalogFileMaintenanceActionService(
            $this->db,
            $this->config,
            (int)($job->payload['requested_by'] ?? 0) ?: null,
            static function (array $progress) use ($context, $fileId, $name): void {
                $progress['file_id'] = $fileId;
                $progress['file_name'] = $name;
                $context->heartbeatIfDue($progress);
            }
        );

        // Full Sync is a reconciliation operation, not a destructive validity sweep.
        // A parser/reader regression or newly tightened validation must never delete
        // an otherwise present verified package. Let the child fail visibly so the
        // operator can inspect/retry it while preserving the file and stable row.
        $result = $maintenance->execute('sync_reimport', [
            'file_id' => $fileId,
            'game_id' => $gameId,
            'package_name' => (string)$file['package_name'],
            'md5' => (string)$file['md5'],
            'package_guid' => (string)($file['package_guid'] ?? ''),
        ]);

        $status = (string)($result['status'] ?? 'reimported');
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'file_id' => $fileId,
            'file_name' => $name,
            'message' => 'Full Sync package unit complete for ' . $name . '.',
        ]);

        return [
            'operation' => 'full_sync_file',
            'game_id' => $gameId,
            'file_id' => $fileId,
            'status' => $status,
            'message' => 'Full Sync package unit complete for ' . $name . '.',
        ];
    }

    /** @return array<string,mixed> */
    private function dependencies(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positive($job->payload, 'game_id');
        $fileIds = $this->dependencyFileIds($job->payload);
        $singleFileId = count($fileIds) === 1 ? (int)$fileIds[0] : 0;
        $unitLabel = $singleFileId > 0
            ? ((string)($this->file($gameId, $singleFileId)['original_name'] ?? ('file #' . $singleFileId)))
            : number_format(count($fileIds)) . ' files';

        $context->checkpoint([
            'stage' => 'full_sync_dependency_file',
            'done' => 0,
            'total' => count($fileIds),
            'percent' => 5,
            'file_id' => $singleFileId,
            'message' => 'Refreshing dependencies for ' . $unitLabel . '.',
        ]);

        $result = (new CatalogFullSyncDependencyBatchService(
            $this->db,
            $this->config,
            static function (array $progress) use ($context, $singleFileId): void {
                if ($singleFileId > 0) {
                    $progress['file_id'] = $singleFileId;
                }
                $context->heartbeatIfDue($progress);
            },
            false
        ))->refresh($gameId, $fileIds);

        $failedIds = [];
        foreach ((array)($result['failures'] ?? []) as $failure) {
            if (!is_array($failure)) {
                continue;
            }
            $failedId = max(0, (int)($failure['file_id'] ?? 0));
            if ($failedId > 0) {
                $failedIds[$failedId] = true;
            }
        }

        if ($failedIds !== []) {
            if ($singleFileId > 0) {
                $failure = is_array($result['failures'][0] ?? null) ? $result['failures'][0] : [];
                throw new \RuntimeException(
                    'Dependency refresh failed for file #' . $singleFileId . ': '
                    . trim((string)($failure['error'] ?? 'Unknown dependency refresh error.'))
                );
            }

            // A batch is only an execution optimization. Split failed members back
            // into durable one-file retry children so successful members remain
            // complete and the operator still gets exact per-file failures.
            $parentJobId = $job->parentJobId
                ?? max(0, (int)($job->payload['workflow_parent_job_id'] ?? 0));
            if ($parentJobId < 1) {
                throw new \RuntimeException('Dependency batch cannot schedule failed file retries without its workflow parent.');
            }
            $requestedBy = max(0, (int)($job->payload['requested_by'] ?? 0));
            $queue = new PdoJobQueue($this->db);
            $retryUnits = [];
            foreach (array_keys($failedIds) as $failedId) {
                $retryUnits[] = [
                    'payload' => [
                        'game_id' => $gameId,
                        'file_id' => (int)$failedId,
                        'requested_by' => $requestedBy > 0 ? $requestedBy : null,
                        'workflow_parent_job_id' => $parentJobId,
                        'retry_from_batch_job_id' => $job->id,
                    ],
                    'workflow_unit_key' => 'dependency:retry:' . (int)$failedId,
                ];
            }
            $queue->enqueueWorkflowUnits(
                $job->queue,
                JobType::FULL_SYNC_DEPENDENCY_FILE,
                $retryUnits,
                90,
                null,
                $requestedBy > 0 ? $requestedBy : null,
                3,
                $parentJobId
            );
        }

        $completed = max(0, (int)($result['succeeded'] ?? 0));
        $context->checkpoint([
            'stage' => 'complete',
            'done' => count($fileIds),
            'total' => count($fileIds),
            'percent' => 100,
            'file_id' => $singleFileId,
            'message' => $failedIds === []
                ? 'Dependency batch complete for ' . number_format(count($fileIds)) . ' file(s).'
                : 'Dependency batch completed ' . number_format($completed) . ' file(s); '
                    . number_format(count($failedIds)) . ' failed file(s) were split into exact retry jobs.',
        ]);

        return [
            'operation' => 'full_sync_dependency_file',
            'game_id' => $gameId,
            'file_id' => $singleFileId,
            'file_ids' => $fileIds,
            'status' => $failedIds === [] ? 'completed' : 'completed_with_retries',
            'succeeded' => $completed,
            'retry_children' => count($failedIds),
            'metadata_repairs' => (int)($result['metadata_repairs'] ?? 0),
            'message' => $failedIds === []
                ? 'Dependency batch complete.'
                : count($failedIds) . ' failed dependency file(s) were isolated into per-file retry jobs.',
        ];
    }

    /** @param array<string,mixed> $payload @return list<int> */
    private function dependencyFileIds(array $payload): array
    {
        $ids = [];
        $batch = $payload['file_ids'] ?? null;
        if (is_array($batch)) {
            foreach ($batch as $fileId) {
                $id = (int)$fileId;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($ids === []) {
            $id = $this->positive($payload, 'file_id');
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if (count($ids) > CatalogFullSyncDependencyBatchService::MAX_BATCH_SIZE) {
            throw new \RuntimeException(
                'Full Sync dependency unit exceeds the maximum batch size of '
                . CatalogFullSyncDependencyBatchService::MAX_BATCH_SIZE . '.'
            );
        }
        return $ids;
    }

    /** @return array<string,mixed>|null */
    private function file(int $gameId, int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,md5,package_guid FROM ue_files '
            . 'WHERE id=? AND game_id=? AND scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId, $gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $payload */
    private function positive(array $payload, string $key): int
    {
        $value = (int)($payload[$key] ?? 0);
        if ($value < 1) {
            throw new \RuntimeException('Full Sync unit requires a positive ' . $key . '.');
        }
        return $value;
    }
}
