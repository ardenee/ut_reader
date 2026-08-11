<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Revalidates selected sibling-game providers and queues their destination imports outside the HTTP request.
 * Why: Cross-game batch selection may involve many packages; queue preparation must not copy/hash/revalidate them serially in the browser request.
 * Role: Durable parent job that reports preparation progress and creates normal child IMPORT_STAGED_PACKAGE jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogCrossGamePackageCopyService;

final class CatalogCrossGameCopyBatchJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::CROSS_GAME_COPY_BATCH;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($job->payload['source_file_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        $destinationGameId = (int)($job->payload['destination_game_id'] ?? 0);
        $userId = (int)($job->payload['user_id'] ?? 0);
        $userId = $userId > 0 ? $userId : null;
        if ($sourceIds === [] || $destinationGameId < 1) {
            throw new \RuntimeException('Cross-game copy batch requires source files and a destination game.');
        }
        if (count($sourceIds) > 1000) {
            throw new \RuntimeException('Cross-game copy batch is limited to 1,000 source files.');
        }

        $destination = \catalog_one(
            $this->db,
            'SELECT id,name FROM ue_games WHERE id=? LIMIT 1',
            [$destinationGameId]
        );
        if (!$destination) {
            throw new \RuntimeException('Cross-game copy destination no longer exists.');
        }

        $service = new CatalogCrossGamePackageCopyService($this->db, $this->config);
        $total = count($sourceIds);
        $started = microtime(true);
        $queued = 0;
        $deduplicated = 0;
        $skipped = 0;
        $failed = 0;
        $childJobIds = [];
        $issues = [];

        $context->checkpoint($this->progress(
            0,
            $total,
            $queued,
            $deduplicated,
            $skipped,
            $failed,
            $started,
            '',
            'Preparing ' . $total . ' selected package(s) for ' . (string)$destination['name'] . '.'
        ));

        foreach ($sourceIds as $index => $sourceFileId) {
            $name = (string)(\catalog_one(
                $this->db,
                'SELECT original_name FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
                [$sourceFileId]
            )['original_name'] ?? ('file #' . $sourceFileId));

            $context->checkpoint($this->progress(
                $index,
                $total,
                $queued,
                $deduplicated,
                $skipped,
                $failed,
                $started,
                $name,
                'Revalidating and queueing ' . $name . ' (' . ($index + 1) . ' of ' . $total . ').'
            ));

            try {
                $result = $service->queue($sourceFileId, $destinationGameId, $userId);
                $childJobId = (int)($result['job_id'] ?? 0);
                if ($childJobId > 0) {
                    $childJobIds[$childJobId] = true;
                }
                if (!empty($result['deduplicated'])) {
                    $deduplicated++;
                } else {
                    $queued++;
                }
            } catch (Throwable $error) {
                $message = trim($error->getMessage());
                if ($this->isExpectedSkip($message)) {
                    $skipped++;
                } else {
                    $failed++;
                }
                if (count($issues) < 100) {
                    $issues[] = [
                        'source_file_id' => $sourceFileId,
                        'file' => $name,
                        'status' => $this->isExpectedSkip($message) ? 'skipped' : 'failed',
                        'message' => $message !== '' ? $message : get_class($error),
                    ];
                }
            }

            $done = $index + 1;
            $context->checkpoint($this->progress(
                $done,
                $total,
                $queued,
                $deduplicated,
                $skipped,
                $failed,
                $started,
                $name,
                'Prepared ' . $done . ' of ' . $total . ' package(s): '
                    . $queued . ' queued, ' . $deduplicated . ' already queued, '
                    . $skipped . ' skipped, ' . $failed . ' failed.'
            ));
        }

        $elapsed = max(0.0, microtime(true) - $started);
        $message = 'Cross-game queue preparation complete for ' . (string)$destination['name'] . ': '
            . $queued . ' queued, ' . $deduplicated . ' already queued, '
            . $skipped . ' skipped, ' . $failed . ' failed. '
            . 'Import jobs continue independently in Background Jobs.';
        $context->checkpoint($this->progress(
            $total,
            $total,
            $queued,
            $deduplicated,
            $skipped,
            $failed,
            $started,
            '',
            $message
        ) + ['stage' => 'complete', 'eta_seconds' => 0]);

        return [
            'operation' => 'cross_game_copy_batch',
            'status' => 'completed',
            'destination_game_id' => $destinationGameId,
            'destination_game' => (string)$destination['name'],
            'selected' => $total,
            'queued' => $queued,
            'deduplicated' => $deduplicated,
            'skipped' => $skipped,
            'failed' => $failed,
            'elapsed_seconds' => round($elapsed, 2),
            'child_job_ids' => array_map('intval', array_keys($childJobIds)),
            'issues' => $issues,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function progress(
        int $done,
        int $total,
        int $queued,
        int $deduplicated,
        int $skipped,
        int $failed,
        float $started,
        string $currentFile,
        string $message
    ): array {
        $elapsed = max(0.0, microtime(true) - $started);
        $eta = null;
        if ($done > 0 && $done < $total) {
            $eta = max(0, (int)round(($elapsed / $done) * ($total - $done)));
        }
        return [
            'stage' => 'cross_game_copy_batch',
            'done' => $done,
            'total' => max(1, $total),
            'percent' => $total > 0 ? (int)floor($done * 100 / $total) : 100,
            'queued' => $queued,
            'deduplicated' => $deduplicated,
            'skipped' => $skipped,
            'failed' => $failed,
            'current_file' => $currentFile,
            'elapsed_seconds' => round($elapsed, 1),
            'eta_seconds' => $eta,
            'message' => $message,
        ];
    }

    private function isExpectedSkip(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'no longer exports an object required by a missing dependency')
            || str_contains($message, 'already verified in the target game')
            || str_contains($message, 'same package bytes are already verified');
    }
}
