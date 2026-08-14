<?php
/**
 * Durable projection reconciliation workflow.
 *
 * Provider/source projection preparation stays in the parent. Potentially large
 * affected dependency-owner work is split into independent per-file children so
 * successful owners are never replayed after a retry. The parent releases its
 * worker while children run and performs bulk summary/stat publication only once
 * every child has completed successfully.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;

final class CatalogProjectionReconciliationJobHandler implements JobHandler
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
        return in_array(
            $jobType,
            [JobType::RECONCILE_CATALOG_PROJECTIONS, JobType::RECONCILE_CATALOG_PROJECTION_FILE],
            true
        );
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($job->type === JobType::RECONCILE_CATALOG_PROJECTION_FILE) {
            return $this->reconcileFileUnit($job, $context);
        }
        return $this->coordinate($job, $context);
    }

    /** @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context): array
    {
        [$fileId, $file, $gameIds, $packageNames] = $this->reconciliationContext($job->payload);
        if ($fileId < 1 && $gameIds === []) {
            throw new \RuntimeException('Projection reconciliation requires a file or game context.');
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'projection_prepare';
        }

        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $legacyStage = (string)($resume['stage'] ?? '');
            $stage = $legacyStage === 'game_stats' ? 'projection_finalize' : 'projection_prepare';
            $resume = [];
        }

        if ($stage === 'projection_prepare') {
            $providers = new PdoPackageProviderRepository($this->db);
            $summaries = new PdoDependencyPackageSummary($this->db);
            $summaryRows = 0;
            if ($fileId > 0) {
                $providers->reconcileFile($fileId);
                $summary = $summaries->rebuildFile($fileId);
                $summaryRows = (int)($summary['summary_rows'] ?? 0);
            }
            $context->checkpoint($this->progress(
                'projection_plan',
                10,
                'Provider projections are ready; planning independently recoverable dependency-owner units.',
                [
                    'file_id' => $fileId,
                    'game_ids' => $gameIds,
                    'package_names' => $packageNames,
                    'dependency_summary_rows' => $summaryRows,
                    'plan_last_file_id' => 0,
                    'planned_units' => 0,
                ]
            ));
            $resume = $context->resumeProgress();
            $stage = 'projection_plan';
        }

        if ($stage === 'projection_plan') {
            $this->planUnits($job, $context, $gameIds, $packageNames, $fileId, $resume);
            $stage = 'projection_wait';
        }

        if ($stage === 'projection_wait') {
            $state = $this->childState($job->id);
            $total = max(1, $state['total']);
            $percent = 15 + (int)floor(($state['completed'] * 65) / $total);
            $problems = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
            if ($problems > 0) {
                $context->defer(30, $this->progress(
                    'projection_wait',
                    min(80, $percent),
                    'Projection reconciliation is waiting on ' . $problems . ' failed/cancelled file unit(s). '
                        . 'Restart only those units; ' . $state['completed'] . ' successful file unit(s) are retained.',
                    ['children' => $state]
                ));
            }
            if (($state['queued'] + $state['running']) > 0) {
                $context->defer(2, $this->progress(
                    'projection_wait',
                    min(80, $percent),
                    'Projection file units: ' . $state['completed'] . '/' . $state['total']
                        . ' complete, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                    ['children' => $state]
                ));
            }
            $context->checkpoint($this->progress(
                'projection_finalize',
                82,
                'All projection dependency-owner units completed; publishing summaries and cached game statistics.',
                ['children' => $state]
            ));
            $stage = 'projection_finalize';
        }

        if ($stage !== 'projection_finalize') {
            throw new \RuntimeException('Unknown projection reconciliation workflow stage: ' . $stage);
        }

        $aggregate = $this->aggregateUnitResults($job->id);
        $summaries = new PdoDependencyPackageSummary($this->db);
        $summaryRows = 0;
        if ($fileId > 0) {
            $sourceSummary = $summaries->rebuildFile($fileId);
            $summaryRows = (int)($sourceSummary['summary_rows'] ?? 0);
        }

        $summaryFilesRefreshed = 0;
        if ($aggregate['changed_file_ids'] !== []) {
            $context->checkpoint($this->progress(
                'projection_finalize',
                88,
                'Bulk-refreshing dependency summaries for ' . count($aggregate['changed_file_ids'])
                    . ' changed owner file(s).'
            ));
            $bulkSummary = $summaries->rebuildFiles($aggregate['changed_file_ids']);
            $summaryFilesRefreshed = (int)($bulkSummary['files'] ?? 0);
        }

        $context->checkpoint($this->progress(
            'projection_finalize',
            94,
            'Refreshing cached game counters.',
            ['game_ids' => $gameIds]
        ));
        $stats = new PdoGameCatalogStats($this->db);
        $statsRefreshed = 0;
        foreach ($gameIds as $gameId) {
            if ($stats->rebuildGame($gameId) !== null) {
                $statsRefreshed++;
            }
        }

        $children = $this->childState($job->id);
        $context->checkpoint($this->progress(
            'complete',
            100,
            'Catalogue projections reconciled.',
            [
                'affected_files' => $children['total'],
                'changed_files' => count($aggregate['changed_file_ids']),
                'no_op_files' => $aggregate['no_op_files'],
                'children' => $children,
            ]
        ));

        return [
            'operation' => 'reconcile_catalog_projections',
            'workflow_version' => self::WORKFLOW_VERSION,
            'file_id' => $fileId,
            'file_exists' => $file !== null,
            'game_ids' => $gameIds,
            'package_names' => $packageNames,
            'dependency_summary_rows' => $summaryRows,
            'affected_files' => $children['total'],
            'processed_files' => $aggregate['processed_files'],
            'dependency_files_changed' => count($aggregate['changed_file_ids']),
            'compact_no_op_files' => $aggregate['no_op_files'],
            'targeted_imports_processed' => $aggregate['targeted_imports'],
            'summary_files_refreshed' => $summaryFilesRefreshed,
            'stats_refreshed' => $statsRefreshed,
            'failure_count' => 0,
            'failures' => [],
            'failures_truncated' => false,
            'children' => $children,
        ];
    }

    /** @return array<string,mixed> */
    private function reconcileFileUnit(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = (int)($job->payload['affected_file_id'] ?? 0);
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Projection file unit requires a positive affected_file_id.');
        }
        $packageNames = $this->packageNames((array)($job->payload['package_names'] ?? []));
        if ($packageNames === []) {
            throw new \InvalidArgumentException('Projection file unit requires at least one package name.');
        }

        $context->checkpoint([
            'stage' => 'projection_file',
            'done' => 0,
            'total' => 1,
            'percent' => 1,
            'file_id' => $fileId,
            'message' => 'Reconciling targeted dependencies for file #' . $fileId . '.',
        ]);

        $result = (new PdoCatalogDependencyRebuilder($this->db, $this->config))->rebuildForPackages(
            $fileId,
            $packageNames,
            false
        );
        $changed = max(0, (int)($result['dependencies_changed'] ?? 0));
        $targeted = max(0, (int)($result['imports_processed'] ?? 0));
        $importsTotal = max($targeted, (int)($result['imports_total'] ?? $targeted));
        $skipped = !empty($result['skipped_missing_file']);

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'file_id' => $fileId,
            'status' => $skipped ? 'skipped' : 'completed',
            'message' => $skipped
                ? 'Projection dependency owner no longer exists; skipped file #' . $fileId . '.'
                : 'Projection file #' . $fileId . ' reconciled: targeted imports=' . $targeted . '/'
                    . $importsTotal . ', changes=' . $changed . '.',
        ]);

        return [
            'operation' => 'reconcile_catalog_projection_file',
            'affected_file_id' => $fileId,
            'skipped_missing_file' => $skipped,
            'imports_processed' => $targeted,
            'imports_total' => $importsTotal,
            'dependencies_changed' => $changed,
            'container_rewritten' => !empty($result['container_rewritten']),
        ];
    }

    /**
     * @param list<int> $gameIds
     * @param list<string> $packageNames
     * @param array<string,mixed> $resume
     */
    private function planUnits(
        ClaimedJob $job,
        JobExecutionContext $context,
        array $gameIds,
        array $packageNames,
        int $excludeFileId,
        array $resume
    ): void {
        $affected = $this->affectedFileIds(
            $gameIds,
            $packageNames,
            $excludeFileId,
            (new PdoDependencyPackageSummary($this->db))->available()
        );
        $lastFileId = (int)($resume['plan_last_file_id'] ?? 0);
        $planned = (int)($resume['planned_units'] ?? 0);
        if ((string)($resume['stage'] ?? '') !== 'projection_plan') {
            $lastFileId = 0;
            $planned = 0;
        }

        $ids = [];
        foreach ($affected as $id) {
            if ($id > $lastFileId) {
                $ids[] = $id;
                if (count($ids) >= self::PLAN_BATCH_SIZE) {
                    break;
                }
            }
        }

        $queue = new PdoJobQueue($this->db);
        $createdBy = isset($job->payload['requested_by']) && (int)$job->payload['requested_by'] > 0
            ? (int)$job->payload['requested_by']
            : null;
        foreach ($ids as $affectedFileId) {
            $queue->enqueue(
                $job->queue,
                JobType::RECONCILE_CATALOG_PROJECTION_FILE,
                [
                    'affected_file_id' => $affectedFileId,
                    'package_names' => $packageNames,
                    'workflow_parent_job_id' => $job->id,
                ],
                50,
                null,
                null,
                $createdBy,
                3,
                $job->id,
                'affected:' . $affectedFileId
            );
            $lastFileId = $affectedFileId;
            $planned++;
        }

        $hasMore = false;
        foreach ($affected as $id) {
            if ($id > $lastFileId) {
                $hasMore = true;
                break;
            }
        }
        $progress = $this->progress(
            'projection_plan',
            12,
            'Planned ' . $planned . '/' . count($affected) . ' durable projection file unit(s).',
            [
                'plan_last_file_id' => $lastFileId,
                'planned_units' => $planned,
                'affected_total' => count($affected),
            ]
        );
        if ($hasMore) {
            $context->defer(1, $progress);
        }

        $context->checkpoint($this->progress(
            'projection_wait',
            15,
            'Planned ' . $planned . ' durable projection file unit(s); waiting for workers.',
            ['planned_units' => $planned, 'affected_total' => count($affected)]
        ));
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId): array
    {
        return (new PdoWorkflowChildStateQuery($this->db))->fetch($parentJobId, 'affected:');
    }

    /** @return array{processed_files:int,no_op_files:int,targeted_imports:int,changed_file_ids:list<int>} */
    private function aggregateUnitResults(int $parentJobId): array
    {
        $processed = 0;
        $noOp = 0;
        $targetedImports = 0;
        $changed = [];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "affected:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result) || !empty($result['skipped_missing_file'])) {
                continue;
            }
            $processed++;
            $targetedImports += max(0, (int)($result['imports_processed'] ?? 0));
            $changes = max(0, (int)($result['dependencies_changed'] ?? 0));
            if ($changes > 0) {
                $fileId = (int)($result['affected_file_id'] ?? 0);
                if ($fileId > 0) {
                    $changed[$fileId] = $fileId;
                }
            } else {
                $noOp++;
            }
        }
        ksort($changed, SORT_NUMERIC);
        return [
            'processed_files' => $processed,
            'no_op_files' => $noOp,
            'targeted_imports' => $targetedImports,
            'changed_file_ids' => array_values($changed),
        ];
    }

    /** @param array<string,mixed> $payload @return array{0:int,1:array<string,mixed>|null,2:list<int>,3:list<string>} */
    private function reconciliationContext(array $payload): array
    {
        $fileId = max(0, (int)($payload['file_id'] ?? 0));
        $gameIds = $this->positiveIds((array)($payload['game_ids'] ?? []));
        $packageNames = $this->packageNames((array)($payload['package_names'] ?? []));
        $file = null;

        if ($fileId > 0) {
            $statement = $this->db->prepare('SELECT id,game_id,package_name,scan_status FROM ue_files WHERE id=?');
            $statement->execute([$fileId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $file = is_array($row) ? $row : null;
        }
        if ($file !== null) {
            $currentGameId = (int)($file['game_id'] ?? 0);
            if ($currentGameId > 0) {
                $gameIds[] = $currentGameId;
            }
            $packageNames[] = (string)($file['package_name'] ?? '');
            $aliasStatement = $this->db->prepare(
                'SELECT package_name FROM ue_file_package_aliases WHERE file_id=? ORDER BY id'
            );
            $aliasStatement->execute([$fileId]);
            foreach ($aliasStatement->fetchAll(PDO::FETCH_COLUMN) as $aliasName) {
                $packageNames[] = (string)$aliasName;
            }
        }

        return [$fileId, $file, $this->positiveIds($gameIds), $this->packageNames($packageNames)];
    }

    /** @param list<int> $gameIds @param list<string> $packageNames @return list<int> */
    private function affectedFileIds(array $gameIds, array $packageNames, int $excludeFileId, bool $summaryAvailable): array
    {
        if ($gameIds === [] || $packageNames === []) {
            return [];
        }
        $ids = [];
        foreach ($gameIds as $gameId) {
            foreach (array_chunk($packageNames, 100) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                if ($summaryAvailable) {
                    $sql = 'SELECT DISTINCT s.file_id FROM ue_dependency_package_summaries s '
                        . 'JOIN ue_files f ON f.id=s.file_id '
                        . 'WHERE s.game_id=? AND s.required_package IN (' . $placeholders . ') '
                        . 'AND f.scan_status="verified"';
                } else {
                    $sql = 'SELECT DISTINCT d.file_id FROM ' . PdoDependencyReadSource::sql($this->db) . ' d '
                        . 'JOIN ue_files f ON f.id=d.file_id '
                        . 'WHERE f.game_id=? AND d.required_package IN (' . $placeholders . ') '
                        . 'AND f.scan_status="verified"';
                }
                $args = [$gameId, ...$chunk];
                if ($excludeFileId > 0) {
                    $sql .= $summaryAvailable ? ' AND s.file_id<>?' : ' AND d.file_id<>?';
                    $args[] = $excludeFileId;
                }
                $statement = $this->db->prepare($sql);
                $statement->execute($args);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $ids[(int)$id] = true;
                }
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_map('intval', array_keys($ids));
    }

    /** @param array<mixed> $values @return list<int> */
    private function positiveIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<mixed> $values @return list<string> */
    private function packageNames(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
            $name = trim((string)$value);
            if ($name !== '') {
                $names[mb_strtolower($name, 'UTF-8')] = $name;
            }
        }
        ksort($names);
        return array_values($names);
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
