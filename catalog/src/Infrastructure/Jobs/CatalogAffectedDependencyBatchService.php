<?php
/**
 * Executes one bounded batch of targeted affected-dependency owners.
 *
 * The common path deliberately avoids one durable queue row per file. Each file
 * still owns its compact metadata lock and targeted package resolution, while a
 * bad file is split into its own retry row so the rest of the batch continues.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogAffectedDependencyBatchService
{
    public const MAX_BATCH_SIZE = 250;
    private const FILE_CONTENTION_ATTEMPTS = 4;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    public function run(ClaimedJob $job, JobExecutionContext $context, array $source): array
    {
        $fileIds = $this->normalizeFileIds($job->payload['affected_file_ids'] ?? null);
        $total = count($fileIds);
        $sourceFileId = (int)($source['id'] ?? 0);
        $gameId = (int)($source['game_id'] ?? 0);
        $packageName = trim((string)($source['package_name'] ?? ''));
        if ($sourceFileId < 1 || $gameId < 1 || $packageName === '') {
            throw new RuntimeException('Affected dependency batch has no valid source provider.');
        }

        $batchKey = trim((string)($job->workflowUnitKey ?? ''));
        if ($batchKey === '') {
            $batchKey = 'affected:batch:job-' . $job->id;
        }

        $resume = $context->resumeProgress();
        $resuming = (string)($resume['stage'] ?? '') === 'affected_batch'
            && (string)($resume['batch_key'] ?? '') === $batchKey
            && (int)($resume['total'] ?? 0) === $total;

        $done = $resuming ? max(0, min($total, (int)($resume['done'] ?? 0))) : 0;
        $processedIds = $this->idSet($resuming ? ($resume['processed_file_ids'] ?? []) : []);
        $changedIds = $this->idSet($resuming ? ($resume['changed_file_ids'] ?? []) : []);
        $skippedIds = $this->idSet($resuming ? ($resume['skipped_file_ids'] ?? []) : []);
        $retryIds = $this->idSet($resuming ? ($resume['retry_file_ids'] ?? []) : []);
        $importsProcessed = $resuming ? max(0, (int)($resume['imports_processed'] ?? 0)) : 0;
        $dependenciesChanged = $resuming ? max(0, (int)($resume['dependencies_changed'] ?? 0)) : 0;
        $containersRewritten = $resuming ? max(0, (int)($resume['containers_rewritten'] ?? 0)) : 0;

        $rebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);
        $queue = new PdoJobQueue($this->db);

        for ($index = $done; $index < $total; $index++) {
            $affectedFileId = $fileIds[$index];
            $failure = null;
            try {
                $result = $this->rebuildWithContentionRetry($rebuilder, $affectedFileId, $packageName);
                if (!empty($result['skipped_missing_file'])) {
                    $skippedIds[$affectedFileId] = true;
                } else {
                    $processedIds[$affectedFileId] = true;
                    $fileChanges = max(0, (int)($result['dependencies_changed'] ?? 0));
                    if ($fileChanges > 0) {
                        $changedIds[$affectedFileId] = true;
                    }
                    $importsProcessed += max(0, (int)($result['imports_processed'] ?? 0));
                    $dependenciesChanged += $fileChanges;
                    if (!empty($result['container_rewritten'])) {
                        $containersRewritten++;
                    }
                }
            } catch (Throwable $error) {
                $failure = $error;
                // Transient database contention is an execution condition, not a
                // corrupt-file/system-error event. It receives an isolated retry
                // without polluting the system error log. Non-contention failures
                // remain visible for diagnosis.
                if (!PdoContention::retryable($error)) {
                    $this->recordFailure($job, $affectedFileId, $packageName, $error);
                }
                try {
                    $queue->enqueue(
                        $job->queue,
                        JobType::REBUILD_AFFECTED_DEPENDENCIES,
                        [
                            'file_id' => $sourceFileId,
                            'game_id' => $gameId,
                            'package_name' => $packageName,
                            'affected_file_id' => $affectedFileId,
                            'workflow_parent_job_id' => $job->rootJobId(),
                            'retry_of_batch_job_id' => $job->id,
                        ],
                        40,
                        null,
                        null,
                        null,
                        3,
                        $job->rootJobId(),
                        'affected:retry:' . $affectedFileId
                    );
                    $retryIds[$affectedFileId] = true;
                } catch (Throwable $retryError) {
                    throw new RuntimeException(
                        'Affected file #' . $affectedFileId . ' failed and its durable retry could not be queued: '
                        . $retryError->getMessage(),
                        0,
                        $error
                    );
                }
            }

            $done = $index + 1;
            $progress = $this->progress(
                $batchKey,
                $done,
                $total,
                $processedIds,
                $changedIds,
                $skippedIds,
                $retryIds,
                $importsProcessed,
                $dependenciesChanged,
                $containersRewritten,
                $failure instanceof Throwable
                    ? (PdoContention::retryable($failure)
                        ? 'Affected file #' . $affectedFileId . ' remained contended after local retries; queued an isolated retry and continuing.'
                        : 'Affected file #' . $affectedFileId . ' failed in the batch; queued an isolated retry and continuing.')
                    : 'Targeted dependency batch ' . $done . '/' . $total . ' for ' . $packageName . '.'
            );

            // A failure boundary is checkpointed immediately so the retry and
            // cursor remain in sync. Successful files use the normal heartbeat
            // cadence, avoiding one queue UPDATE for every file.
            if ($failure instanceof Throwable) {
                $context->checkpoint($progress);
            } else {
                $context->heartbeatIfDue($progress);
            }
        }

        $context->checkpoint($this->progress(
            $batchKey,
            $total,
            $total,
            $processedIds,
            $changedIds,
            $skippedIds,
            $retryIds,
            $importsProcessed,
            $dependenciesChanged,
            $containersRewritten,
            'Affected dependency batch complete: ' . count($processedIds) . ' refreshed, '
                . count($changedIds) . ' changed, ' . count($skippedIds) . ' skipped, '
                . count($retryIds) . ' isolated retry file(s).',
            'complete'
        ));

        return [
            'operation' => 'rebuild_affected_dependency_batch',
            'mode' => 'batch',
            'file_id' => $sourceFileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'requested_files' => $total,
            'processed_files' => count($processedIds),
            'changed_files' => count($changedIds),
            'skipped_files' => count($skippedIds),
            'retry_scheduled' => count($retryIds),
            'processed_file_ids' => array_map('intval', array_keys($processedIds)),
            'changed_file_ids' => array_map('intval', array_keys($changedIds)),
            'skipped_file_ids' => array_map('intval', array_keys($skippedIds)),
            'retry_file_ids' => array_map('intval', array_keys($retryIds)),
            'imports_processed' => $importsProcessed,
            'dependencies_changed' => $dependenciesChanged,
            'containers_rewritten' => $containersRewritten,
        ];
    }

    /** @return array<string,mixed> */
    private function rebuildWithContentionRetry(
        PdoCatalogDependencyRebuilder $rebuilder,
        int $fileId,
        string $packageName
    ): array {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $rebuilder->rebuildForPackages($fileId, [$packageName], false);
            } catch (Throwable $error) {
                if (!PdoContention::retryable($error) || $attempt >= self::FILE_CONTENTION_ATTEMPTS) {
                    throw $error;
                }
                usleep(PdoContention::backoffMicros($attempt, 25000));
            }
        }
    }

    /** @return list<int> */
    private function normalizeFileIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException('Affected dependency batch requires affected_file_ids.');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === [] || count($ids) > self::MAX_BATCH_SIZE) {
            throw new RuntimeException(
                'Affected dependency batch must contain 1-' . self::MAX_BATCH_SIZE . ' valid file IDs.'
            );
        }
        return $ids;
    }

    /** @return array<int,true> */
    private function idSet(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $set = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $set[$id] = true;
            }
        }
        return $set;
    }

    /**
     * @param array<int,true> $processedIds
     * @param array<int,true> $changedIds
     * @param array<int,true> $skippedIds
     * @param array<int,true> $retryIds
     * @return array<string,mixed>
     */
    private function progress(
        string $batchKey,
        int $done,
        int $total,
        array $processedIds,
        array $changedIds,
        array $skippedIds,
        array $retryIds,
        int $importsProcessed,
        int $dependenciesChanged,
        int $containersRewritten,
        string $message,
        string $stage = 'affected_batch'
    ): array {
        return [
            'stage' => $stage,
            'batch_key' => $batchKey,
            'done' => $done,
            'total' => $total,
            'percent' => (int)floor(($done * 100) / max(1, $total)),
            'completed_files' => count($processedIds) + count($skippedIds),
            'processed_file_ids' => array_map('intval', array_keys($processedIds)),
            'changed_file_ids' => array_map('intval', array_keys($changedIds)),
            'skipped_file_ids' => array_map('intval', array_keys($skippedIds)),
            'retry_file_ids' => array_map('intval', array_keys($retryIds)),
            'imports_processed' => $importsProcessed,
            'dependencies_changed' => $dependenciesChanged,
            'containers_rewritten' => $containersRewritten,
            'message' => $message,
        ];
    }

    private function recordFailure(ClaimedJob $job, int $fileId, string $packageName, Throwable $error): void
    {
        try {
            CatalogSystemErrorRecorder::record([
                'source_kind' => 'background-job',
                'severity' => 'error',
                'error_type' => get_class($error),
                'message' => 'Affected dependency batch #' . $job->id . ' file #' . $fileId
                    . ' failed; isolated retry queued: ' . $error->getMessage(),
                'source_file' => $error->getFile(),
                'source_line' => $error->getLine(),
                'trace_text' => $error->getTraceAsString(),
                'context' => [
                    'operation' => 'rebuild_affected_dependency_batch',
                    'batch_job_id' => $job->id,
                    'parent_job_id' => $job->rootJobId(),
                    'file_id' => $fileId,
                    'package_name' => $packageName,
                    'disposition' => 'isolated_retry_queued',
                ],
            ]);
        } catch (Throwable $recordError) {
            error_log('[UnrealDB affected dependency batch] Could not record file failure: '
                . $recordError->getMessage());
        }
    }
}
