<?php
/**
 * Durable targeted dependency refresh workflow after a provider becomes available.
 *
 * A provider change can affect tens of thousands of existing files. The root
 * workflow therefore plans bounded file batches rather than one durable queue row
 * per affected file. Individual failures are split back out into retry rows by
 * CatalogAffectedDependencyBatchService so one bad package never blocks its batch.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

final class CatalogAffectedDependencyRefreshJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 4;
    private const LEGACY_COMPACT_LIMIT = 5000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REBUILD_AFFECTED_DEPENDENCIES;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceFileId = (int)($job->payload['file_id'] ?? 0);
        if ($sourceFileId < 1) {
            throw new RuntimeException('Affected dependency refresh requires a positive file_id.');
        }

        $source = $this->sourceFile($sourceFileId);
        if ($source === null) {
            return $this->skipMissingSource($sourceFileId, $context);
        }

        if ((int)($job->payload['affected_file_id'] ?? 0) > 0) {
            return $this->handleFileUnit($job, $context, $source);
        }

        // Version-4 batches and pre-existing legacy batch rows share this shape.
        if (array_key_exists('affected_file_ids', $job->payload)) {
            return (new CatalogAffectedDependencyBatchService($this->db, $this->config))
                ->run($job, $context, $source);
        }

        return $this->coordinate($job, $context, $source);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context, array $source): array
    {
        $sourceFileId = (int)$source['id'];
        $gameId = (int)$source['game_id'];
        $packageName = (string)$source['package_name'];
        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        $resumeVersion = (int)($resume['workflow_version'] ?? 0);

        // Version 3 already has a correct source-preparation contract and may
        // have tens of thousands of completed one-file children. Preserve those
        // rows rather than replaying them. Older workflow shapes are rebuilt.
        if ($resumeVersion > 0 && $resumeVersion < 3) {
            $stage = 'affected_prepare';
            $resume = [];
            $resumeVersion = 0;
        }
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'affected_prepare';
        }

        if ($stage === 'affected_prepare') {
            $rebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);
            $rebuilder->rebuild(
                $sourceFileId,
                static function (array $progress) use ($context, $packageName): void {
                    $context->heartbeatIfDue([
                        'workflow_version' => self::WORKFLOW_VERSION,
                        'stage' => 'affected_prepare',
                        'done' => 0,
                        'total' => 1,
                        'percent' => 2,
                        'message' => 'Preparing source dependencies for ' . $packageName
                            . (!empty($progress['message']) ? ' — ' . (string)$progress['message'] : ''),
                    ]);
                },
                0,
                100,
                'Preparing source dependency links',
                false
            );
            (new PdoPackageProviderRepository($this->db))->reconcileFile($sourceFileId);
            $sourceSummary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($sourceFileId);
            $resume = $this->progress(
                'affected_plan',
                5,
                'Source provider is authoritative; planning bounded affected-file batches.',
                [
                    'package_name' => $packageName,
                    'dependency_summary_rows' => (int)($sourceSummary['summary_rows'] ?? 0),
                ]
            );
            $context->checkpoint($resume);
            $stage = 'affected_plan';
            $resumeVersion = self::WORKFLOW_VERSION;
        }

        if ($stage === 'affected_plan') {
            $plan = $this->planBatchUnits($job, $context, $source);
            $resume = $this->progress(
                'affected_wait',
                10,
                'Planned ' . $plan['batches'] . ' bounded batch unit(s) for '
                    . $plan['total_files'] . ' affected file(s).',
                [
                    'package_name' => $packageName,
                    'affected_total' => $plan['total_files'],
                    'planned_batches' => $plan['batches'],
                ]
            );
            $context->checkpoint($resume);
            $stage = 'affected_wait';
            $resumeVersion = self::WORKFLOW_VERSION;
        }

        if ($stage === 'affected_wait') {
            $affectedTotal = max(0, (int)($resume['affected_total'] ?? 0));
            if ($affectedTotal < 1) {
                // Version-3 wait rows did not persist a file total separately.
                // Resolve it once before compacting their queued one-file units.
                $affectedTotal = count(CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
                    $this->db,
                    $gameId,
                    $sourceFileId,
                    $packageName
                ));
            }

            $compacted = $this->compactQueuedLegacyUnits($job, $source);
            $children = $this->childState($job->id);
            $files = $this->fileProgressState($job->id, $affectedTotal);
            $percent = 10 + (int)floor(($files['completed'] * 75) / max(1, $files['total']));

            if ($compacted['files'] > 0) {
                $context->defer(1, $this->progress(
                    'affected_wait',
                    min(85, $percent),
                    'Compacted ' . $compacted['files'] . ' queued one-file unit(s) into '
                        . $compacted['batches'] . ' batch unit(s). '
                        . $files['completed'] . '/' . $files['total'] . ' affected files complete.',
                    [
                        'package_name' => $packageName,
                        'affected_total' => $affectedTotal,
                        'children' => $children,
                        'file_state' => $files,
                    ]
                ));
            }

            $problems = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
            if ($problems > 0) {
                $context->defer(30, $this->progress(
                    'affected_wait',
                    min(85, $percent),
                    'Affected dependency workflow is waiting on ' . $problems . ' failed/cancelled unit(s). '
                        . 'Only those problem units need attention; completed file work is retained.',
                    [
                        'package_name' => $packageName,
                        'affected_total' => $affectedTotal,
                        'children' => $children,
                        'file_state' => $files,
                    ]
                ));
            }

            if (($children['queued'] + $children['running']) > 0) {
                $context->defer(2, $this->progress(
                    'affected_wait',
                    min(85, $percent),
                    'Affected files for ' . $packageName . ': ' . $files['completed'] . '/'
                        . $files['total'] . ' complete, ' . $files['pending'] . ' pending; '
                        . $children['running'] . ' worker unit(s) running, '
                        . $children['queued'] . ' durable unit(s) queued.',
                    [
                        'package_name' => $packageName,
                        'affected_total' => $affectedTotal,
                        'children' => $children,
                        'file_state' => $files,
                    ]
                ));
            }

            $context->checkpoint($this->progress(
                'affected_finalize',
                88,
                'All affected file work completed; publishing dependency summaries and game counters.',
                [
                    'package_name' => $packageName,
                    'affected_total' => $affectedTotal,
                    'children' => $children,
                    'file_state' => $files,
                ]
            ));
            $stage = 'affected_finalize';
            $resume = ['affected_total' => $affectedTotal];
        }

        if ($stage !== 'affected_finalize') {
            throw new RuntimeException('Unknown affected dependency workflow stage: ' . $stage);
        }

        $aggregate = $this->aggregateFileUnits($job->id);
        $summaryRows = 0;
        if ($aggregate['processed_file_ids'] !== []) {
            $context->checkpoint($this->progress(
                'affected_finalize',
                92,
                'Bulk-refreshing dependency summaries for ' . count($aggregate['processed_file_ids']) . ' file(s).',
                ['package_name' => $packageName]
            ));
            $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFiles($aggregate['processed_file_ids']);
            if (empty($summary['available'])) {
                throw new RuntimeException('Dependency package summary projection is unavailable after affected refresh.');
            }
            $summaryRows = (int)($summary['summary_rows'] ?? 0);
        }

        $context->checkpoint($this->progress(
            'affected_finalize',
            97,
            'Refreshing cached game counters after affected dependency work.',
            ['package_name' => $packageName, 'game_id' => $gameId]
        ));
        $gameStats = $this->refreshGameStats($gameId);
        $children = $this->childState($job->id);
        $affectedTotal = max(
            (int)($resume['affected_total'] ?? 0),
            $aggregate['processed_files'] + $aggregate['skipped_files']
        );

        $message = 'Affected dependency refresh complete for ' . $packageName . ': '
            . $aggregate['processed_files'] . ' processed, ' . $aggregate['skipped_files'] . ' skipped, '
            . $aggregate['dependencies_changed'] . ' dependency change(s).';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'package_name' => $packageName,
            'affected_total' => $affectedTotal,
            'children' => $children,
            'processed' => $aggregate['processed_files'],
            'skipped' => $aggregate['skipped_files'],
            'dependencies_changed' => $aggregate['dependencies_changed'],
        ]));

        return [
            'operation' => 'rebuild_affected_dependencies',
            'workflow_version' => self::WORKFLOW_VERSION,
            'mode' => 'coordinator',
            'file_id' => $sourceFileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$source['original_name'],
            'affected_files' => $affectedTotal,
            'processed_files' => $aggregate['processed_files'],
            'skipped_files' => $aggregate['skipped_files'],
            'imports_processed' => $aggregate['imports_processed'],
            'dependencies_changed' => $aggregate['dependencies_changed'],
            'containers_rewritten' => $aggregate['containers_rewritten'],
            'dependency_summary_rows' => $summaryRows,
            'game_stats_refreshed' => $gameStats !== null,
            'failure_count' => 0,
            'failures' => [],
            'children' => $children,
        ];
    }

    /**
     * Discover the affected file IDs once, skip work already represented by an
     * existing child row, then enqueue bounded batches for the remainder.
     *
     * @param array<string,mixed> $source
     * @return array{total_files:int,batches:int}
     */
    private function planBatchUnits(ClaimedJob $job, JobExecutionContext $context, array $source): array
    {
        $sourceFileId = (int)$source['id'];
        $gameId = (int)$source['game_id'];
        $packageName = (string)$source['package_name'];
        $affectedIds = CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $this->db,
            $gameId,
            $sourceFileId,
            $packageName
        );
        $covered = $this->plannedFileIdSet($job->id);
        $remaining = [];
        foreach ($affectedIds as $affectedFileId) {
            if (!isset($covered[$affectedFileId])) {
                $remaining[] = $affectedFileId;
            }
        }

        $queue = new PdoJobQueue($this->db);
        $chunks = array_chunk($remaining, CatalogAffectedDependencyBatchService::MAX_BATCH_SIZE);
        $plannedBatches = 0;
        foreach ($chunks as $index => $ids) {
            if ($ids === []) {
                continue;
            }
            $batchKey = $this->batchUnitKey($ids, 'planned');
            $queue->enqueue(
                $job->queue,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $sourceFileId,
                    'game_id' => $gameId,
                    'package_name' => $packageName,
                    'affected_file_ids' => $ids,
                    'workflow_parent_job_id' => $job->id,
                    'batch_number' => $index + 1,
                    'batch_size' => count($ids),
                ],
                40,
                null,
                null,
                null,
                3,
                $job->id,
                $batchKey
            );
            $plannedBatches++;
            $context->heartbeatIfDue($this->progress(
                'affected_plan',
                5 + (int)floor((5 * ($index + 1)) / max(1, count($chunks))),
                'Planning affected dependency batches: ' . ($index + 1) . '/' . count($chunks) . '.',
                [
                    'package_name' => $packageName,
                    'affected_total' => count($affectedIds),
                    'planned_batches' => $plannedBatches,
                ]
            ));
        }

        return [
            'total_files' => count($affectedIds),
            'batches' => $plannedBatches + $this->existingBatchCount($job->id),
        ];
    }

    /** @return array<int,true> */
    private function plannedFileIdSet(int $parentJobId): array
    {
        $set = [];
        $statement = $this->db->prepare(
            'SELECT payload_json FROM ue_background_jobs WHERE parent_job_id=? AND job_type=?'
        );
        $statement->execute([$parentJobId, JobType::REBUILD_AFFECTED_DEPENDENCIES]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $payload = json_decode((string)$json, true);
            if (!is_array($payload)) {
                continue;
            }
            $single = (int)($payload['affected_file_id'] ?? 0);
            if ($single > 0) {
                $set[$single] = true;
            }
            if (is_array($payload['affected_file_ids'] ?? null)) {
                foreach ($payload['affected_file_ids'] as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $set[$id] = true;
                    }
                }
            }
        }
        return $set;
    }

    private function existingBatchCount(int $parentJobId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "affected:batch:%"'
        );
        $statement->execute([$parentJobId]);
        return (int)$statement->fetchColumn();
    }

    /**
     * Convert untouched version-3 one-file rows into bounded batches without
     * touching completed/running/retried/error rows. The conversion is atomic per
     * page so work cannot disappear between DELETE and batch INSERT.
     *
     * @param array<string,mixed> $source
     * @return array{files:int,batches:int}
     */
    private function compactQueuedLegacyUnits(ClaimedJob $job, array $source): array
    {
        if ($this->db->inTransaction()) {
            return ['files' => 0, 'batches' => 0];
        }

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT id,payload_json FROM ue_background_jobs '
                . 'WHERE parent_job_id=? AND job_type=? AND status="queued" '
                . 'AND attempts=0 AND last_error IS NULL AND cancel_requested_at IS NULL '
                . 'AND workflow_unit_key LIKE "affected:%" '
                . 'AND workflow_unit_key NOT LIKE "affected:batch:%" '
                . 'AND workflow_unit_key NOT LIKE "affected:retry:%" '
                . 'ORDER BY id LIMIT ' . self::LEGACY_COMPACT_LIMIT . ' FOR UPDATE SKIP LOCKED'
            );
            $statement->execute([$job->id, JobType::REBUILD_AFFECTED_DEPENDENCIES]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                $this->db->commit();
                return ['files' => 0, 'batches' => 0];
            }

            $idsToDelete = [];
            $fileIds = [];
            foreach ($rows as $row) {
                $payload = json_decode((string)($row['payload_json'] ?? ''), true);
                $affectedFileId = is_array($payload) ? (int)($payload['affected_file_id'] ?? 0) : 0;
                if ($affectedFileId < 1) {
                    continue;
                }
                $idsToDelete[] = (int)$row['id'];
                $fileIds[$affectedFileId] = $affectedFileId;
            }

            if ($fileIds === []) {
                $this->db->commit();
                return ['files' => 0, 'batches' => 0];
            }

            $queue = new PdoJobQueue($this->db);
            $batchCount = 0;
            foreach (array_chunk(array_values($fileIds), CatalogAffectedDependencyBatchService::MAX_BATCH_SIZE) as $ids) {
                $queue->enqueue(
                    $job->queue,
                    JobType::REBUILD_AFFECTED_DEPENDENCIES,
                    [
                        'file_id' => (int)$source['id'],
                        'game_id' => (int)$source['game_id'],
                        'package_name' => (string)$source['package_name'],
                        'affected_file_ids' => $ids,
                        'workflow_parent_job_id' => $job->id,
                        'batch_size' => count($ids),
                        'compacted_from_legacy_units' => true,
                    ],
                    40,
                    null,
                    null,
                    null,
                    3,
                    $job->id,
                    $this->batchUnitKey($ids, 'migrated')
                );
                $batchCount++;
            }

            foreach (array_chunk($idsToDelete, 500) as $deleteIds) {
                $delete = $this->db->prepare(
                    'DELETE FROM ue_background_jobs WHERE parent_job_id=? AND status="queued" '
                    . 'AND id IN (' . implode(',', array_fill(0, count($deleteIds), '?')) . ')'
                );
                $delete->execute([$job->id, ...$deleteIds]);
            }

            $this->db->commit();
            return ['files' => count($fileIds), 'batches' => $batchCount];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int> $ids */
    private function batchUnitKey(array $ids, string $kind): string
    {
        $ids = array_values(array_map('intval', $ids));
        return 'affected:batch:' . $kind . ':'
            . substr(hash('sha256', implode(',', $ids)), 0, 24);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function handleFileUnit(ClaimedJob $job, JobExecutionContext $context, array $source): array
    {
        $sourceFileId = (int)$source['id'];
        $packageName = (string)$source['package_name'];
        $affectedFileId = (int)$job->payload['affected_file_id'];

        $context->checkpoint([
            'stage' => 'dependencies',
            'done' => 0,
            'total' => 1,
            'percent' => 1,
            'file_id' => $sourceFileId,
            'affected_file_id' => $affectedFileId,
            'package_name' => $packageName,
            'message' => 'Refreshing ' . $packageName . ' dependencies for affected file #' . $affectedFileId . '.',
        ]);

        $result = (new PdoCatalogDependencyRebuilder($this->db, $this->config))->rebuildForPackages(
            $affectedFileId,
            [$packageName],
            false
        );
        $skipped = !empty($result['skipped_missing_file']);
        $importsProcessed = max(0, (int)($result['imports_processed'] ?? 0));
        $dependenciesChanged = max(0, (int)($result['dependencies_changed'] ?? 0));

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'file_id' => $sourceFileId,
            'affected_file_id' => $affectedFileId,
            'package_name' => $packageName,
            'status' => $skipped ? 'skipped' : 'completed',
            'message' => $skipped
                ? 'Affected file #' . $affectedFileId . ' no longer exists; skipped.'
                : 'Affected file #' . $affectedFileId . ' refreshed: imports=' . $importsProcessed
                    . ', changes=' . $dependenciesChanged . '.',
        ]);

        return [
            'operation' => 'rebuild_affected_dependency_file',
            'mode' => 'file',
            'file_id' => $sourceFileId,
            'game_id' => (int)$source['game_id'],
            'package_name' => $packageName,
            'affected_file_id' => $affectedFileId,
            'skipped_missing_file' => $skipped,
            'imports_processed' => $importsProcessed,
            'dependencies_changed' => $dependenciesChanged,
            'container_rewritten' => !empty($result['container_rewritten']),
        ];
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId): array
    {
        $state = ['total' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0, 'cancelled' => 0];
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "affected:%" GROUP BY status'
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

    /** @return array{total:int,completed:int,pending:int,running_batches:int,queued_batches:int,retry_queued:int} */
    private function fileProgressState(int $parentJobId, int $affectedTotal): array
    {
        $completed = 0;

        $legacy = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE parent_job_id=? AND status="completed" '
            . 'AND workflow_unit_key LIKE "affected:%" '
            . 'AND workflow_unit_key NOT LIKE "affected:batch:%" '
            . 'AND workflow_unit_key NOT LIKE "affected:retry:%"'
        );
        $legacy->execute([$parentJobId]);
        $completed += (int)$legacy->fetchColumn();

        $runningBatches = 0;
        $queuedBatches = 0;
        $batches = $this->db->prepare(
            'SELECT status,progress_json,result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "affected:batch:%"'
        );
        $batches->execute([$parentJobId]);
        foreach ($batches->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            if ($status === 'running') {
                $runningBatches++;
            } elseif ($status === 'queued') {
                $queuedBatches++;
            }
            $json = $status === 'completed'
                ? (string)($row['result_json'] ?? '')
                : (string)($row['progress_json'] ?? '');
            $data = json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }
            if ($status === 'completed') {
                $completed += max(0, (int)($data['processed_files'] ?? 0));
                $completed += max(0, (int)($data['skipped_files'] ?? 0));
            } elseif ($status === 'running') {
                $completed += max(0, (int)($data['completed_files'] ?? 0));
            }
        }

        $retry = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "affected:retry:%" GROUP BY status'
        );
        $retry->execute([$parentJobId]);
        $retryQueued = 0;
        foreach ($retry->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            $count = (int)$row['c'];
            if ($status === 'completed') {
                $completed += $count;
            } elseif ($status === 'queued') {
                $retryQueued += $count;
            }
        }

        $total = max($affectedTotal, $completed);
        $completed = min($total, $completed);
        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => max(0, $total - $completed),
            'running_batches' => $runningBatches,
            'queued_batches' => $queuedBatches,
            'retry_queued' => $retryQueued,
        ];
    }

    /** @return array{processed_files:int,skipped_files:int,imports_processed:int,dependencies_changed:int,containers_rewritten:int,processed_file_ids:list<int>} */
    private function aggregateFileUnits(int $parentJobId): array
    {
        $processedIds = [];
        $skippedIds = [];
        $imports = 0;
        $changed = 0;
        $rewritten = 0;
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "affected:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }

            if (is_array($result['processed_file_ids'] ?? null)) {
                foreach ($result['processed_file_ids'] as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $processedIds[$id] = $id;
                    }
                }
            }
            if (is_array($result['skipped_file_ids'] ?? null)) {
                foreach ($result['skipped_file_ids'] as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $skippedIds[$id] = $id;
                    }
                }
            }

            $affectedFileId = (int)($result['affected_file_id'] ?? 0);
            if ($affectedFileId > 0) {
                if (!empty($result['skipped_missing_file'])) {
                    $skippedIds[$affectedFileId] = $affectedFileId;
                } else {
                    $processedIds[$affectedFileId] = $affectedFileId;
                }
            }

            $imports += max(0, (int)($result['imports_processed'] ?? 0));
            $changed += max(0, (int)($result['dependencies_changed'] ?? 0));
            $rewritten += max(0, (int)($result['containers_rewritten'] ?? 0));
            if (!empty($result['container_rewritten'])) {
                $rewritten++;
            }
        }
        ksort($processedIds, SORT_NUMERIC);
        ksort($skippedIds, SORT_NUMERIC);
        return [
            'processed_files' => count($processedIds),
            'skipped_files' => count($skippedIds),
            'imports_processed' => $imports,
            'dependencies_changed' => $changed,
            'containers_rewritten' => $rewritten,
            'processed_file_ids' => array_values($processedIds),
        ];
    }

    /** @return array<string,mixed>|null */
    private function sourceFile(int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name FROM ue_files '
            . 'WHERE id=? AND scan_status="verified"'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function skipMissingSource(int $fileId, JobExecutionContext $context): array
    {
        $context->checkpoint([
            'stage' => 'skipped',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'message' => 'Skipped affected dependency refresh because source file #'
                . $fileId . ' no longer exists as a verified file.',
            'file_id' => $fileId,
            'skip_reason' => 'source_file_missing',
        ]);
        return [
            'operation' => 'rebuild_affected_dependencies',
            'file_id' => $fileId,
            'skipped' => true,
            'skip_reason' => 'source_file_missing',
            'affected_files' => 0,
            'processed_files' => 0,
            'failure_count' => 0,
        ];
    }

    /** @return array<string,int>|null */
    private function refreshGameStats(int $gameId): ?array
    {
        $stats = new PdoGameCatalogStats($this->db);
        if (!$stats->available()) {
            return null;
        }
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $stats->rebuildGame($gameId, 5);
            if (is_array($result)) {
                return $result;
            }
            if ($attempt < 3) {
                usleep(100000 * $attempt);
            }
        }
        throw new RuntimeException(
            'Could not refresh cached game counters after affected dependency work due to concurrent stats work.'
        );
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
