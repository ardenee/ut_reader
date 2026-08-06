<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Dependency\CatalogAffectedDependencyRefreshService;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Rebuilds existing files affected by one newly available package. */
final class CatalogAffectedDependencyRefreshJobHandler implements JobHandler
{
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

        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name FROM ue_files '
            . 'WHERE id=? AND scan_status="verified"'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            throw new \RuntimeException('Verified source file no longer exists: ' . $fileId);
        }

        require_once __DIR__ . '/../../../lib/CatalogScanner.php';

        $summaryWriter = new PdoDependencyPackageSummary($this->db);
        $gameId = (int)$file['game_id'];
        $packageName = (string)$file['package_name'];
        $affectedIds = CatalogAffectedDependencyRefreshService::findAffectedFileIds(
            $this->db,
            $gameId,
            $fileId,
            $packageName
        );
        $total = count($affectedIds);
        $resumeOffset = max(0, min($total, (int)($job->payload['resume_offset'] ?? 0)));
        $chunkSize = max(50, min(
            5000,
            (int)($this->config['queue']['affected_dependency_chunk_size'] ?? 500)
        ));
        $chunkIds = array_slice($affectedIds, $resumeOffset, $chunkSize);
        $processed = 0;
        $processedTotal = max(0, (int)($job->payload['processed_total'] ?? $resumeOffset));
        $failureCount = 0;
        $failureTotal = max(0, (int)($job->payload['failure_total'] ?? 0));
        $failures = [];
        $summaryRows = max(0, (int)($job->payload['summary_rows_total'] ?? 0));
        $sourceSummaryReady = !empty($job->payload['source_summary_ready']);
        if ($resumeOffset === 0 && !$sourceSummaryReady) {
            $sourceSummary = $summaryWriter->rebuildFile($fileId);
            $summaryRows += (int)$sourceSummary['summary_rows'];
            $sourceSummaryReady = true;
        }

        $context->checkpoint([
            'stage' => 'dependencies',
            'done' => $resumeOffset,
            'total' => max(1, $total),
            'percent' => $total === 0 ? 90 : (int)floor(($resumeOffset * 90) / max(1, $total)),
            'message' => $total === 0
                ? 'No existing files require an affected dependency refresh.'
                : 'Refreshing affected files ' . ($resumeOffset + 1) . '-'
                    . min($total, $resumeOffset + count($chunkIds)) . ' of ' . $total
                    . ' for ' . $packageName . '.',
            'package_name' => $packageName,
            'resume_offset' => $resumeOffset,
            'chunk_size' => count($chunkIds),
            'failures' => $failureTotal,
            'source_summary_ready' => $sourceSummaryReady,
        ]);

        foreach ($chunkIds as $index => $affectedFileId) {
            $position = $resumeOffset + $index + 1;
            try {
                \scanner_rebuild_dependencies(
                    $this->db,
                    $this->config,
                    $affectedFileId,
                    static function (array $progress) use ($context, $position, $total, $packageName, $failureTotal): void {
                        $context->heartbeatIfDue([
                            'stage' => 'dependencies',
                            'done' => $position - 1,
                            'total' => max(1, $total),
                            'percent' => (int)floor((($position - 1) * 90) / max(1, $total)),
                            'message' => 'Refreshing affected file ' . $position . '/' . $total . ' for ' . $packageName
                                . (!empty($progress['message']) ? ' — ' . (string)$progress['message'] : ''),
                            'package_name' => $packageName,
                            'failures' => $failureTotal,
                        ]);
                    },
                    0,
                    100,
                    'Refreshing affected file ' . $position . '/' . $total
                );
                $summary = $summaryWriter->rebuildFile($affectedFileId);
                $summaryRows += (int)$summary['summary_rows'];
                $processed++;
                $processedTotal++;
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (Throwable $error) {
                $failureCount++;
                $failureTotal++;
                if (count($failures) < 100) {
                    $failures[] = [
                        'file_id' => $affectedFileId,
                        'error' => $error->getMessage(),
                    ];
                }
                error_log(
                    '[UnrealDB affected dependency refresh] source_file_id=' . $fileId
                    . ' affected_file_id=' . $affectedFileId
                    . ' error=' . $error->getMessage()
                );
            }

            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => $position,
                'total' => max(1, $total),
                'percent' => (int)floor(($position * 90) / max(1, $total)),
                'message' => 'Processed affected file ' . $position . '/' . $total . ' for ' . $packageName . '.',
                'package_name' => $packageName,
                'processed' => $processedTotal,
                'failures' => $failureTotal,
                'dependency_summary_rows' => $summaryRows,
            ]);

            if (($position % 50) === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $nextOffset = min($total, $resumeOffset + count($chunkIds));
        $continuationJobId = 0;
        if ($nextOffset < $total) {
            $queueName = trim((string)($this->config['queue']['name'] ?? $job->queue));
            if ($queueName === '') {
                $queueName = $job->queue;
            }
            $continuationJobId = (new PdoJobQueue($this->db))->enqueue(
                $queueName,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                [
                    'file_id' => $fileId,
                    'game_id' => $gameId,
                    'package_name' => $packageName,
                    'resume_offset' => $nextOffset,
                    'processed_total' => $processedTotal,
                    'failure_total' => $failureTotal,
                    'summary_rows_total' => $summaryRows,
                    'source_summary_ready' => true,
                ],
                40,
                null,
                'rebuild-affected-file:' . $fileId . ':offset:' . $nextOffset,
                null,
                3
            );

            $context->checkpoint([
                'stage' => 'continuation',
                'done' => $nextOffset,
                'total' => max(1, $total),
                'percent' => (int)floor(($nextOffset * 90) / max(1, $total)),
                'message' => 'Completed affected dependency chunk through ' . $nextOffset . '/' . $total
                    . '; queued continuation job #' . $continuationJobId . '.',
                'package_name' => $packageName,
                'continuation_job_id' => $continuationJobId,
            ]);
        }

        $gameStats = null;
        if ($nextOffset >= $total) {
            $context->checkpoint([
                'stage' => 'game_stats',
                'done' => max(1, $total),
                'total' => max(1, $total),
                'percent' => 95,
                'message' => 'Refreshing cached game counters.',
                'game_id' => $gameId,
            ]);
            $gameStats = (new PdoGameCatalogStats($this->db))->rebuildGame($gameId);
        }

        return [
            'operation' => 'rebuild_affected_dependencies',
            'file_id' => $fileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$file['original_name'],
            'affected_files' => $total,
            'resume_offset' => $resumeOffset,
            'next_offset' => $nextOffset,
            'chunk_size' => count($chunkIds),
            'processed_files' => $processed,
            'processed_total' => $processedTotal,
            'dependency_summary_rows' => $summaryRows,
            'source_summary_ready' => $sourceSummaryReady,
            'game_stats_refreshed' => $gameStats !== null,
            'continuation_job_id' => $continuationJobId,
            'failure_count' => $failureCount,
            'failure_total' => $failureTotal,
            'failures' => $failures,
            'failures_truncated' => $failureCount > count($failures),
        ];
    }
}
