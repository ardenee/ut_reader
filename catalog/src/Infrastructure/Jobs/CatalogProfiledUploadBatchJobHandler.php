<?php
/**
 * Expands one completed browser upload manifest into ordinary durable import jobs.
 *
 * No child import exists while the browser is still uploading. After finalization
 * the coordinator reads a small manifest slice per run, queues import children,
 * then yields so large folders do not monopolize the database.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadBatchStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogProfiledUploadBatchJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 1;
    private const PLAN_BATCH_SIZE = 100;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::PROFILED_UPLOAD_BATCH;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $batchId = strtolower(trim((string)($job->payload['batch_id'] ?? '')));
        $gameId = (int)($job->payload['game_id'] ?? 0);
        $userId = (int)($job->payload['user_id'] ?? 0);
        if (preg_match('/^[a-f0-9]{64}$/', $batchId) !== 1 || $gameId < 1 || $userId < 1) {
            throw new \RuntimeException('Profiled upload batch job payload is invalid.');
        }

        $store = new CatalogProfiledUploadBatchStore($this->config);
        $batch = $store->info($batchId);
        if ((string)($batch['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Profiled upload batch is not finalized.');
        }
        if ((int)($batch['game_id'] ?? 0) !== $gameId || (int)($batch['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Profiled upload batch identity no longer matches its job.');
        }

        $resume = $context->resumeProgress();
        if ((int)($resume['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION) {
            $resume = [];
        }
        $offset = max(0, (int)($resume['manifest_offset'] ?? 0));
        $planned = max(0, (int)($resume['planned_items'] ?? 0));
        $total = max(0, (int)($batch['item_count'] ?? 0));

        $slice = $store->readSlice($batchId, $offset, self::PLAN_BATCH_SIZE);
        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queue = new PdoJobQueue($this->db);

        foreach ($slice['items'] as $item) {
            $stagedPath = (string)$item['staged_path'];
            $originalName = (string)$item['original_name'];
            $type = (string)$item['kind'] === 'pak'
                ? JobType::IMPORT_STAGED_PAK
                : (CatalogArchiveExtractor::isArchiveName($originalName)
                    ? JobType::IMPORT_STAGED_ARCHIVE
                    : JobType::IMPORT_STAGED_PACKAGE);
            $payload = [
                'game_id' => $gameId,
                'staged_path' => $stagedPath,
                'original_name' => $originalName,
                'source_relative_path' => (string)$item['source_relative_path'],
                'strict_profile' => (bool)$item['strict_profile'],
                'user_id' => $userId,
                'size' => (int)$item['size'],
                'profiled_upload_batch_id' => $batchId,
                'profiled_upload_batch_parent_job_id' => $job->id,
            ];
            $unitKey = 'upload:' . hash('sha256', $stagedPath);
            $queue->enqueue(
                $queueName,
                $type,
                $payload,
                5,
                null,
                null,
                $userId,
                3,
                $job->id,
                $unitKey
            );
            $planned++;
        }

        $offset = (int)$slice['next_offset'];
        $percent = $total > 0 ? min(99, (int)floor(($planned * 100) / $total)) : 100;
        $progress = [
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'queue_imports',
            'done' => $planned,
            'total' => max(1, $total),
            'percent' => $percent,
            'manifest_offset' => $offset,
            'planned_items' => $planned,
            'batch_id' => $batchId,
            'message' => 'Queued ' . number_format($planned) . '/' . number_format($total)
                . ' staged upload import job(s).',
        ];

        if (empty($slice['eof'])) {
            // The batch row is only a planner. Its direct import children are
            // independent source/file execution roots, so do not retain strict
            // affinity to the coordinator while later manifest slices wait.
            $context->defer(1, $progress, false);
        }

        $context->checkpoint(array_merge($progress, [
            'stage' => 'complete',
            'done' => max(1, $total),
            'total' => max(1, $total),
            'percent' => 100,
            'message' => 'Upload batch expansion complete: ' . number_format($planned)
                . ' import job(s) queued after browser upload completion.',
        ]));

        return [
            'operation' => 'profiled_upload_batch',
            'status' => 'completed',
            'batch_id' => $batchId,
            'game_id' => $gameId,
            'staged_items' => $total,
            'queued_import_jobs' => $planned,
            'message' => 'Upload batch expansion complete.',
        ];
    }
}
