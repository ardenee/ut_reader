<?php
/**
 * Keeps affected-dependency coordinators non-blocking when child recovery units
 * reach a terminal failed/cancelled state.
 *
 * The underlying handler remains the owner of normal planning/batching. This
 * decorator only handles the compatibility case where a root has no runnable
 * children left but older child rows require operator attention. Successful work
 * is finalized immediately and the failed child rows remain visible/restartable.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;

final class CatalogNonBlockingAffectedDependencyJobHandler implements JobHandler
{
    private readonly CatalogAffectedDependencyRefreshJobHandler $inner;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->inner = new CatalogAffectedDependencyRefreshJobHandler($db, $config);
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REBUILD_AFFECTED_DEPENDENCIES;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($this->isBlockedCoordinator($job)) {
            return $this->finalizeBlockedCoordinator($job, $context);
        }

        $result = $this->inner->handle($job, $context);

        // Version-4 batches used affected:retry child rows. If one of those old
        // rows is manually restarted after its parent has already finalized, its
        // targeted rebuild must publish its own summary/counter refresh because
        // the parent can no longer aggregate it.
        if ($this->isLegacyRetryUnit($job)
            && empty($result['skipped_missing_file'])
            && $this->parentCompleted($job->parentJobId)) {
            $affectedFileId = (int)($job->payload['affected_file_id'] ?? 0);
            $gameId = (int)($result['game_id'] ?? $job->payload['game_id'] ?? 0);
            if ($affectedFileId > 0 && $gameId > 0) {
                $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($affectedFileId);
                if (empty($summary['available'])) {
                    throw new RuntimeException(
                        'Dependency package summary projection is unavailable after affected recovery.'
                    );
                }
                $statsJobId = CatalogGameStatsRefreshCoordinator::request(
                    $this->db,
                    $job->queue,
                    $gameId,
                    null
                );
                $result['recovery_summary_rows'] = (int)($summary['summary_rows'] ?? 0);
                $result['recovery_game_stats_job_id'] = $statsJobId;
                $result['recovery_published_after_parent'] = true;
            }
        }

        return $result;
    }

    private function isBlockedCoordinator(ClaimedJob $job): bool
    {
        if ($job->parentJobId !== null
            || (int)($job->payload['affected_file_id'] ?? 0) > 0
            || array_key_exists('affected_file_ids', $job->payload)) {
            return false;
        }

        $resume = $job->resumeProgress;
        if ((string)($resume['stage'] ?? '') !== 'affected_wait') {
            return false;
        }

        $state = $this->childState($job->id);
        $runnable = $state['queued'] + $state['running'];
        $problems = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
        return $runnable === 0 && $problems > 0;
    }

    /** @return array<string,mixed> */
    private function finalizeBlockedCoordinator(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceFileId = (int)($job->payload['file_id'] ?? 0);
        if ($sourceFileId < 1) {
            throw new RuntimeException('Affected dependency refresh requires a positive file_id.');
        }

        $source = $this->sourceFile($sourceFileId);
        if ($source === null) {
            // Preserve the original handler's normal missing-source semantics.
            return $this->inner->handle($job, $context);
        }

        $children = $this->childState($job->id);
        $problemCount = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
        if (($children['queued'] + $children['running']) > 0 || $problemCount < 1) {
            return $this->inner->handle($job, $context);
        }

        $packageName = (string)$source['package_name'];
        $gameId = (int)$source['game_id'];
        $aggregate = $this->aggregateCompletedUnits($job->id);

        $context->checkpoint([
            'workflow_version' => 4,
            'stage' => 'affected_finalize',
            'done' => 88,
            'total' => 100,
            'percent' => 88,
            'package_name' => $packageName,
            'children' => $children,
            'failure_count' => $problemCount,
            'message' => 'No affected dependency unit is still runnable. Finalizing successful work while '
                . $problemCount . ' failed/cancelled recovery item(s) remain visible to the administrator.',
        ]);

        $summaryRows = 0;
        if ($aggregate['changed_file_ids'] !== []) {
            $context->checkpoint([
                'workflow_version' => 4,
                'stage' => 'affected_finalize',
                'done' => 92,
                'total' => 100,
                'percent' => 92,
                'package_name' => $packageName,
                'failure_count' => $problemCount,
                'message' => 'Publishing dependency summaries for '
                    . count($aggregate['changed_file_ids']) . ' successfully changed file(s).',
            ]);
            $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFiles($aggregate['changed_file_ids']);
            if (empty($summary['available'])) {
                throw new RuntimeException(
                    'Dependency package summary projection is unavailable after affected refresh.'
                );
            }
            $summaryRows = (int)($summary['summary_rows'] ?? 0);
        }

        $context->checkpoint([
            'workflow_version' => 4,
            'stage' => 'affected_finalize',
            'done' => 97,
            'total' => 100,
            'percent' => 97,
            'package_name' => $packageName,
            'game_id' => $gameId,
            'failure_count' => $problemCount,
            'message' => 'Scheduling one coalesced game-counter refresh for the successful affected dependency work.',
        ]);
        $requestedBy = (int)($job->payload['requested_by'] ?? 0);
        $statsJobId = CatalogGameStatsRefreshCoordinator::request(
            $this->db,
            $job->queue,
            $gameId,
            $requestedBy > 0 ? $requestedBy : null
        );

        $resume = $job->resumeProgress;
        $affectedTotal = max(
            0,
            (int)($resume['affected_total'] ?? 0),
            $aggregate['processed_files'] + $aggregate['skipped_files'] + $problemCount
        );
        $message = 'Affected dependency refresh finalized for ' . $packageName . ': '
            . $aggregate['processed_files'] . ' processed, '
            . $aggregate['changed_files'] . ' changed, '
            . $aggregate['skipped_files'] . ' skipped; '
            . $problemCount . ' failed/cancelled recovery item(s) did not block the queue. '
            . 'Cached counters coalesced into job #' . $statsJobId . '.';

        $context->checkpoint([
            'workflow_version' => 4,
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'status' => 'partial',
            'package_name' => $packageName,
            'affected_total' => $affectedTotal,
            'children' => $children,
            'processed' => $aggregate['processed_files'],
            'changed' => $aggregate['changed_files'],
            'skipped' => $aggregate['skipped_files'],
            'dependencies_changed' => $aggregate['dependencies_changed'],
            'game_stats_refresh_job_id' => $statsJobId,
            'failure_count' => $problemCount,
            'message' => $message,
        ]);

        return [
            'operation' => 'rebuild_affected_dependencies',
            'status' => 'partial',
            'workflow_version' => 4,
            'mode' => 'coordinator',
            'file_id' => $sourceFileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$source['original_name'],
            'affected_files' => $affectedTotal,
            'processed_files' => $aggregate['processed_files'],
            'changed_files' => $aggregate['changed_files'],
            'skipped_files' => $aggregate['skipped_files'],
            'imports_processed' => $aggregate['imports_processed'],
            'dependencies_changed' => $aggregate['dependencies_changed'],
            'containers_rewritten' => $aggregate['containers_rewritten'],
            'dependency_summary_rows' => $summaryRows,
            'game_stats_refreshed' => false,
            'game_stats_refresh_job_id' => $statsJobId,
            'failure_count' => $problemCount,
            'failures' => [],
            'children' => $children,
        ];
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId): array
    {
        $state = [
            'total' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'dead_letter' => 0,
            'cancelled' => 0,
        ];
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

    /**
     * @return array{
     *   processed_files:int,changed_files:int,skipped_files:int,imports_processed:int,
     *   dependencies_changed:int,containers_rewritten:int,changed_file_ids:list<int>
     * }
     */
    private function aggregateCompletedUnits(int $parentJobId): array
    {
        $processedIds = [];
        $changedIds = [];
        $skippedIds = [];
        $imports = 0;
        $dependencyChanges = 0;
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

            $rowProcessedIds = [];
            foreach ((array)($result['processed_file_ids'] ?? []) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $processedIds[$id] = $id;
                    $rowProcessedIds[$id] = $id;
                }
            }
            foreach ((array)($result['skipped_file_ids'] ?? []) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $skippedIds[$id] = $id;
                }
            }

            $affectedFileId = (int)($result['affected_file_id'] ?? 0);
            if ($affectedFileId > 0) {
                if (!empty($result['skipped_missing_file'])) {
                    $skippedIds[$affectedFileId] = $affectedFileId;
                } else {
                    $processedIds[$affectedFileId] = $affectedFileId;
                    $rowProcessedIds[$affectedFileId] = $affectedFileId;
                }
            }

            $rowChanges = max(0, (int)($result['dependencies_changed'] ?? 0));
            if (is_array($result['changed_file_ids'] ?? null)) {
                foreach ($result['changed_file_ids'] as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $changedIds[$id] = $id;
                    }
                }
            } elseif ($rowChanges > 0) {
                foreach ($rowProcessedIds as $id) {
                    $changedIds[$id] = $id;
                }
            }

            $imports += max(0, (int)($result['imports_processed'] ?? 0));
            $dependencyChanges += $rowChanges;
            $rewritten += max(0, (int)($result['containers_rewritten'] ?? 0));
            if (!empty($result['container_rewritten'])) {
                $rewritten++;
            }
        }

        ksort($processedIds, SORT_NUMERIC);
        ksort($changedIds, SORT_NUMERIC);
        ksort($skippedIds, SORT_NUMERIC);
        return [
            'processed_files' => count($processedIds),
            'changed_files' => count($changedIds),
            'skipped_files' => count($skippedIds),
            'imports_processed' => $imports,
            'dependencies_changed' => $dependencyChanges,
            'containers_rewritten' => $rewritten,
            'changed_file_ids' => array_values($changedIds),
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

    private function isLegacyRetryUnit(ClaimedJob $job): bool
    {
        return $job->parentJobId !== null
            && (int)($job->payload['affected_file_id'] ?? 0) > 0
            && (int)($job->payload['retry_of_batch_job_id'] ?? 0) > 0;
    }

    private function parentCompleted(?int $parentJobId): bool
    {
        if ($parentJobId === null || $parentJobId < 1) {
            return false;
        }
        $statement = $this->db->prepare('SELECT status FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$parentJobId]);
        return strtolower(trim((string)($statement->fetchColumn() ?: ''))) === 'completed';
    }
}
