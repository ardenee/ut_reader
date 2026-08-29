<?php
/**
 * Rebuilds dependency projections. Whole-game work is a durable coordinator
 * over existing per-file dependency jobs so successful files are never replayed.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedCompactMetadataHealth;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;

final class CatalogDependencyRefreshJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 500;
    private const SUMMARY_BATCH_SIZE = 1000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [
            JobType::REBUILD_GAME_DEPENDENCIES,
            JobType::REBUILD_FILE_DEPENDENCIES,
        ], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return $job->type === JobType::REBUILD_GAME_DEPENDENCIES
            ? $this->rebuildGame($job, $context)
            : $this->rebuildFile($job, $context);
    }

    /** @return array<string,mixed> */
    private function rebuildFile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->positiveInt($job->payload, 'file_id');
        if ($this->isLegacyPakDependencyFileUnit($job)) {
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'file_id' => $fileId,
                'status' => 'superseded',
                'message' => 'Legacy PAK whole-game dependency unit superseded by targeted PAK dependency refresh.',
            ]);
            return [
                'operation' => 'rebuild_file_dependencies',
                'file_id' => $fileId,
                'status' => 'superseded',
                'message' => 'Legacy PAK whole-game dependency unit superseded by targeted PAK dependency refresh.',
            ];
        }

        $file = $this->one(
            'SELECT id,game_id,package_name,original_name FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if ($file === null) {
            if (!empty($job->payload['workflow_parent_job_id'])) {
                return [
                    'operation' => 'rebuild_file_dependencies',
                    'file_id' => $fileId,
                    'status' => 'already_removed',
                    'message' => 'Verified file was removed after workflow planning; no dependency work remains.',
                ];
            }
            throw new RuntimeException('Verified file no longer exists: ' . $fileId);
        }

        $postImport = !empty($job->payload['post_import']);
        $renameRefresh = !empty($job->payload['rename_refresh']);
        $deferGameStats = !empty($job->payload['workflow_defer_game_stats']);
        $deferSummaryPolicy = array_key_exists('workflow_defer_dependency_summary', $job->payload)
            ? !empty($job->payload['workflow_defer_dependency_summary'])
            : true;
        $deferWorkflowSummary = !$postImport
            && !empty($job->payload['workflow_parent_job_id'])
            && $deferGameStats
            && $deferSummaryPolicy;

        $this->ensureCompactMetadataReady($job, $context, $fileId, (string)$file['original_name']);

        (new PdoCatalogDependencyRebuilder($this->db, $this->config))->rebuild(
            $fileId,
            static function (array $progress) use ($context, $fileId): void {
                $progress['file_id'] = $fileId;
                $context->heartbeatIfDue($progress);
            },
            0,
            70,
            'Refreshing file dependency links',
            false
        );

        $context->checkpoint([
            'stage' => 'package_provider',
            'done' => 1,
            'total' => 4,
            'percent' => 74,
            'message' => 'Reconciling the package provider.',
            'file_id' => $fileId,
        ]);
        (new PdoPackageProviderRepository($this->db))->reconcileFile($fileId);

        $summaryRows = 0;
        if ($deferWorkflowSummary) {
            $context->checkpoint([
                'stage' => 'dependency_summary_deferred',
                'done' => 2,
                'total' => 4,
                'percent' => 82,
                'message' => 'Dependency summary deferred to the parent workflow bulk publisher.',
                'file_id' => $fileId,
            ]);
        } else {
            $context->checkpoint([
                'stage' => 'dependency_summary',
                'done' => 2,
                'total' => 4,
                'percent' => 82,
                'message' => 'Rebuilding the file dependency summary.',
                'file_id' => $fileId,
            ]);
            $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);
            if (empty($summary['available'])) {
                throw new RuntimeException('Dependency package summary projection is unavailable after compact rebuild.');
            }
            $summaryRows = (int)($summary['summary_rows'] ?? 0);
        }

        $affectedJobId = 0;
        if ($postImport) {
            $context->checkpoint([
                'stage' => 'affected_detection',
                'done' => 3,
                'total' => 4,
                'percent' => 90,
                'message' => $renameRefresh
                    ? 'Checking dependencies affected by the corrected package identity.'
                    : 'Checking whether existing files reference the imported package.',
                'file_id' => $fileId,
                'dependency_summary_rows' => $summaryRows,
            ]);

            if ($renameRefresh) {
                $affectedJobId = CatalogAffectedDependencyRefreshCoordinator::enqueueRenameRefresh(
                    $this->db,
                    (int)$file['game_id'],
                    $fileId,
                    (string)$file['package_name'],
                    (string)($job->payload['old_package_name'] ?? '')
                );
            } else {
                $affectedJobId = CatalogAffectedDependencyRefreshCoordinator::enqueueIfNeeded(
                    $this->db,
                    (int)$file['game_id'],
                    $fileId,
                    (string)$file['package_name'],
                    true,
                    true
                );
            }
        }

        $gameStats = null;
        if ($affectedJobId < 1 && !$deferGameStats) {
            $context->checkpoint([
                'stage' => 'game_stats',
                'done' => 4,
                'total' => 4,
                'percent' => 95,
                'message' => 'Refreshing cached game counters.',
                'file_id' => $fileId,
                'dependency_summary_rows' => $summaryRows,
            ]);
            $gameStats = (new PdoGameCatalogStats($this->db))->rebuildGame((int)$file['game_id']);
        } elseif ($affectedJobId > 0) {
            $context->checkpoint([
                'stage' => 'affected_queued',
                'done' => 4,
                'total' => 4,
                'percent' => 100,
                'message' => 'Queued affected-file refresh job #' . $affectedJobId
                    . '; that chain will publish final game counters.',
                'file_id' => $fileId,
                'affected_job_id' => $affectedJobId,
                'dependency_summary_rows' => $summaryRows,
            ]);
        } else {
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 4,
                'total' => 4,
                'percent' => 100,
                'message' => $deferWorkflowSummary
                    ? 'Dependency file unit complete; parent workflow will publish summaries and game counters once.'
                    : ($deferGameStats
                        ? 'Dependency file unit complete; parent workflow will publish game counters once.'
                        : 'Dependency file unit complete.'),
                'file_id' => $fileId,
                'dependency_summary_rows' => $summaryRows,
                'dependency_summary_deferred' => $deferWorkflowSummary,
            ]);
        }

        return [
            'operation' => 'rebuild_file_dependencies',
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'post_import' => $postImport,
            'rename_refresh' => $renameRefresh,
            'package_provider_reconciled' => true,
            'dependency_summary_rows' => $summaryRows,
            'dependency_summary_deferred' => $deferWorkflowSummary,
            'affected_job_id' => $affectedJobId,
            'game_stats_refreshed' => $gameStats !== null,
            'stats' => $deferGameStats ? null : $this->stats([$fileId]),
        ];
    }

    /** @return array<string,mixed> */
    private function rebuildGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        if (!empty($job->payload['game_stats_only'])) {
            return $this->rebuildGameStatsOnly($job, $context, $gameId);
        }
        if ($this->isPakDependencyWorkflow($job)) {
            return $this->rebuildPakDependencies($job, $context, $gameId);
        }

        $offset = max(0, (int)($job->payload['offset'] ?? 0));
        $game = $this->one('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new RuntimeException('Game no longer exists: ' . $gameId);
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start' || (int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'dependency_game_plan';
            $resume = [];
        }

        if ($stage === 'dependency_game_plan') {
            $this->planGameDependencyUnits($job, $context, $gameId, $offset, $resume);
            $stage = 'dependency_game_wait';
        }

        if ($stage === 'dependency_game_wait') {
            $state = $this->childState($job->id);
            $total = max(1, $state['total']);
            $percent = 5 + (int)floor(($state['completed'] * 90) / $total);
            if (($state['failed'] + $state['dead_letter'] + $state['cancelled']) > 0) {
                $context->defer(30, $this->workflowProgress(
                    'dependency_game_wait',
                    min(95, $percent),
                    'Dependency rebuild is waiting on '
                        . ($state['failed'] + $state['dead_letter'] + $state['cancelled'])
                        . ' failed/cancelled file unit(s). Restart only those child jobs; '
                        . $state['completed'] . ' successful file unit(s) are retained.',
                    ['children' => $state]
                ));
            }
            if (($state['queued'] + $state['running']) > 0) {
                $context->defer(2, $this->workflowProgress(
                    'dependency_game_wait',
                    min(95, $percent),
                    'Dependency file units: ' . $state['completed'] . '/' . $state['total']
                        . ' complete, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                    ['children' => $state]
                ));
            }
            $resume = $this->workflowProgress(
                'dependency_game_summary',
                95,
                'All dependency file units completed; publishing dependency summaries in bounded batches.',
                [
                    'children' => $state,
                    'summary_last_child_job_id' => 0,
                    'summary_files' => 0,
                    'summary_rows' => 0,
                ]
            );
            $context->checkpoint($resume);
            $stage = 'dependency_game_summary';
        }

        if ($stage === 'dependency_game_finalize' && empty($resume['dependency_summary_complete'])) {
            $stage = 'dependency_game_summary';
        }

        if ($stage === 'dependency_game_summary') {
            $summary = $this->rebuildGameSummaryBatch($job, $context, $resume);
            $state = $this->childState($job->id);
            $resume = $this->workflowProgress(
                'dependency_game_finalize',
                99,
                'Dependency summaries published; refreshing cached game counters.',
                [
                    'children' => $state,
                    'dependency_summary_complete' => true,
                    'summary_last_child_job_id' => (int)$summary['last_child_job_id'],
                    'summary_files' => (int)$summary['files'],
                    'summary_rows' => (int)$summary['rows'],
                ]
            );
            $context->checkpoint($resume);
            $stage = 'dependency_game_finalize';
        }

        if ($stage !== 'dependency_game_finalize') {
            throw new RuntimeException('Unknown game dependency workflow stage: ' . $stage);
        }

        $gameStats = (new PdoGameCatalogStats($this->db))->rebuildGame($gameId);
        $state = $this->childState($job->id);
        $result = [
            'operation' => 'rebuild_game_dependencies',
            'workflow_version' => self::WORKFLOW_VERSION,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'offset' => $offset,
            'processed_files' => $state['completed'],
            'dependency_summary_files' => (int)($resume['summary_files'] ?? 0),
            'dependency_summary_rows' => (int)($resume['summary_rows'] ?? 0),
            'game_stats_refreshed' => $gameStats !== null,
            'children' => $state,
            'stats' => $this->statsForGame($gameId),
            'message' => 'Game dependency rebuild complete: ' . $state['completed'] . ' durable file unit(s).',
        ];
        $context->checkpoint($this->workflowProgress('complete', 100, (string)$result['message'], $result));
        return $result;
    }

    /** @return array<string,mixed> */
    private function rebuildGameStatsOnly(ClaimedJob $job, JobExecutionContext $context, int $gameId): array
    {
        $game = $this->one('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new RuntimeException('Game no longer exists: ' . $gameId);
        }

        if ($this->pendingGameDependencyWorkExists($job, $gameId)) {
            $context->defer(15, $this->workflowProgress(
                'game_stats_wait',
                10,
                'Coalesced cached-counter refresh is waiting for game dependency work to drain.',
                ['game_id' => $gameId, 'mode' => 'stats_only']
            ));
        }

        $stats = new PdoGameCatalogStats($this->db);
        if (!$stats->available()) {
            throw new RuntimeException('Cached game statistics projection is unavailable.');
        }

        $context->checkpoint($this->workflowProgress(
            'game_stats_refresh',
            25,
            'Refreshing coalesced cached game counters for ' . (string)$game['name'] . '.',
            ['game_id' => $gameId, 'mode' => 'stats_only']
        ));
        $rebuilt = $stats->rebuildGame($gameId, 5);
        if ($rebuilt === null) {
            $context->defer(5, $this->workflowProgress(
                'game_stats_refresh',
                50,
                'Cached game counters are busy in another publisher; retrying shortly.',
                ['game_id' => $gameId, 'mode' => 'stats_only']
            ));
        }

        $message = 'Cached game counters refreshed for ' . (string)$game['name'] . '.';
        $result = [
            'operation' => 'refresh_game_catalog_stats',
            'workflow_version' => self::WORKFLOW_VERSION,
            'mode' => 'stats_only',
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'game_stats_refreshed' => true,
            'message' => $message,
        ];
        $context->checkpoint($this->workflowProgress('complete', 100, $message, $result));
        return $result;
    }

    private function pendingGameDependencyWorkExists(ClaimedJob $job, int $gameId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM ue_background_jobs WHERE queue_name=? AND status IN ("queued","running") '
            . 'AND concurrency_key=? AND id<>? '
            . 'AND (dedupe_key IS NULL OR dedupe_key NOT LIKE ?) LIMIT 1'
        );
        $statement->execute([
            $job->queue,
            'dependency:game:' . $gameId,
            $job->id,
            'game-stats:' . $gameId . ':%',
        ]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array<string,mixed> */
    private function rebuildPakDependencies(ClaimedJob $job, JobExecutionContext $context, int $gameId): array
    {
        $pakParentJobId = (int)($job->parentJobId ?? 0);
        if ($pakParentJobId < 1) {
            throw new RuntimeException('Targeted PAK dependency workflow has no PAK parent job.');
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if (!str_starts_with($stage, 'pak_dependency_')) {
            $this->cancelQueuedLegacyChildren($job->id);
            $stage = 'pak_dependency_plan';
            $resume = [];
        }

        if ($stage === 'pak_dependency_plan') {
            $targets = (new CatalogPakDependencyTargetQuery($this->db))->discover($pakParentJobId, $gameId);
            $units = [];
            foreach ((array)$targets['target_file_ids'] as $fileId) {
                $fileId = (int)$fileId;
                if ($fileId < 1) {
                    continue;
                }
                $units[] = [
                    'payload' => [
                        'file_id' => $fileId,
                        'workflow_parent_job_id' => $job->id,
                        'workflow_defer_game_stats' => true,
                        'workflow_defer_dependency_summary' => false,
                        'pak_dependency_refresh' => true,
                    ],
                    'workflow_unit_key' => 'pak-dependency:' . $fileId,
                ];
            }
            if ($units !== []) {
                (new PdoJobQueue($this->db))->enqueueWorkflowUnits(
                    $job->queue,
                    JobType::REBUILD_FILE_DEPENDENCIES,
                    $units,
                    25,
                    null,
                    (int)($job->payload['requested_by'] ?? 0) ?: null,
                    3,
                    $job->id
                );
            }
            $resume = $this->workflowProgress(
                'pak_dependency_wait',
                10,
                'Targeted PAK dependency refresh planned for ' . count($units) . ' file(s): '
                    . count((array)$targets['source_file_ids']) . ' PAK provider file(s), '
                    . count((array)$targets['affected_file_ids']) . ' affected catalog file(s).',
                [
                    'pak_parent_job_id' => $pakParentJobId,
                    'pak_source_files' => count((array)$targets['source_file_ids']),
                    'pak_provider_packages' => count((array)$targets['provider_packages']),
                    'pak_affected_files' => count((array)$targets['affected_file_ids']),
                    'pak_target_files' => count($units),
                ]
            );
            $context->checkpoint($resume);
            $stage = 'pak_dependency_wait';
        }

        if ($stage === 'pak_dependency_wait') {
            $state = $this->childState($job->id, 'pak-dependency:');
            $total = max(1, $state['total']);
            $percent = 10 + (int)floor(($state['completed'] * 85) / $total);
            $problems = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
            if ($problems > 0) {
                $context->defer(30, $this->workflowProgress(
                    'pak_dependency_wait',
                    min(95, $percent),
                    'Targeted PAK dependency refresh is waiting on ' . $problems
                        . ' failed/cancelled file unit(s); completed file work is retained.',
                    ['children' => $state] + $resume
                ));
            }
            if (($state['queued'] + $state['running']) > 0) {
                $context->defer(2, $this->workflowProgress(
                    'pak_dependency_wait',
                    min(95, $percent),
                    'Targeted PAK dependencies: ' . $state['completed'] . '/' . $state['total']
                        . ' complete, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                    ['children' => $state] + $resume
                ));
            }
            $resume = $this->workflowProgress(
                'pak_dependency_finalize',
                97,
                'Targeted PAK dependency files completed; scheduling one coalesced cached-counter refresh.',
                ['children' => $state] + $resume
            );
            $context->checkpoint($resume);
            $stage = 'pak_dependency_finalize';
        }

        if ($stage !== 'pak_dependency_finalize') {
            throw new RuntimeException('Unknown targeted PAK dependency workflow stage: ' . $stage);
        }

        $requestedBy = (int)($job->payload['requested_by'] ?? 0) ?: null;
        $statsJobId = CatalogGameStatsRefreshCoordinator::request(
            $this->db,
            $job->queue,
            $gameId,
            $requestedBy
        );
        $state = $this->childState($job->id, 'pak-dependency:');
        $message = 'Targeted PAK dependency refresh complete: ' . $state['completed']
            . ' file unit(s); cached game counters coalesced into job #' . $statsJobId . '.';
        $result = [
            'operation' => 'rebuild_game_dependencies',
            'workflow_version' => self::WORKFLOW_VERSION,
            'mode' => 'pak_targeted',
            'game_id' => $gameId,
            'pak_parent_job_id' => $pakParentJobId,
            'processed_files' => $state['completed'],
            'pak_source_files' => (int)($resume['pak_source_files'] ?? 0),
            'pak_provider_packages' => (int)($resume['pak_provider_packages'] ?? 0),
            'pak_affected_files' => (int)($resume['pak_affected_files'] ?? 0),
            'pak_target_files' => (int)($resume['pak_target_files'] ?? 0),
            'game_stats_refreshed' => false,
            'game_stats_refresh_job_id' => $statsJobId,
            'children' => $state,
            'message' => $message,
        ];
        $context->checkpoint($this->workflowProgress('complete', 100, $message, $result));
        return $result;
    }

    /**
     * Publishes dependency summaries for completed child jobs without holding one
     * worker for the entire game. PdoDependencyPackageSummary performs its own
     * 250-file transaction batching inside each durable 1,000-child cursor step.
     *
     * @param array<string,mixed> $resume
     * @return array{last_child_job_id:int,files:int,rows:int}
     */
    private function rebuildGameSummaryBatch(ClaimedJob $job, JobExecutionContext $context, array $resume): array
    {
        $lastChildJobId = max(0, (int)($resume['summary_last_child_job_id'] ?? 0));
        $summaryFiles = max(0, (int)($resume['summary_files'] ?? 0));
        $summaryRows = max(0, (int)($resume['summary_rows'] ?? 0));

        $statement = $this->db->prepare(
            'SELECT id,payload_json FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND job_type=? AND status="completed" AND id>? '
            . 'ORDER BY id LIMIT ' . self::SUMMARY_BATCH_SIZE
        );
        $statement->execute([$job->id, JobType::REBUILD_FILE_DEPENDENCIES, $lastChildJobId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fileIds = [];
        foreach ($rows as $row) {
            $lastChildJobId = max($lastChildJobId, (int)($row['id'] ?? 0));
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            $fileId = is_array($payload) ? (int)($payload['file_id'] ?? 0) : 0;
            if ($fileId > 0) {
                $fileIds[] = $fileId;
            }
        }

        if ($fileIds !== []) {
            $published = (new PdoDependencyPackageSummary($this->db))->rebuildFiles($fileIds);
            if (empty($published['available'])) {
                throw new RuntimeException('Dependency package summary projection is unavailable during game bulk publication.');
            }
            $summaryFiles += (int)($published['files'] ?? 0);
            $summaryRows += (int)($published['summary_rows'] ?? 0);
        }

        if (count($rows) === self::SUMMARY_BATCH_SIZE) {
            $state = $this->childState($job->id);
            $total = max(1, $state['completed']);
            $percent = 95 + min(3, (int)floor(($summaryFiles * 3) / $total));
            $context->defer(1, $this->workflowProgress(
                'dependency_game_summary',
                $percent,
                'Published dependency summaries for ' . $summaryFiles . '/' . $state['completed'] . ' file unit(s).',
                [
                    'children' => $state,
                    'summary_last_child_job_id' => $lastChildJobId,
                    'summary_files' => $summaryFiles,
                    'summary_rows' => $summaryRows,
                ]
            ));
        }

        return [
            'last_child_job_id' => $lastChildJobId,
            'files' => $summaryFiles,
            'rows' => $summaryRows,
        ];
    }

    /** @param array<string,mixed> $resume */
    private function planGameDependencyUnits(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $gameId,
        int $offset,
        array $resume
    ): void {
        $snapshotMaxId = max(0, (int)($resume['snapshot_max_file_id'] ?? 0));
        $lastPackage = (string)($resume['plan_last_package_name'] ?? '');
        $lastFileId = max(0, (int)($resume['plan_last_file_id'] ?? 0));
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        $firstPage = $snapshotMaxId < 1;

        if ($firstPage) {
            $snapshotMaxId = (int)($this->one(
                'SELECT COALESCE(MAX(id),0) max_id FROM ue_files WHERE game_id=? AND scan_status="verified"',
                [$gameId]
            )['max_id'] ?? 0);
        }
        if ($snapshotMaxId < 1) {
            $context->checkpoint($this->workflowProgress('dependency_game_wait', 5, 'No verified files require dependency rebuild.'));
            return;
        }

        if ($firstPage) {
            $sql = 'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" AND id<=? '
                . 'ORDER BY package_name,id LIMIT ' . self::PLAN_BATCH_SIZE;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
            $statement = $this->db->prepare($sql);
            $statement->execute([$gameId, $snapshotMaxId]);
        } else {
            $statement = $this->db->prepare(
                'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" AND id<=? '
                . 'AND (package_name>? OR (package_name=? AND id>?)) '
                . 'ORDER BY package_name,id LIMIT ' . self::PLAN_BATCH_SIZE
            );
            $statement->execute([$gameId, $snapshotMaxId, $lastPackage, $lastPackage, $lastFileId]);
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $queue = new PdoJobQueue($this->db);
        $units = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['id'];
            $units[] = [
                'payload' => [
                    'file_id' => $fileId,
                    'workflow_parent_job_id' => $job->id,
                    'workflow_defer_game_stats' => true,
                    'workflow_defer_dependency_summary' => true,
                ],
                'workflow_unit_key' => 'dependency:' . $fileId,
            ];
        }

        if ($units !== []) {
            $queue->enqueueWorkflowUnits(
                $job->queue,
                JobType::REBUILD_FILE_DEPENDENCIES,
                $units,
                30,
                null,
                (int)($job->payload['requested_by'] ?? 0) ?: null,
                3,
                $job->id
            );
            $planned += count($units);
            $lastRow = $rows[array_key_last($rows)];
            $lastPackage = (string)$lastRow['package_name'];
            $lastFileId = (int)$lastRow['id'];
        }

        $progress = $this->workflowProgress('dependency_game_plan', 3,
            'Planned ' . $planned . ' durable dependency file unit(s).', [
                'snapshot_max_file_id' => $snapshotMaxId,
                'plan_last_package_name' => $lastPackage,
                'plan_last_file_id' => $lastFileId,
                'planned_units' => $planned,
            ]);
        if (count($rows) === self::PLAN_BATCH_SIZE) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->workflowProgress(
            'dependency_game_wait',
            5,
            'Planned ' . $planned . ' durable dependency file unit(s); waiting for workers.',
            ['planned_units' => $planned]
        ));
    }

    private function ensureCompactMetadataReady(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $fileId,
        string $originalName
    ): void {
        $repair = $this->metadataRepairChild($job->id);
        if ($this->compactMetadataPhysicallyPresent($fileId) && $repair === null) {
            return;
        }

        $requestedBy = max(0, (int)($job->payload['requested_by'] ?? 0));
        if ($repair === null) {
            $repairJobId = (new PdoJobQueue($this->db))->enqueue(
                $job->queue,
                JobType::REPAIR_COMPACT_METADATA_FILE,
                [
                    'file_id' => $fileId,
                    'requested_by' => $requestedBy > 0 ? $requestedBy : null,
                    'recovery_for_dependency_job_id' => $job->id,
                ],
                10,
                null,
                null,
                $requestedBy > 0 ? $requestedBy : null,
                3,
                $job->id,
                'metadata-repair'
            );
            $context->defer(1, [
                'stage' => 'metadata_repair_wait',
                'done' => 0,
                'total' => 4,
                'percent' => 2,
                'file_id' => $fileId,
                'metadata_repair_job_id' => $repairJobId,
                'message' => 'Compact metadata is missing or unreadable for '
                    . ($originalName !== '' ? $originalName : ('file #' . $fileId))
                    . '; queued repair job #' . $repairJobId . ' from the authoritative stored package.',
            ]);
        }

        $repairId = (int)($repair['id'] ?? 0);
        $status = strtolower(trim((string)($repair['status'] ?? 'queued')));
        if (in_array($status, ['failed', 'dead_letter', 'cancelled'], true)) {
            $error = trim((string)($repair['last_error'] ?? ''));
            $context->defer(30, [
                'stage' => 'metadata_repair_wait',
                'done' => 0,
                'total' => 4,
                'percent' => 2,
                'file_id' => $fileId,
                'metadata_repair_job_id' => $repairId,
                'metadata_repair_status' => $status,
                'message' => 'Compact metadata repair job #' . $repairId . ' is ' . $status
                    . ($error !== '' ? ': ' . mb_substr($error, 0, 500, 'UTF-8') : '.')
                    . ' Restart that repair child; this dependency job will resume after it succeeds.',
            ]);
        }

        if ($status !== 'completed') {
            $progress = json_decode((string)($repair['progress_json'] ?? ''), true);
            $repairPercent = is_array($progress)
                ? max(0, min(100, (int)($progress['percent'] ?? 0)))
                : 0;
            $context->defer(2, [
                'stage' => 'metadata_repair_wait',
                'done' => 0,
                'total' => 4,
                'percent' => min(69, 2 + (int)floor($repairPercent * 0.6)),
                'file_id' => $fileId,
                'metadata_repair_job_id' => $repairId,
                'metadata_repair_status' => $status,
                'message' => 'Waiting for compact metadata repair job #' . $repairId
                    . ' (' . $status . ', ' . $repairPercent . '%).',
            ]);
        }

        if (!VerifiedCompactMetadataHealth::healthy($this->db, $this->config, $fileId)) {
            throw new RuntimeException(
                'Compact metadata repair job #' . $repairId
                . ' completed, but format-2 metadata is still missing or unreadable for file #' . $fileId . '.'
            );
        }

        $context->checkpoint([
            'stage' => 'metadata_repair_complete',
            'done' => 0,
            'total' => 4,
            'percent' => 5,
            'file_id' => $fileId,
            'metadata_repair_job_id' => $repairId,
            'message' => 'Compact metadata repair job #' . $repairId
                . ' completed; resuming dependency rebuild.',
        ]);
    }

    private function compactMetadataPhysicallyPresent(int $fileId): bool
    {
        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact dependency rebuilding.');
        }

        $statement = $this->db->prepare(
            'SELECT f.game_id,m.format_version,m.codec,m.compressed_size FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id WHERE f.id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (int)($row['format_version'] ?? 0) !== BlockedCompressedMetadataContainer::FORMAT_VERSION
            || (int)($row['codec'] ?? 0) !== BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP) {
            return false;
        }

        $path = BlockedCompressedMetadataContainer::path(
            $storageRoot,
            (int)$row['game_id'],
            $fileId
        );
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return false;
        }
        $size = @filesize($path);
        return $size !== false && (int)$size === (int)($row['compressed_size'] ?? -1);
    }

    /** @return array<string,mixed>|null */
    private function metadataRepairChild(int $parentJobId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,status,last_error,progress_json,result_json FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key="metadata-repair" LIMIT 1'
        );
        $statement->execute([$parentJobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function isPakDependencyWorkflow(ClaimedJob $job): bool
    {
        if ($job->parentJobId === null || $job->parentJobId < 1 || $job->workflowUnitKey !== 'dependencies') {
            return false;
        }
        $statement = $this->db->prepare('SELECT job_type FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$job->parentJobId]);
        return (string)($statement->fetchColumn() ?: '') === JobType::IMPORT_STAGED_PAK;
    }

    private function isLegacyPakDependencyFileUnit(ClaimedJob $job): bool
    {
        if ($job->parentJobId === null || $job->parentJobId < 1
            || !str_starts_with((string)($job->workflowUnitKey ?? ''), 'dependency:')) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT pak.job_type FROM ue_background_jobs legacy '
            . 'JOIN ue_background_jobs pak ON pak.id=legacy.parent_job_id '
            . 'WHERE legacy.id=? AND legacy.job_type=? AND legacy.workflow_unit_key="dependencies" LIMIT 1'
        );
        $statement->execute([$job->parentJobId, JobType::REBUILD_GAME_DEPENDENCIES]);
        return (string)($statement->fetchColumn() ?: '') === JobType::IMPORT_STAGED_PAK;
    }

    private function cancelQueuedLegacyChildren(int $parentJobId): int
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
            . 'cancel_requested_at=COALESCE(cancel_requested_at,?),' 
            . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" '
            . 'THEN "Superseded by targeted PAK dependency refresh." ELSE cancel_reason END,'
            . 'completed_at=?,updated_at=? WHERE parent_job_id=? AND status="queued" '
            . 'AND workflow_unit_key LIKE "dependency:%"'
        );
        $statement->execute([$timestamp, $timestamp, $timestamp, $parentJobId]);
        return max(0, $statement->rowCount());
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId, string $prefix = 'dependency:'): array
    {
        return (new PdoWorkflowChildStateQuery($this->db))->fetch($parentJobId, $prefix);
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = (int)($payload[$key] ?? 0);
        if ($value < 1) {
            throw new RuntimeException('A positive ' . $key . ' is required.');
        }
        return $value;
    }

    /** @param list<mixed> $args @return array<string,mixed>|null */
    private function one(string $sql, array $args): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<int> $fileIds @return array<string,int> */
    private function stats(array $fileIds): array
    {
        if ($fileIds === []) {
            return ['total' => 0, 'resolved' => 0, 'missing' => 0, 'package_only' => 0, 'common' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $statement = $this->db->prepare(
            'SELECT COUNT(*) total,SUM(status="resolved") resolved,SUM(status="missing") missing,'
            . 'SUM(status="package_only") package_only,SUM(status="common") common '
            . 'FROM ' . PdoDependencyReadSource::sql($this->db) . ' dependencies '
            . 'WHERE dependencies.file_id IN (' . $placeholders . ')'
        );
        $statement->execute($fileIds);
        return $this->normalizeStats($statement->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,int> */
    private function statsForGame(int $gameId): array
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) total,SUM(d.status="resolved") resolved,SUM(d.status="missing") missing,'
            . 'SUM(d.status="package_only") package_only,SUM(d.status="common") common '
            . 'FROM ' . PdoDependencyReadSource::sql($this->db) . ' d '
            . 'JOIN ue_files f ON f.id=d.file_id WHERE f.game_id=? AND f.scan_status="verified"'
        );
        $statement->execute([$gameId]);
        return $this->normalizeStats($statement->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $row @return array<string,int> */
    private function normalizeStats(array $row): array
    {
        return [
            'total' => (int)($row['total'] ?? 0),
            'resolved' => (int)($row['resolved'] ?? 0),
            'missing' => (int)($row['missing'] ?? 0),
            'package_only' => (int)($row['package_only'] ?? 0),
            'common' => (int)($row['common'] ?? 0),
        ];
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
