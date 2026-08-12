<?php
/**
 * Rebuilds dependency projections. Whole-game work is a durable coordinator
 * over existing per-file dependency jobs so successful files are never replayed.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

final class CatalogDependencyRefreshJobHandler implements JobHandler
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
        return in_array($jobType, [
            JobType::REBUILD_GAME_DEPENDENCIES,
            JobType::REBUILD_FILE_DEPENDENCIES,
        ], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        return $job->type === JobType::REBUILD_GAME_DEPENDENCIES
            ? $this->rebuildGame($job, $context)
            : $this->rebuildFile($job, $context);
    }

    /** @return array<string,mixed> */
    private function rebuildFile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->positiveInt($job->payload, 'file_id');
        $file = $this->one(
            'SELECT id,game_id,package_name,original_name FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if ($file === null) {
            // Workflow children are allowed to become no-ops when another valid
            // maintenance action removed the file after the parent snapshot.
            if (!empty($job->payload['workflow_parent_job_id'])) {
                return [
                    'operation' => 'rebuild_file_dependencies',
                    'file_id' => $fileId,
                    'status' => 'already_removed',
                    'message' => 'Verified file was removed after workflow planning; no dependency work remains.',
                ];
            }
            throw new \RuntimeException('Verified file no longer exists: ' . $fileId);
        }

        \scanner_rebuild_dependencies(
            $this->db,
            $this->config,
            $fileId,
            static function (array $progress) use ($context, $fileId): void {
                $progress['file_id'] = $fileId;
                $context->heartbeatIfDue($progress);
            },
            0,
            70,
            'Refreshing file dependency links'
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

        $context->checkpoint([
            'stage' => 'dependency_summary',
            'done' => 2,
            'total' => 4,
            'percent' => 82,
            'message' => 'Rebuilding the file dependency summary.',
            'file_id' => $fileId,
        ]);
        $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);

        $affectedJobId = 0;
        $postImport = !empty($job->payload['post_import']);
        if ($postImport) {
            $context->checkpoint([
                'stage' => 'affected_detection',
                'done' => 3,
                'total' => 4,
                'percent' => 90,
                'message' => 'Checking whether existing files reference the imported package.',
                'file_id' => $fileId,
                'dependency_summary_rows' => (int)$summary['summary_rows'],
            ]);
            $affectedJobId = CatalogAffectedDependencyRefreshCoordinator::enqueueIfNeeded(
                $this->db,
                (int)$file['game_id'],
                $fileId,
                (string)$file['package_name'],
                true,
                true
            );
        }

        $deferGameStats = !empty($job->payload['workflow_defer_game_stats']);
        $gameStats = null;
        if ($affectedJobId < 1 && !$deferGameStats) {
            $context->checkpoint([
                'stage' => 'game_stats',
                'done' => 4,
                'total' => 4,
                'percent' => 95,
                'message' => 'Refreshing cached game counters.',
                'file_id' => $fileId,
                'dependency_summary_rows' => (int)$summary['summary_rows'],
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
                'dependency_summary_rows' => (int)$summary['summary_rows'],
            ]);
        } else {
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 4,
                'total' => 4,
                'percent' => 100,
                'message' => 'Dependency file unit complete; parent workflow will publish game counters once.',
                'file_id' => $fileId,
                'dependency_summary_rows' => (int)$summary['summary_rows'],
            ]);
        }

        return [
            'operation' => 'rebuild_file_dependencies',
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'post_import' => $postImport,
            'package_provider_reconciled' => true,
            'dependency_summary_rows' => (int)$summary['summary_rows'],
            'affected_job_id' => $affectedJobId,
            'game_stats_refreshed' => $gameStats !== null,
            'stats' => $deferGameStats ? null : $this->stats([$fileId]),
        ];
    }

    /** @return array<string,mixed> */
    private function rebuildGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $offset = max(0, (int)($job->payload['offset'] ?? 0));
        $game = $this->one('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new \RuntimeException('Game no longer exists: ' . $gameId);
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
            $context->checkpoint($this->workflowProgress(
                'dependency_game_finalize',
                95,
                'All dependency file units completed; refreshing cached game counters.',
                ['children' => $state]
            ));
            $stage = 'dependency_game_finalize';
        }

        if ($stage !== 'dependency_game_finalize') {
            throw new \RuntimeException('Unknown game dependency workflow stage: ' . $stage);
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
            'game_stats_refreshed' => $gameStats !== null,
            'children' => $state,
            'stats' => $this->statsForGame($gameId),
            'message' => 'Game dependency rebuild complete: ' . $state['completed'] . ' durable file unit(s).',
        ];
        $context->checkpoint($this->workflowProgress('complete', 100, (string)$result['message'], $result));
        return $result;
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
        foreach ($rows as $row) {
            $fileId = (int)$row['id'];
            $lastPackage = (string)$row['package_name'];
            $lastFileId = $fileId;
            $queue->enqueue(
                $job->queue,
                JobType::REBUILD_FILE_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'workflow_parent_job_id' => $job->id,
                    'workflow_defer_game_stats' => true,
                ],
                30,
                null,
                null,
                (int)($job->payload['requested_by'] ?? 0) ?: null,
                3,
                $job->id,
                'dependency:' . $fileId
            );
            $planned++;
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

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId): array
    {
        $state = ['total' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0, 'cancelled' => 0];
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "dependency:%" GROUP BY status'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            $count = (int)$row['c'];
            $state['total'] += $count;
            if (array_key_exists($status, $state)) {
                $state[$status] += $count;
            }
        }
        return $state;
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = (int)($payload[$key] ?? 0);
        if ($value < 1) {
            throw new \RuntimeException('A positive ' . $key . ' is required.');
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
