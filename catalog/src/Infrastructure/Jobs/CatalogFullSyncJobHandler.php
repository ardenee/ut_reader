<?php
/**
 * Durable Full Sync coordinator.
 *
 * The coordinator never loops over package parsing/dependency work itself. It
 * plans idempotent child units, releases its worker while those units run, and
 * resumes at the next phase from persisted progress. Successful units are never
 * replayed after a worker crash or manual Restart.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncProjectionService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;

final class CatalogFullSyncJobHandler implements JobHandler
{
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
        return $jobType === JobType::FULL_SYNC_GAME;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = (int)($job->payload['game_id'] ?? 0);
        if ($gameId < 1) {
            throw new RuntimeException('Full Sync job requires a positive game_id.');
        }
        $requestedBy = (int)($job->payload['requested_by'] ?? 0);
        $requestedBy = $requestedBy > 0 ? $requestedBy : null;
        $game = $this->one('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new RuntimeException('Full Sync game no longer exists: ' . $gameId);
        }

        $resume = $context->resumeProgress();
        $stage = $this->resumeStage($resume);

        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $legacyStage = (string)($resume['stage'] ?? '');
            if ($legacyStage === 'full_sync_finalize') {
                $stage = 'full_sync_finalize';
            } elseif (in_array($legacyStage, ['full_sync_dependencies', 'full_sync_providers'], true)) {
                $stage = 'full_sync_plan_dependencies';
                $resume = [];
            } else {
                $stage = 'full_sync_plan_reimport';
                $resume = [];
            }
        }

        if ($stage === 'full_sync_plan_reimport') {
            $this->planUnits(
                $job,
                $context,
                $gameId,
                $requestedBy,
                JobType::FULL_SYNC_FILE,
                'reimport',
                0,
                5,
                $resume
            );
            $resume = $context->resumeProgress();
            $stage = 'full_sync_wait_reimport';
        }

        if ($stage === 'full_sync_wait_reimport') {
            $state = $this->childState($job->id, 'reimport:');
            if (!$this->childrenReady($context, $state, 'full_sync_wait_reimport', 5, 65, 'package reimport')) {
                throw new \LogicException('Unreachable after Full Sync reimport defer.');
            }
            $context->checkpoint($this->progress(
                'full_sync_prepare_providers',
                70,
                'All ' . $state['completed'] . ' package units completed; rebuilding package providers.',
                ['reimport_children' => $state]
            ));
            $stage = 'full_sync_prepare_providers';
        }

        if ($stage === 'full_sync_prepare_providers') {
            $projectionProgress = static function (array $inner) use ($context): void {
                $innerPercent = max(0, min(100, (int)($inner['percent'] ?? 0)));
                $context->heartbeatIfDue([
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'stage' => 'full_sync_prepare_providers',
                    'done' => $innerPercent,
                    'total' => 100,
                    'percent' => 70 + (int)floor(($innerPercent * 4) / 100),
                    'message' => (string)($inner['message'] ?? 'Preparing package providers.'),
                ]);
            };
            (new CatalogFullSyncProjectionService($this->db, $projectionProgress))->prepareDependencies($gameId);
            $context->checkpoint($this->progress(
                'full_sync_plan_dependencies',
                74,
                'Package providers are ready; planning dependency units.'
            ));
            $resume = [];
            $stage = 'full_sync_plan_dependencies';
        }

        if ($stage === 'full_sync_plan_dependencies') {
            $this->planUnits(
                $job,
                $context,
                $gameId,
                $requestedBy,
                JobType::FULL_SYNC_DEPENDENCY_FILE,
                'dependency',
                74,
                76,
                $resume
            );
            $stage = 'full_sync_wait_dependencies';
        }

        if ($stage === 'full_sync_wait_dependencies') {
            $state = $this->childState($job->id, 'dependency:');
            if (!$this->childrenReady($context, $state, 'full_sync_wait_dependencies', 76, 97, 'dependency')) {
                throw new \LogicException('Unreachable after Full Sync dependency defer.');
            }
            $context->checkpoint($this->progress(
                'full_sync_finalize',
                97,
                'All ' . $state['completed'] . ' dependency units completed; finalizing summaries and game statistics.',
                ['dependency_children' => $state]
            ));
            $stage = 'full_sync_finalize';
        }

        if ($stage !== 'full_sync_finalize') {
            throw new RuntimeException('Unknown Full Sync workflow stage: ' . $stage);
        }

        $finalProgress = static function (array $inner) use ($context): void {
            $innerPercent = max(0, min(100, (int)($inner['percent'] ?? 0)));
            $context->heartbeatIfDue([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'full_sync_finalize',
                'done' => $innerPercent,
                'total' => 100,
                'percent' => 97 + (int)floor(($innerPercent * 3) / 100),
                'message' => (string)($inner['message'] ?? 'Finalizing Full Sync projections.'),
            ]);
        };
        try {
            $final = (new CatalogFullSyncProjectionService($this->db, $finalProgress))->finalize($gameId);
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new RuntimeException('Full Sync finalization failed: ' . $error->getMessage(), 0, $error);
        }

        $stats = is_array($final['stats'] ?? null) ? $final['stats'] : [];
        $reimportState = $this->childState($job->id, 'reimport:');
        $dependencyState = $this->childState($job->id, 'dependency:');
        $message = 'Full Sync complete for ' . (string)$game['name']
            . ': package units=' . $reimportState['completed']
            . ', dependency units=' . $dependencyState['completed']
            . ', missing dependencies=' . (int)($stats['missing_dependency_count'] ?? 0)
            . ', missing packages=' . (int)($stats['missing_package_count'] ?? 0) . '.';

        $context->checkpoint($this->progress('complete', 100, $message, [
            'reimport_children' => $reimportState,
            'dependency_children' => $dependencyState,
            'missing_dependency_count' => (int)($stats['missing_dependency_count'] ?? 0),
            'missing_package_count' => (int)($stats['missing_package_count'] ?? 0),
        ]));

        return [
            'operation' => 'full_sync_game',
            'workflow_version' => self::WORKFLOW_VERSION,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'reimport_children' => $reimportState,
            'dependency_children' => $dependencyState,
            'stats' => $stats,
            'message' => $message,
        ];
    }

    /**
     * Plan at most one bounded page of child rows per coordinator claim. The
     * parent/unit unique key makes replay idempotent if the coordinator dies
     * between the insert and checkpoint.
     *
     * @param array<string,mixed> $resume
     */
    private function planUnits(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $gameId,
        ?int $requestedBy,
        string $childType,
        string $prefix,
        int $percentStart,
        int $percentEnd,
        array $resume
    ): void {
        $expectedStage = $prefix === 'reimport' ? 'full_sync_plan_reimport' : 'full_sync_plan_dependencies';
        $snapshotMaxId = (int)($resume['snapshot_max_file_id'] ?? 0);
        $lastId = (int)($resume['plan_last_file_id'] ?? 0);
        $planned = (int)($resume['planned_units'] ?? 0);

        if ((string)($resume['stage'] ?? '') !== $expectedStage || $snapshotMaxId < 1) {
            $snapshot = $this->one(
                'SELECT COUNT(*) c,COALESCE(MAX(id),0) max_id FROM ue_files WHERE game_id=? AND scan_status="verified"',
                [$gameId]
            ) ?? [];
            $snapshotMaxId = (int)($snapshot['max_id'] ?? 0);
            $lastId = 0;
            $planned = 0;
        }

        if ($snapshotMaxId < 1) {
            $context->checkpoint($this->progress(
                $prefix === 'reimport' ? 'full_sync_wait_reimport' : 'full_sync_wait_dependencies',
                $percentEnd,
                'No verified package units require ' . $prefix . ' work.'
            ));
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND id>? AND id<=? '
            . 'ORDER BY id LIMIT ' . self::PLAN_BATCH_SIZE
        );
        $statement->execute([$gameId, $lastId, $snapshotMaxId]);
        $ids = array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));

        $queue = new PdoJobQueue($this->db);
        foreach ($ids as $fileId) {
            $queue->enqueue(
                $job->queue,
                $childType,
                [
                    'game_id' => $gameId,
                    'file_id' => $fileId,
                    'requested_by' => $requestedBy,
                    'workflow_parent_job_id' => $job->id,
                ],
                90,
                null,
                null,
                $requestedBy,
                3,
                $job->id,
                $prefix . ':' . $fileId
            );
            $lastId = $fileId;
            $planned++;
        }

        $hasMore = $lastId < $snapshotMaxId && $ids !== [];
        $approxPercent = $snapshotMaxId > 0
            ? $percentStart + (int)floor(($percentEnd - $percentStart) * ($lastId / $snapshotMaxId))
            : $percentEnd;
        $progress = $this->progress($expectedStage, min($percentEnd, $approxPercent),
            'Planned ' . $planned . ' durable ' . $prefix . ' unit(s).', [
                'snapshot_max_file_id' => $snapshotMaxId,
                'plan_last_file_id' => $lastId,
                'planned_units' => $planned,
            ]);

        if ($hasMore) {
            $context->defer(1, $progress);
        }

        $context->checkpoint($this->progress(
            $prefix === 'reimport' ? 'full_sync_wait_reimport' : 'full_sync_wait_dependencies',
            $percentEnd,
            'Planned ' . $planned . ' durable ' . $prefix . ' unit(s); waiting for workers.',
            ['planned_units' => $planned]
        ));
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
        if ($state['dead_letter'] > 0 || $state['failed'] > 0 || $state['cancelled'] > 0) {
            // Terminal child problems require operator action. Do not pin this
            // worker to a root that cannot advance: with a small worker pool that
            // would starve unrelated runnable workflows (for example another Full
            // Sync whose failed children have just been repaired/requeued).
            $context->defer(30, $this->progress($stage, $percent,
                ucfirst($label) . ' workflow is waiting on '
                . ($state['dead_letter'] + $state['failed'] + $state['cancelled'])
                . ' error/cancelled unit(s). Restart only those failed child jobs; '
                . $state['completed'] . ' successful unit(s) are retained.',
                [$label . '_children' => $state]
            ), false);
        }
        if (($state['queued'] + $state['running']) > 0) {
            $context->defer(2, $this->progress($stage, $percent,
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

    /** @param array<string,mixed> $resume */
    private function resumeStage(array $resume): string
    {
        $stage = trim((string)($resume['stage'] ?? ''));
        return $stage !== '' && $stage !== 'worker_start' ? $stage : 'full_sync_plan_reimport';
    }

    /** @param list<mixed> $arguments @return array<string,mixed>|null */
    private function one(string $sql, array $arguments): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(string $stage, int $percent, string $message, array $extra = []): array
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
