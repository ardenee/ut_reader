<?php
/**
 * Catalog maintenance job handler. Whole-game source identity repair is a
 * parent/child workflow; individual file repair remains one bounded unit.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;
use UnrealDb\Catalog\Infrastructure\Storage\UploadProgressPruner;

final class CatalogMaintenanceJobHandler implements JobHandler
{
    private const MAINTENANCE_WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';
    private const MAINTENANCE_WRITE_LOCK_WAIT = 45;
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 500;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [
            JobType::REPAIR_SOURCE_IDENTITY_FILE,
            JobType::REPAIR_SOURCE_IDENTITY_GAME,
            JobType::PRUNE_UPLOAD_PROGRESS,
        ], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return match ($job->type) {
            JobType::REPAIR_SOURCE_IDENTITY_FILE => $this->repairSourceIdentityFile($job, $context),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => $this->repairSourceIdentityGame($job, $context),
            JobType::PRUNE_UPLOAD_PROGRESS => $this->pruneUploadProgress($job, $context),
            default => throw new \RuntimeException('Unsupported catalog maintenance job: ' . $job->type),
        };
    }

    /** @return array<string,mixed> */
    private function repairSourceIdentityFile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->requiredPositiveInt($job->payload, 'file_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        require_once __DIR__ . '/../../../lib/CatalogSourceIdentity.php';

        $exists = $this->fetchOne('SELECT id FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId]);
        if ($exists === null && !empty($job->payload['workflow_parent_job_id'])) {
            return [
                'operation' => 'repair_source_identity_file',
                'file_id' => $fileId,
                'status' => 'already_removed',
                'changed' => false,
                'alias_count' => 0,
                'message' => 'Verified file was removed after workflow planning; no source identity work remains.',
            ];
        }

        return $this->withMaintenanceWriteLock(function () use ($fileId, $context): array {
            $context->checkpoint([
                'stage' => 'source_identity',
                'done' => 0,
                'total' => 1,
                'percent' => 0,
                'file_id' => $fileId,
                'message' => 'Preparing canonical source identity repair.',
            ]);
            $result = \catalog_source_identity_rebuild_file(
                $this->db,
                $this->config,
                $fileId,
                static function (array $progress) use ($context, $fileId): void {
                    $progress['file_id'] = $fileId;
                    $context->heartbeatIfDue($progress);
                },
                true
            );
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'file_id' => $fileId,
                'message' => !empty($result['changed'])
                    ? 'Canonical source identity repair complete.'
                    : 'The file already matches its mounted source path.',
            ]);

            return ['operation' => 'repair_source_identity_file', 'file_id' => $fileId] + $result;
        });
    }

    /** @return array<string,mixed> */
    private function repairSourceIdentityGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->requiredPositiveInt($job->payload, 'game_id');
        $game = $this->fetchOne('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new \RuntimeException('Game no longer exists: ' . $gameId);
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start' || (int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'source_identity_game_plan';
            $resume = [];
        }

        if ($stage === 'source_identity_game_plan') {
            $this->planSourceIdentityUnits($job, $context, $gameId, $resume);
            $stage = 'source_identity_game_wait';
        }

        if ($stage === 'source_identity_game_wait') {
            $state = $this->childState($job->id, 'source_identity:');
            $total = max(1, $state['total']);
            $percent = 5 + (int)floor(($state['completed'] * 75) / $total);
            if (($state['failed'] + $state['dead_letter'] + $state['cancelled']) > 0) {
                $context->defer(30, $this->workflowProgress(
                    'source_identity_game_wait',
                    min(80, $percent),
                    'Source identity repair is waiting on '
                        . ($state['failed'] + $state['dead_letter'] + $state['cancelled'])
                        . ' failed/cancelled file unit(s). Restart only those child jobs; '
                        . $state['completed'] . ' successful units are retained.',
                    ['children' => $state]
                ));
            }
            if (($state['queued'] + $state['running']) > 0) {
                $context->defer(2, $this->workflowProgress(
                    'source_identity_game_wait',
                    min(80, $percent),
                    'Source identity units: ' . $state['completed'] . '/' . $state['total']
                        . ' complete, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                    ['children' => $state]
                ));
            }
            $context->checkpoint($this->workflowProgress(
                'source_identity_game_dependency_plan',
                82,
                'All source identity file units completed; queueing the dependency refresh workflow.',
                ['children' => $state]
            ));
            $stage = 'source_identity_game_dependency_plan';
        }

        if ($stage === 'source_identity_game_dependency_plan') {
            $dependencyJobId = (new PdoJobQueue($this->db))->enqueue(
                $job->queue,
                JobType::REBUILD_GAME_DEPENDENCIES,
                [
                    'game_id' => $gameId,
                    'offset' => 0,
                    'workflow_parent_job_id' => $job->id,
                ],
                20,
                null,
                null,
                null,
                3,
                $job->id,
                'dependencies'
            );
            $context->checkpoint($this->workflowProgress(
                'source_identity_game_dependency_wait',
                85,
                'Dependency refresh workflow #' . $dependencyJobId . ' queued.',
                ['dependency_job_id' => $dependencyJobId]
            ));
            $stage = 'source_identity_game_dependency_wait';
        }

        if ($stage === 'source_identity_game_dependency_wait') {
            $dependency = $this->workflowChild($job->id, 'dependencies');
            if ($dependency === null) {
                $context->checkpoint($this->workflowProgress(
                    'source_identity_game_dependency_plan',
                    82,
                    'Dependency workflow child was not found; replanning it.'
                ));
                $context->defer(1);
            }
            $status = (string)($dependency['status'] ?? 'queued');
            if (in_array($status, ['failed', 'dead_letter', 'cancelled'], true)) {
                $context->defer(30, $this->workflowProgress(
                    'source_identity_game_dependency_wait',
                    90,
                    'Dependency child job #' . (int)$dependency['id'] . ' requires attention. Restart that child only; source identity file work is retained.',
                    ['dependency_job_id' => (int)$dependency['id'], 'dependency_status' => $status]
                ));
            }
            if ($status !== 'completed') {
                $progress = json_decode((string)($dependency['progress_json'] ?? ''), true);
                $innerPercent = is_array($progress) ? max(0, min(100, (int)($progress['percent'] ?? 0))) : 0;
                $context->defer(2, $this->workflowProgress(
                    'source_identity_game_dependency_wait',
                    85 + (int)floor(($innerPercent * 14) / 100),
                    'Dependency workflow #' . (int)$dependency['id'] . ' is ' . $status . '.',
                    ['dependency_job_id' => (int)$dependency['id'], 'dependency_status' => $status]
                ));
            }
            $stage = 'source_identity_game_finalize';
            $context->checkpoint($this->workflowProgress(
                $stage,
                99,
                'Dependency workflow completed; finalizing source identity workflow.'
            ));
        }

        if ($stage !== 'source_identity_game_finalize') {
            throw new \RuntimeException('Unknown source identity workflow stage: ' . $stage);
        }

        $state = $this->childState($job->id, 'source_identity:');
        $aggregate = $this->sourceIdentityAggregate($job->id);
        $result = [
            'operation' => 'repair_source_identity_game',
            'workflow_version' => self::WORKFLOW_VERSION,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'total' => $state['total'],
            'changed' => $aggregate['changed'],
            'aliases' => $aggregate['aliases'],
            'failure_count' => 0,
            'failures' => [],
            'failures_truncated' => false,
            'dependencies_rebuilt' => true,
            'children' => $state,
            'message' => 'Source identity repair complete: ' . $state['completed'] . ' durable file unit(s), '
                . $aggregate['changed'] . ' changed, ' . $aggregate['aliases'] . ' alias(es).',
        ];
        $context->checkpoint($this->workflowProgress('complete', 100, (string)$result['message'], $result));
        return $result;
    }

    /** @param array<string,mixed> $resume */
    private function planSourceIdentityUnits(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $gameId,
        array $resume
    ): void {
        $snapshotMaxId = max(0, (int)($resume['snapshot_max_file_id'] ?? 0));
        $lastId = max(0, (int)($resume['plan_last_file_id'] ?? 0));
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        if ($snapshotMaxId < 1) {
            $snapshot = $this->fetchOne(
                'SELECT COALESCE(MAX(id),0) max_id FROM ue_files WHERE game_id=? AND scan_status="verified"',
                [$gameId]
            ) ?? [];
            $snapshotMaxId = (int)($snapshot['max_id'] ?? 0);
        }
        if ($snapshotMaxId < 1) {
            $context->checkpoint($this->workflowProgress('source_identity_game_wait', 5, 'No verified files require source identity repair.'));
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND id>? AND id<=? ORDER BY id LIMIT '
            . self::PLAN_BATCH_SIZE
        );
        $statement->execute([$gameId, $lastId, $snapshotMaxId]);
        $ids = array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));
        $queue = new PdoJobQueue($this->db);
        foreach ($ids as $fileId) {
            $queue->enqueue(
                $job->queue,
                JobType::REPAIR_SOURCE_IDENTITY_FILE,
                ['file_id' => $fileId, 'workflow_parent_job_id' => $job->id],
                10,
                null,
                null,
                null,
                3,
                $job->id,
                'source_identity:' . $fileId
            );
            $lastId = $fileId;
            $planned++;
        }

        $progress = $this->workflowProgress('source_identity_game_plan', 3,
            'Planned ' . $planned . ' durable source identity file unit(s).', [
                'snapshot_max_file_id' => $snapshotMaxId,
                'plan_last_file_id' => $lastId,
                'planned_units' => $planned,
            ]);
        if ($ids !== [] && $lastId < $snapshotMaxId) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->workflowProgress(
            'source_identity_game_wait',
            5,
            'Planned ' . $planned . ' durable source identity file unit(s); waiting for workers.',
            ['planned_units' => $planned]
        ));
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId, string $prefix): array
    {
        return (new PdoWorkflowChildStateQuery($this->db))->fetch($parentJobId, $prefix);
    }

    /** @return array<string,mixed>|null */
    private function workflowChild(int $parentJobId, string $unitKey): ?array
    {
        return $this->fetchOne(
            'SELECT id,status,progress_json,result_json,last_error FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key=? LIMIT 1',
            [$parentJobId, $unitKey]
        );
    }

    /** @return array{changed:int,aliases:int} */
    private function sourceIdentityAggregate(int $parentJobId): array
    {
        $statement = $this->db->prepare(
            'SELECT '
            . 'COALESCE(SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.changed")) IN ("1","true") THEN 1 ELSE 0 END),0) changed,'
            . 'COALESCE(SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.alias_count")),"0") AS UNSIGNED)),0) aliases '
            . 'FROM ue_background_jobs WHERE parent_job_id=? AND workflow_unit_key LIKE "source_identity:%" AND status="completed"'
        );
        $statement->execute([$parentJobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['changed' => (int)($row['changed'] ?? 0), 'aliases' => (int)($row['aliases'] ?? 0)];
    }

    /** @return array<string,mixed> */
    private function pruneUploadProgress(ClaimedJob $job, JobExecutionContext $context): array
    {
        $maxAge = isset($job->payload['max_age_seconds'])
            ? max(60, min((int)$job->payload['max_age_seconds'], 604800))
            : 86400;
        $context->checkpoint(['stage' => 'pruning_upload_progress', 'max_age_seconds' => $maxAge]);
        $removed = (new UploadProgressPruner())->prune($maxAge);
        $context->checkpoint(['stage' => 'pruned_upload_progress', 'removed_files' => $removed]);

        return [
            'max_age_seconds' => $maxAge,
            'removed_files' => $removed,
            'operation' => 'prune_upload_progress',
        ];
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    private function fetchOne(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function withMaintenanceWriteLock(callable $operation): array
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([self::MAINTENANCE_WRITE_LOCK, self::MAINTENANCE_WRITE_LOCK_WAIT]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Another catalog maintenance write task is running.');
        }

        try {
            $result = $operation();
            if (!is_array($result)) {
                throw new \RuntimeException('Maintenance operation returned an invalid result.');
            }
            return $result;
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::MAINTENANCE_WRITE_LOCK]);
            } catch (Throwable $releaseError) {
                error_log('[UnrealDB jobs] Could not release maintenance write lock: ' . $releaseError->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function requiredPositiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Job payload requires positive ' . $field . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function workflowProgress(string $stage, int $percent, string $message, array $extra = []): array
    {
        return [
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ] + $extra;
    }
}
