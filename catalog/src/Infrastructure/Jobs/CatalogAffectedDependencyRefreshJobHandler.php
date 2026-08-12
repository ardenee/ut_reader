<?php
/**
 * Durable targeted dependency refresh workflow after a provider becomes available.
 *
 * The provider/source preparation remains one bounded parent phase. Every
 * affected dependency owner is then an independent child unit. This deliberately
 * replaces the old 50-file child batches: a failure now retries exactly one file,
 * while successful file units and their compact metadata writes are retained.
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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

final class CatalogAffectedDependencyRefreshJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 3;
    private const PLAN_BATCH_SIZE = 500;
    private const LEGACY_MAX_BATCH_SIZE = 250;

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
            throw new \RuntimeException('Affected dependency refresh requires a positive file_id.');
        }

        $source = $this->sourceFile($sourceFileId);
        if ($source === null) {
            return $this->skipMissingSource($sourceFileId, $context);
        }

        if ((int)($job->payload['affected_file_id'] ?? 0) > 0) {
            return $this->handleFileUnit($job, $context, $source);
        }

        // Existing queued jobs from the previous batch implementation remain
        // executable after deployment. Their persisted `done` cursor is honored,
        // so a retried legacy batch continues after its last completed file.
        if (array_key_exists('affected_file_ids', $job->payload)) {
            return $this->handleLegacyBatch($job, $context, $source);
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

        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'affected_prepare';
            $resume = [];
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
            $context->checkpoint($this->progress(
                'affected_plan',
                5,
                'Source provider is authoritative; planning affected dependency-owner units.',
                [
                    'package_name' => $packageName,
                    'dependency_summary_rows' => (int)($sourceSummary['summary_rows'] ?? 0),
                    'plan_last_file_id' => 0,
                    'planned_units' => 0,
                ]
            ));
            $resume = $context->resumeProgress();
            $stage = 'affected_plan';
        }

        if ($stage === 'affected_plan') {
            $this->planFileUnits($job, $context, $source, $resume);
            $stage = 'affected_wait';
        }

        if ($stage === 'affected_wait') {
            $children = $this->childState($job->id);
            $total = max(1, $children['total']);
            $percent = 10 + (int)floor(($children['completed'] * 75) / $total);
            $problems = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
            if ($problems > 0) {
                $context->defer(30, $this->progress(
                    'affected_wait',
                    min(85, $percent),
                    'Affected dependency workflow is waiting on ' . $problems . ' failed/cancelled file unit(s). '
                        . 'Restart only those file units; ' . $children['completed'] . ' successful unit(s) are retained.',
                    ['package_name' => $packageName, 'children' => $children]
                ));
            }
            if (($children['queued'] + $children['running']) > 0) {
                $context->defer(2, $this->progress(
                    'affected_wait',
                    min(85, $percent),
                    'Affected file units for ' . $packageName . ': ' . $children['completed'] . '/'
                        . $children['total'] . ' complete, ' . $children['running'] . ' running, '
                        . $children['queued'] . ' queued.',
                    ['package_name' => $packageName, 'children' => $children]
                ));
            }
            $context->checkpoint($this->progress(
                'affected_finalize',
                88,
                'All affected file units completed; publishing dependency summaries and game counters.',
                ['package_name' => $packageName, 'children' => $children]
            ));
            $stage = 'affected_finalize';
        }

        if ($stage !== 'affected_finalize') {
            throw new \RuntimeException('Unknown affected dependency workflow stage: ' . $stage);
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
                throw new \RuntimeException('Dependency package summary projection is unavailable after affected refresh.');
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

        $message = 'Affected dependency refresh complete for ' . $packageName . ': '
            . $aggregate['processed_files'] . ' processed, ' . $aggregate['skipped_files'] . ' skipped, '
            . $aggregate['dependencies_changed'] . ' dependency change(s).';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'package_name' => $packageName,
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
            'affected_files' => $children['total'],
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

    /** @param array<string,mixed> $source */
    private function planFileUnits(
        ClaimedJob $job,
        JobExecutionContext $context,
        array $source,
        array $resume
    ): void {
        $sourceFileId = (int)$source['id'];
        $gameId = (int)$source['game_id'];
        $packageName = (string)$source['package_name'];
        $affectedIds = CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $this->db,
            $gameId,
            $sourceFileId,
            $packageName
        );

        $lastFileId = max(0, (int)($resume['plan_last_file_id'] ?? 0));
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        if ((string)($resume['stage'] ?? '') !== 'affected_plan') {
            $lastFileId = 0;
            $planned = 0;
        }

        $ids = [];
        foreach ($affectedIds as $affectedFileId) {
            if ($affectedFileId <= $lastFileId) {
                continue;
            }
            $ids[] = $affectedFileId;
            if (count($ids) >= self::PLAN_BATCH_SIZE) {
                break;
            }
        }

        $queue = new PdoJobQueue($this->db);
        foreach ($ids as $affectedFileId) {
            $queue->enqueue(
                $job->queue,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $sourceFileId,
                    'game_id' => $gameId,
                    'package_name' => $packageName,
                    'affected_file_id' => $affectedFileId,
                    'workflow_parent_job_id' => $job->id,
                ],
                40,
                null,
                null,
                null,
                3,
                $job->id,
                'affected:' . $affectedFileId
            );
            $lastFileId = $affectedFileId;
            $planned++;
        }

        $hasMore = false;
        foreach ($affectedIds as $affectedFileId) {
            if ($affectedFileId > $lastFileId) {
                $hasMore = true;
                break;
            }
        }
        $progress = $this->progress(
            'affected_plan',
            7,
            'Planned ' . $planned . '/' . count($affectedIds) . ' independent affected-file unit(s) for '
                . $packageName . '.',
            [
                'package_name' => $packageName,
                'plan_last_file_id' => $lastFileId,
                'planned_units' => $planned,
                'affected_total' => count($affectedIds),
            ]
        );
        if ($hasMore) {
            $context->defer(1, $progress);
        }

        $context->checkpoint($this->progress(
            'affected_wait',
            10,
            'Planned ' . $planned . ' independent affected-file unit(s); waiting for workers.',
            [
                'package_name' => $packageName,
                'planned_units' => $planned,
                'affected_total' => count($affectedIds),
            ]
        ));
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

        // No per-file exception swallowing here. Any unexpected failure belongs
        // to this one durable child and can be restarted without replaying peers.
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

    /**
     * Compatibility path for jobs already queued by the old 50-file fan-out.
     * A retry uses the durable `done` cursor and starts with the first unfinished
     * element. New work never creates this shape.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function handleLegacyBatch(ClaimedJob $job, JobExecutionContext $context, array $source): array
    {
        $ids = $this->legacyBatchIds($job->payload['affected_file_ids'] ?? null);
        $resume = $context->resumeProgress();
        $start = (string)($resume['stage'] ?? '') === 'dependencies'
            ? max(0, min(count($ids), (int)($resume['done'] ?? 0)))
            : 0;
        $processedIds = [];
        $importsProcessed = 0;
        $dependenciesChanged = 0;
        $skipped = 0;
        $rewritten = 0;
        $rebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);

        for ($index = $start; $index < count($ids); $index++) {
            $affectedFileId = $ids[$index];
            $result = $rebuilder->rebuildForPackages(
                $affectedFileId,
                [(string)$source['package_name']],
                false
            );
            if (!empty($result['skipped_missing_file'])) {
                $skipped++;
            } else {
                $processedIds[] = $affectedFileId;
                $importsProcessed += max(0, (int)($result['imports_processed'] ?? 0));
                $dependenciesChanged += max(0, (int)($result['dependencies_changed'] ?? 0));
                if (!empty($result['container_rewritten'])) {
                    $rewritten++;
                }
            }
            $done = $index + 1;
            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => $done,
                'total' => count($ids),
                'percent' => (int)floor(($done * 90) / max(1, count($ids))),
                'package_name' => (string)$source['package_name'],
                'affected_file_id' => $affectedFileId,
                'message' => 'Compatibility batch resumed at ' . $done . '/' . count($ids) . '.',
            ]);
        }

        if ($processedIds !== []) {
            (new PdoDependencyPackageSummary($this->db))->rebuildFiles($processedIds);
        }
        $this->refreshGameStats((int)$source['game_id']);
        $context->checkpoint([
            'stage' => 'complete',
            'done' => count($ids),
            'total' => count($ids),
            'percent' => 100,
            'message' => 'Legacy affected dependency batch completed without replaying its persisted cursor.',
        ]);
        return [
            'operation' => 'rebuild_affected_dependencies',
            'mode' => 'legacy_batch_compatibility',
            'file_id' => (int)$source['id'],
            'game_id' => (int)$source['game_id'],
            'package_name' => (string)$source['package_name'],
            'batch_size' => count($ids),
            'processed_files' => count($processedIds),
            'skipped_files' => $skipped,
            'imports_processed' => $importsProcessed,
            'dependencies_changed' => $dependenciesChanged,
            'containers_rewritten' => $rewritten,
            'failure_count' => 0,
            'failures' => [],
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

    /** @return array{processed_files:int,skipped_files:int,imports_processed:int,dependencies_changed:int,containers_rewritten:int,processed_file_ids:list<int>} */
    private function aggregateFileUnits(int $parentJobId): array
    {
        $processed = 0;
        $skipped = 0;
        $imports = 0;
        $changed = 0;
        $rewritten = 0;
        $processedIds = [];
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
            if (!empty($result['skipped_missing_file'])) {
                $skipped++;
                continue;
            }
            $fileId = (int)($result['affected_file_id'] ?? 0);
            if ($fileId > 0) {
                $processedIds[$fileId] = $fileId;
            }
            $processed++;
            $imports += max(0, (int)($result['imports_processed'] ?? 0));
            $changed += max(0, (int)($result['dependencies_changed'] ?? 0));
            if (!empty($result['container_rewritten'])) {
                $rewritten++;
            }
        }
        ksort($processedIds, SORT_NUMERIC);
        return [
            'processed_files' => $processed,
            'skipped_files' => $skipped,
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

    /** @return list<int> */
    private function legacyBatchIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Affected dependency compatibility batch requires affected_file_ids.');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === [] || count($ids) > self::LEGACY_MAX_BATCH_SIZE) {
            throw new \RuntimeException('Affected dependency compatibility batch has an invalid file list.');
        }
        return $ids;
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
        throw new \RuntimeException(
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
