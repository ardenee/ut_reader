<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Plans and executes targeted dependency refresh batches after a package provider becomes available.
 * Why: Rebuilding hundreds or thousands of affected files serially in one worker wastes the pool and repeats projection work.
 * Role: Durable fan-out/batch handler for JobType::REBUILD_AFFECTED_DEPENDENCIES.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/** Rebuilds existing files affected by one newly available package. */
final class CatalogAffectedDependencyRefreshJobHandler implements JobHandler
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const MAX_BATCH_SIZE = 250;

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

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = (int)($job->payload['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new \RuntimeException('Affected dependency refresh requires a positive file_id.');
        }

        $file = $this->sourceFile($fileId);
        if ($file === null) {
            return $this->skipMissingSource($fileId, $context);
        }

        if (array_key_exists('affected_file_ids', $job->payload)) {
            return $this->handleBatch($job, $context, $file);
        }

        return $this->planBatches($job, $context, $file);
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function planBatches(ClaimedJob $job, JobExecutionContext $context, array $file): array
    {
        $fileId = (int)$file['id'];
        $gameId = (int)$file['game_id'];
        $packageName = (string)$file['package_name'];
        $summaryWriter = new PdoDependencyPackageSummary($this->db);
        $sourceSummaryReady = !empty($job->payload['source_summary_ready']);
        $sourceSummaryRows = max(0, (int)($job->payload['summary_rows_total'] ?? 0));

        if (!$sourceSummaryReady) {
            $rebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);
            $rebuilder->rebuild(
                $fileId,
                static function (array $progress) use ($context, $packageName): void {
                    $context->heartbeatIfDue([
                        'stage' => 'source_dependencies',
                        'done' => 0,
                        'total' => 1,
                        'percent' => 0,
                        'message' => 'Preparing source dependencies for ' . $packageName
                            . (!empty($progress['message']) ? ' — ' . (string)$progress['message'] : ''),
                    ]);
                },
                0,
                100,
                'Preparing source dependency links',
                false
            );
            (new PdoPackageProviderRepository($this->db))->reconcileFile($fileId);
            $sourceSummary = $summaryWriter->rebuildFile($fileId);
            $sourceSummaryRows += (int)$sourceSummary['summary_rows'];
            $sourceSummaryReady = true;
        }

        $context->checkpoint([
            'stage' => 'discover',
            'done' => 0,
            'total' => 1,
            'percent' => 5,
            'message' => 'Finding files whose ' . $packageName . ' dependencies can change.',
            'package_name' => $packageName,
        ]);

        $affectedIds = CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $this->db,
            $gameId,
            $fileId,
            $packageName
        );
        $total = count($affectedIds);
        $resumeOffset = max(0, min($total, (int)($job->payload['resume_offset'] ?? 0)));
        $remainingIds = array_slice($affectedIds, $resumeOffset);

        if ($remainingIds === []) {
            $gameStats = $this->refreshGameStats($gameId);
            $context->checkpoint([
                'stage' => 'complete',
                'done' => max(1, $total),
                'total' => max(1, $total),
                'percent' => 100,
                'message' => $total === 0
                    ? 'No existing files require an affected dependency refresh.'
                    : 'Affected dependency refresh is already complete for ' . $packageName . '.',
                'package_name' => $packageName,
            ]);
            return [
                'operation' => 'rebuild_affected_dependencies',
                'mode' => 'planner',
                'file_id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
                'affected_files' => $total,
                'resume_offset' => $resumeOffset,
                'planned_batches' => 0,
                'child_job_ids' => [],
                'source_summary_ready' => $sourceSummaryReady,
                'dependency_summary_rows' => $sourceSummaryRows,
                'game_stats_refreshed' => $gameStats !== null,
            ];
        }

        $batchSize = $this->batchSize();
        $chunks = array_chunk($remainingIds, $batchSize);
        $totalBatchCount = (int)ceil($total / max(1, $batchSize));
        $firstBatchNumber = intdiv($resumeOffset, $batchSize) + 1;
        $queueName = trim((string)($this->config['queue']['name'] ?? $job->queue));
        if ($queueName === '') {
            $queueName = $job->queue;
        }
        $queue = new PdoJobQueue($this->db);
        $childJobIds = [];

        foreach ($chunks as $index => $chunk) {
            $batchNumber = $firstBatchNumber + $index;
            $batchStart = $resumeOffset + ($index * $batchSize) + 1;
            $batchEnd = $batchStart + count($chunk) - 1;
            $chunkHash = substr(hash('sha256', implode(',', $chunk)), 0, 20);
            $childJobIds[] = $queue->enqueue(
                $queueName,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'package_name' => $packageName,
                    'affected_file_ids' => array_values(array_map('intval', $chunk)),
                    'affected_total' => $total,
                    'batch_number' => $batchNumber,
                    'batch_count' => $totalBatchCount,
                    'batch_start' => $batchStart,
                    'batch_end' => $batchEnd,
                    'source_summary_ready' => true,
                ],
                40,
                null,
                'rebuild-affected-file:' . $fileId . ':batch:' . $chunkHash,
                null,
                3
            );
        }

        $context->checkpoint([
            'stage' => 'fanout',
            'done' => $resumeOffset,
            'total' => max(1, $total),
            'percent' => 100,
            'message' => 'Queued ' . count($childJobIds) . ' targeted batch job(s) for '
                . count($remainingIds) . ' affected file(s) for ' . $packageName . '.',
            'package_name' => $packageName,
            'affected_files' => $total,
            'remaining_files' => count($remainingIds),
            'batch_size' => $batchSize,
            'child_job_ids' => $childJobIds,
        ]);

        return [
            'operation' => 'rebuild_affected_dependencies',
            'mode' => 'planner',
            'file_id' => $fileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$file['original_name'],
            'affected_files' => $total,
            'resume_offset' => $resumeOffset,
            'remaining_files' => count($remainingIds),
            'batch_size' => $batchSize,
            'planned_batches' => count($childJobIds),
            'child_job_ids' => $childJobIds,
            'source_summary_ready' => $sourceSummaryReady,
            'dependency_summary_rows' => $sourceSummaryRows,
        ];
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function handleBatch(ClaimedJob $job, JobExecutionContext $context, array $file): array
    {
        $sourceFileId = (int)$file['id'];
        $gameId = (int)$file['game_id'];
        $packageName = (string)$file['package_name'];
        $ids = $this->batchIds($job->payload['affected_file_ids'] ?? null);
        $batchNumber = max(1, (int)($job->payload['batch_number'] ?? 1));
        $batchCount = max($batchNumber, (int)($job->payload['batch_count'] ?? $batchNumber));
        $affectedTotal = max(count($ids), (int)($job->payload['affected_total'] ?? count($ids)));
        $batchStart = max(1, (int)($job->payload['batch_start'] ?? 1));
        $batchEnd = max($batchStart, (int)($job->payload['batch_end'] ?? ($batchStart + count($ids) - 1)));

        $context->checkpoint([
            'stage' => 'dependencies',
            'done' => 0,
            'total' => max(1, count($ids)),
            'percent' => 0,
            'message' => 'Starting affected dependency batch ' . $batchNumber . '/' . $batchCount
                . ' (' . $batchStart . '-' . $batchEnd . ' of ' . $affectedTotal . ') for ' . $packageName . '.',
            'package_name' => $packageName,
            'batch_number' => $batchNumber,
            'batch_count' => $batchCount,
            'batch_size' => count($ids),
        ]);

        $rebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);
        $processedIds = [];
        $processed = 0;
        $skipped = 0;
        $failureCount = 0;
        $failures = [];
        $importsProcessed = 0;
        $dependenciesChanged = 0;
        $containersRewritten = 0;

        foreach ($ids as $index => $affectedFileId) {
            $position = $index + 1;
            $overallPosition = min($affectedTotal, $batchStart + $index);
            try {
                $result = $rebuilder->rebuildForPackages($affectedFileId, [$packageName], false);
                if (!empty($result['skipped_missing_file'])) {
                    $skipped++;
                } else {
                    $processed++;
                    $processedIds[] = $affectedFileId;
                    $importsProcessed += max(0, (int)($result['imports_processed'] ?? 0));
                    $dependenciesChanged += max(0, (int)($result['dependencies_changed'] ?? 0));
                    if (!empty($result['container_rewritten'])) {
                        $containersRewritten++;
                    }
                }
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (PDOException $error) {
                // Database failures are batch infrastructure failures. Let the
                // durable job retry the whole idempotent batch instead of silently
                // losing one dependency owner.
                throw $error;
            } catch (Throwable $error) {
                if (str_contains(strtolower($error->getMessage()), 'already being refreshed')) {
                    throw $error;
                }
                $failureCount++;
                if (count($failures) < 100) {
                    $failures[] = [
                        'file_id' => $affectedFileId,
                        'error' => $error->getMessage(),
                    ];
                }
                error_log(
                    '[UnrealDB affected dependency batch] source_file_id=' . $sourceFileId
                    . ' affected_file_id=' . $affectedFileId
                    . ' error=' . $error->getMessage()
                );
            }

            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => $position,
                'total' => max(1, count($ids)),
                'percent' => (int)floor(($position * 85) / max(1, count($ids))),
                'message' => 'Batch ' . $batchNumber . '/' . $batchCount . ': processed '
                    . $position . '/' . count($ids) . ' file(s); overall '
                    . $overallPosition . '/' . $affectedTotal . ' for ' . $packageName . '.',
                'package_name' => $packageName,
                'batch_number' => $batchNumber,
                'batch_count' => $batchCount,
                'processed' => $processed,
                'skipped' => $skipped,
                'failures' => $failureCount,
                'dependencies_changed' => $dependenciesChanged,
            ]);

            if (($position % 25) === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $context->checkpoint([
            'stage' => 'summaries',
            'done' => count($ids),
            'total' => max(1, count($ids)),
            'percent' => 90,
            'message' => 'Bulk-refreshing dependency summaries for batch ' . $batchNumber . '/' . $batchCount . '.',
            'package_name' => $packageName,
        ]);
        $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFiles($processedIds);
        if (empty($summary['available']) && $processedIds !== []) {
            throw new \RuntimeException('Dependency package summary projection is unavailable after affected batch rebuild.');
        }

        $context->checkpoint([
            'stage' => 'game_stats',
            'done' => count($ids),
            'total' => max(1, count($ids)),
            'percent' => 95,
            'message' => 'Refreshing cached game counters after batch ' . $batchNumber . '/' . $batchCount . '.',
            'game_id' => $gameId,
        ]);
        $gameStats = $this->refreshGameStats($gameId);

        $context->checkpoint([
            'stage' => 'complete',
            'done' => count($ids),
            'total' => max(1, count($ids)),
            'percent' => 100,
            'message' => 'Completed affected dependency batch ' . $batchNumber . '/' . $batchCount
                . ': ' . $processed . ' processed, ' . $skipped . ' skipped, '
                . $failureCount . ' failed; ' . $dependenciesChanged . ' dependency change(s).',
            'package_name' => $packageName,
            'processed' => $processed,
            'skipped' => $skipped,
            'failures' => $failureCount,
            'dependencies_changed' => $dependenciesChanged,
        ]);

        return [
            'operation' => 'rebuild_affected_dependencies',
            'mode' => 'batch',
            'file_id' => $sourceFileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$file['original_name'],
            'batch_number' => $batchNumber,
            'batch_count' => $batchCount,
            'batch_start' => $batchStart,
            'batch_end' => $batchEnd,
            'affected_files' => $affectedTotal,
            'batch_size' => count($ids),
            'processed_files' => $processed,
            'skipped_files' => $skipped,
            'imports_processed' => $importsProcessed,
            'dependencies_changed' => $dependenciesChanged,
            'containers_rewritten' => $containersRewritten,
            'dependency_summary_rows' => (int)($summary['summary_rows'] ?? 0),
            'game_stats_refreshed' => $gameStats !== null,
            'failure_count' => $failureCount,
            'failures' => $failures,
            'failures_truncated' => $failureCount > count($failures),
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
    private function batchIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Affected dependency batch payload requires affected_file_ids.');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            throw new \RuntimeException('Affected dependency batch contains no valid file IDs.');
        }
        if (count($ids) > self::MAX_BATCH_SIZE) {
            throw new \RuntimeException('Affected dependency batch exceeds ' . self::MAX_BATCH_SIZE . ' files.');
        }
        return $ids;
    }

    private function batchSize(): int
    {
        return max(10, min(
            self::MAX_BATCH_SIZE,
            (int)($this->config['queue']['affected_dependency_batch_size'] ?? self::DEFAULT_BATCH_SIZE)
        ));
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
            'Could not refresh cached game counters after affected dependency batch due to concurrent stats work.'
        );
    }
}
