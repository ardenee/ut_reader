<?php
/**
 * Durable storage-maintenance workflows.
 *
 * Unverified reconciliation is one independently restartable unit per physical
 * queue file. Stale-artifact cleanup is one unit per cleanup category. Parent
 * jobs only plan/wait/aggregate and never swallow child failures into text.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;

final class CatalogStorageMaintenanceJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 500;

    private readonly CatalogUnverifiedStagingIndex $staging;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
    }

    public function supports(string $jobType): bool
    {
        return in_array(
            $jobType,
            [JobType::RECONCILE_UNVERIFIED_STORAGE, JobType::PRUNE_STALE_ARTIFACTS],
            true
        );
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($job->type === JobType::RECONCILE_UNVERIFIED_STORAGE) {
            if (isset($job->payload['reconcile_queue_name'])) {
                return $this->reconcileOne($job, $context);
            }
            return $this->coordinateReconciliation($job, $context);
        }
        if ($job->type === JobType::PRUNE_STALE_ARTIFACTS) {
            if (isset($job->payload['prune_unit'])) {
                return $this->pruneOne($job, $context);
            }
            return $this->coordinatePruning($job, $context);
        }
        throw new \RuntimeException('Unsupported storage maintenance job: ' . $job->type);
    }

    /** @return array<string,mixed> */
    private function coordinateReconciliation(ClaimedJob $job, JobExecutionContext $context): array
    {
        $limit = max(1, min((int)($job->payload['max_files'] ?? 1000), 10000));
        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'reconcile_plan';
            $resume = [];
        }
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'reconcile_plan';
        }

        if ($stage === 'reconcile_plan') {
            $this->planReconciliation($job, $context, $limit, $resume);
            $stage = 'reconcile_wait';
        }

        if ($stage === 'reconcile_wait') {
            $state = $this->childState($job->id, 'reconcile:');
            if (!$this->childrenReady($context, $state, 'reconcile_wait', 5, 98, 'unverified reconciliation')) {
                throw new \LogicException('Unreachable after reconciliation defer.');
            }
            $context->checkpoint($this->progress(
                'reconcile_finalize',
                99,
                'All unverified reconciliation units completed.',
                ['children' => $state]
            ));
            $stage = 'reconcile_finalize';
        }

        if ($stage !== 'reconcile_finalize') {
            throw new \RuntimeException('Unknown unverified reconciliation workflow stage: ' . $stage);
        }

        $summary = $this->reconciliationSummary($job->id);
        $state = $this->childState($job->id, 'reconcile:');
        $context->checkpoint($this->progress(
            'complete',
            100,
            'Unverified storage reconciliation complete: ' . $summary['indexed'] . ' indexed, '
                . $summary['existing'] . ' already indexed, ' . $summary['missing'] . ' disappeared.',
            ['children' => $state]
        ));
        return [
            'operation' => 'reconcile_unverified_storage',
            'workflow_version' => self::WORKFLOW_VERSION,
            'processed' => $state['completed'],
            'indexed' => $summary['indexed'],
            'existing' => $summary['existing'],
            'missing' => $summary['missing'],
            'failed' => 0,
            'errors' => [],
            'errors_truncated' => false,
            'limit_reached' => !empty($summary['limit_reached']),
            'children' => $state,
        ];
    }

    /** @param array<string,mixed> $resume */
    private function planReconciliation(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $limit,
        array $resume
    ): void {
        $candidates = $this->reconciliationCandidates($limit);
        $lastKey = (string)($resume['plan_last_key'] ?? '');
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        if ((string)($resume['stage'] ?? '') !== 'reconcile_plan') {
            $lastKey = '';
            $planned = 0;
        }

        $queue = new PdoJobQueue($this->db);
        $page = 0;
        foreach ($candidates as $key => $candidate) {
            if ($lastKey !== '' && strcmp($key, $lastKey) <= 0) {
                continue;
            }
            $queue->enqueue(
                $job->queue,
                JobType::RECONCILE_UNVERIFIED_STORAGE,
                [
                    'reconcile_game_id' => (int)$candidate['game_id'],
                    'reconcile_queue_name' => (string)$candidate['name'],
                    'workflow_parent_job_id' => $job->id,
                ],
                30,
                null,
                null,
                null,
                3,
                $job->id,
                'reconcile:' . hash('sha256', $key)
            );
            $lastKey = $key;
            $planned++;
            $page++;
            if ($page >= self::PLAN_BATCH_SIZE) {
                break;
            }
        }

        $hasMore = false;
        foreach (array_keys($candidates) as $key) {
            if ($lastKey === '' || strcmp($key, $lastKey) > 0) {
                $hasMore = true;
                break;
            }
        }
        $progress = $this->progress(
            'reconcile_plan',
            3,
            'Planned ' . $planned . '/' . count($candidates) . ' unverified reconciliation unit(s).',
            [
                'plan_last_key' => $lastKey,
                'planned_units' => $planned,
                'candidate_count' => count($candidates),
                'limit_reached' => count($candidates) >= $limit,
            ]
        );
        if ($hasMore) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->progress(
            'reconcile_wait',
            5,
            'All ' . $planned . ' reconciliation unit(s) are planned; waiting for workers.',
            [
                'planned_units' => $planned,
                'candidate_count' => count($candidates),
                'limit_reached' => count($candidates) >= $limit,
            ]
        ));
    }

    /** @return array<string,array{game_id:int,name:string}> */
    private function reconciliationCandidates(int $limit): array
    {
        $games = \catalog_all($this->db, 'SELECT id,name,slug,profile_id FROM ue_games ORDER BY id');
        $candidates = [];
        foreach ($games as $game) {
            $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, false);
            if (!is_dir($directory) || !is_readable($directory)) {
                continue;
            }
            $names = scandir($directory) ?: [];
            sort($names, SORT_STRING);
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || str_starts_with($name, '.') || str_ends_with(strtolower($name), '.txt')) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if (!is_file($path) || is_link($path) || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
                    continue;
                }
                $key = sprintf('%010d:%s', (int)$game['id'], $name);
                $candidates[$key] = ['game_id' => (int)$game['id'], 'name' => $name];
                if (count($candidates) >= $limit) {
                    break 2;
                }
            }
        }
        ksort($candidates, SORT_STRING);
        return $candidates;
    }

    /** @return array<string,mixed> */
    private function reconcileOne(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = (int)($job->payload['reconcile_game_id'] ?? 0);
        $name = basename(str_replace('\\', '/', trim((string)($job->payload['reconcile_queue_name'] ?? ''))));
        if ($gameId < 1 || $name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Unverified reconciliation unit has an invalid queue identity.');
        }
        $game = \catalog_one($this->db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=? LIMIT 1', [$gameId]);
        if (!$game) {
            throw new \RuntimeException('Unverified reconciliation game no longer exists: ' . $gameId);
        }
        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, false);
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || is_link($path) || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
            $context->checkpoint([
                'stage' => 'complete', 'done' => 1, 'total' => 1, 'percent' => 100, 'status' => 'skipped',
                'message' => 'Unverified queue file disappeared before reconciliation: ' . $name . '.',
            ]);
            return ['operation' => 'reconcile_unverified_storage_file', 'outcome' => 'missing', 'game_id' => $gameId, 'queue_name' => $name];
        }

        $context->checkpoint([
            'stage' => 'reconcile_file', 'done' => 0, 'total' => 1, 'percent' => 1,
            'message' => 'Reconciling unverified queue file: ' . $name . '.',
        ]);
        $originalName = CatalogUnverifiedQueueStorage::originalNameFromQueueName($name);
        $notePath = $path . '.txt';
        $reason = is_file($notePath)
            ? trim((string)file_get_contents($notePath))
            : 'Recovered from unverified filesystem reconciliation.';
        $result = $this->staging->indexPath(
            $gameId,
            $name,
            $path,
            $originalName,
            $reason,
            null,
            '',
            false
        );
        $outcome = (string)($result['status'] ?? '') === 'existing' ? 'existing' : 'indexed';
        $context->checkpoint([
            'stage' => 'complete', 'done' => 1, 'total' => 1, 'percent' => 100, 'status' => $outcome,
            'message' => 'Unverified queue file ' . $outcome . ': ' . $name . '.',
        ]);
        return [
            'operation' => 'reconcile_unverified_storage_file',
            'outcome' => $outcome,
            'game_id' => $gameId,
            'queue_name' => $name,
            'file_id' => (int)($result['file_id'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function coordinatePruning(ClaimedJob $job, JobExecutionContext $context): array
    {
        $minimumAge = max(
            60,
            min(
                (int)($job->payload['orphan_min_age_seconds']
                    ?? $job->payload['incoming_max_age_seconds']
                    ?? 172800),
                30 * 86400
            )
        );
        $storageOnly = !empty($job->payload['storage_only']);
        $queue = new PdoJobQueue($this->db);
        $units = $storageOnly ? ['job_storage'] : ['generated', 'job_storage'];
        foreach ($units as $unit) {
            $queue->enqueue(
                $job->queue,
                JobType::PRUNE_STALE_ARTIFACTS,
                [
                    'prune_unit' => $unit,
                    'orphan_min_age_seconds' => $minimumAge,
                    'workflow_parent_job_id' => $job->id,
                ],
                20,
                null,
                null,
                null,
                3,
                $job->id,
                'prune:' . $unit
            );
        }

        $state = $this->childState($job->id, 'prune:');
        if (!$this->childrenReady($context, $state, 'prune_wait', 5, 98, 'artifact prune')) {
            throw new \LogicException('Unreachable after artifact prune defer.');
        }
        $results = $this->pruneSummary($job->id);
        $jobStorage = is_array($results['job_storage'] ?? null) ? $results['job_storage'] : [];
        $chunkedUploads = is_array($jobStorage['chunked_uploads'] ?? null)
            ? $jobStorage['chunked_uploads']
            : [];
        $message = $storageOnly ? 'Job storage cleanup complete.' : 'Stale artifact cleanup complete.';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'generated' => $results['generated'],
            'chunked_uploads' => $chunkedUploads,
            'job_storage' => $jobStorage,
        ]));
        return [
            'operation' => $storageOnly ? 'prune_job_storage' : 'prune_stale_artifacts',
            'generated' => $results['generated'],
            'chunked_uploads' => $chunkedUploads,
            'job_storage' => $jobStorage,
            'storage_only' => $storageOnly,
            'orphan_min_age_seconds' => $minimumAge,
            'children' => $state,
        ];
    }

    /** @return array<string,mixed> */
    private function pruneOne(ClaimedJob $job, JobExecutionContext $context): array
    {
        $unit = trim((string)($job->payload['prune_unit'] ?? ''));
        $minimumAge = max(60, min((int)($job->payload['orphan_min_age_seconds'] ?? 172800), 30 * 86400));
        $context->checkpoint([
            'stage' => 'prune_unit', 'done' => 0, 'total' => 1, 'percent' => 1,
            'message' => 'Running stale-artifact cleanup unit: ' . $unit . '.',
        ]);
        $result = match ($unit) {
            'generated' => (new GeneratedPackageStore((string)$this->config['storage_path']))->prune(),
            'job_storage' => (new CatalogJobStorageCleanup($this->db, $this->config))->prune($minimumAge),
            default => throw new \InvalidArgumentException('Unknown stale-artifact cleanup unit: ' . $unit),
        };
        $context->checkpoint([
            'stage' => 'complete', 'done' => 1, 'total' => 1, 'percent' => 100, 'status' => 'completed',
            'message' => 'Stale-artifact cleanup unit completed: ' . $unit . '.',
        ]);
        return ['operation' => 'prune_stale_artifact_unit', 'unit' => $unit, 'result' => $result];
    }

    /** @return array{indexed:int,existing:int,missing:int,limit_reached:bool} */
    private function reconciliationSummary(int $parentJobId): array
    {
        $indexed = 0;
        $existing = 0;
        $missing = 0;
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "reconcile:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            $outcome = is_array($result) ? (string)($result['outcome'] ?? '') : '';
            if ($outcome === 'indexed') {
                $indexed++;
            } elseif ($outcome === 'existing') {
                $existing++;
            } else {
                $missing++;
            }
        }
        $progress = $this->parentProgress($parentJobId);
        return [
            'indexed' => $indexed,
            'existing' => $existing,
            'missing' => $missing,
            'limit_reached' => !empty($progress['limit_reached']),
        ];
    }

    /** @return array{generated:mixed,chunked_uploads:mixed,job_storage:mixed} */
    private function pruneSummary(int $parentJobId): array
    {
        $result = ['generated' => [], 'job_storage' => []];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "prune:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $row = json_decode((string)$json, true);
            if (!is_array($row)) {
                continue;
            }
            $unit = (string)($row['unit'] ?? '');
            if (array_key_exists($unit, $result)) {
                $result[$unit] = $row['result'] ?? [];
            }
        }
        return $result;
    }

    /** @param array<string,int> $state */
    private function childrenReady(
        JobExecutionContext $context,
        array $state,
        string $stage,
        int $startPercent,
        int $endPercent,
        string $label
    ): bool {
        $total = max(1, $state['total']);
        $percent = $startPercent + (int)floor((($endPercent - $startPercent) * $state['completed']) / $total);
        $problems = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
        if ($problems > 0) {
            $context->defer(30, $this->progress(
                $stage,
                $percent,
                ucfirst($label) . ' workflow is waiting on ' . $problems . ' failed/cancelled unit(s). '
                    . 'Restart only those units; successful units are retained.',
                [$label . '_children' => $state]
            ));
        }
        if (($state['queued'] + $state['running']) > 0) {
            $context->defer(2, $this->progress(
                $stage,
                $percent,
                ucfirst($label) . ' units: ' . $state['completed'] . '/' . $state['total']
                    . ' completed, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                [$label . '_children' => $state]
            ));
        }
        return $state['total'] === 0 || $state['completed'] === $state['total'];
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId, string $prefix): array
    {
        return (new PdoWorkflowChildStateQuery($this->db))->fetch($parentJobId, $prefix);
    }

    /** @return array<string,mixed> */
    private function parentProgress(int $parentJobId): array
    {
        $statement = $this->db->prepare('SELECT progress_json FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$parentJobId]);
        $decoded = json_decode((string)($statement->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
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
