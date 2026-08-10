<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Runs a complete game Full Sync as one durable background job.
 * Why: Game-wide rescans can take many hours and must not depend on a browser tab remaining open.
 * Role: Durable Full Sync coordinator; identity writes remain short/per-file while dependency work uses bounded batches.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncDependencyBatchService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncProjectionService;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogFullSyncJobHandler implements JobHandler
{
    private const FAILURE_SAMPLE_LIMIT = 200;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::FULL_SYNC_GAME;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($job->type !== JobType::FULL_SYNC_GAME) {
            throw new RuntimeException('Unsupported Full Sync job type: ' . $job->type);
        }

        $gameId = (int)($job->payload['game_id'] ?? 0);
        if ($gameId < 1) {
            throw new RuntimeException('Full Sync job requires a positive game_id.');
        }
        $requestedBy = (int)($job->payload['requested_by'] ?? 0);
        $userId = $requestedBy > 0 ? $requestedBy : null;

        $game = $this->one('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new RuntimeException('Full Sync game no longer exists: ' . $gameId);
        }

        $files = $this->verifiedFiles($gameId);
        $total = count($files);
        $reimported = 0;
        $removed = 0;
        $reimportFailures = 0;
        $dependencyFailures = 0;
        $providerPreparationFailed = false;
        $failureSample = [];

        $context->checkpoint($this->progress(
            'full_sync_reimport',
            0,
            max(1, $total),
            0,
            'Full Sync queued for ' . (string)$game['name'] . ': ' . $total . ' verified package(s).',
            $reimported,
            $removed,
            $reimportFailures,
            $dependencyFailures
        ));

        $currentIndex = 0;
        $currentName = '';
        $maintenance = new CatalogFileMaintenanceActionService(
            $this->db,
            $this->config,
            $userId,
            function (array $inner) use (
                $context,
                &$currentIndex,
                &$currentName,
                $total,
                &$reimported,
                &$removed,
                &$reimportFailures,
                &$dependencyFailures
            ): void {
                $innerPercent = max(0, min(100, (int)($inner['percent'] ?? 0)));
                $innerMessage = trim((string)($inner['message'] ?? ''));
                $overall = $this->phasePercent(0, 70, $currentIndex, max(1, $total), $innerPercent);
                $context->heartbeatIfDue($this->progress(
                    'full_sync_reimport',
                    $currentIndex,
                    max(1, $total),
                    $overall,
                    'Reimporting ' . ($currentIndex + 1) . '/' . max(1, $total) . ': ' . $currentName
                        . ($innerMessage !== '' ? ' — ' . $innerMessage : ''),
                    $reimported,
                    $removed,
                    $reimportFailures,
                    $dependencyFailures,
                    ['file_name' => $currentName]
                ));
            }
        );

        foreach ($files as $index => $file) {
            $currentIndex = $index;
            $currentName = (string)$file['original_name'];
            try {
                $result = $maintenance->execute('sync_reimport', [
                    'file_id' => (int)$file['id'],
                    'game_id' => $gameId,
                    'package_name' => (string)$file['package_name'],
                    'md5' => (string)$file['md5'],
                    'package_guid' => (string)($file['package_guid'] ?? ''),
                ]);
                if ((string)($result['status'] ?? '') === 'removed_missing') {
                    $removed++;
                } else {
                    $reimported++;
                }
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (Throwable $error) {
                $reimportFailures++;
                $this->appendFailure(
                    $failureSample,
                    'Re-import failed — ' . $currentName . ': ' . $error->getMessage()
                );
                $this->recordFailure(
                    $gameId,
                    (int)$file['id'],
                    $currentName,
                    'sync_reimport',
                    $error
                );
            }

            $done = $index + 1;
            $context->heartbeatIfDue($this->progress(
                'full_sync_reimport',
                $done,
                max(1, $total),
                $total > 0 ? (int)floor(($done * 70) / $total) : 70,
                'Full Sync package phase ' . $done . '/' . max(1, $total) . ': ' . $currentName,
                $reimported,
                $removed,
                $reimportFailures,
                $dependencyFailures,
                ['file_id' => (int)$file['id'], 'file_name' => $currentName]
            ));
        }

        $context->checkpoint($this->progress(
            'full_sync_providers',
            0,
            100,
            70,
            'Rebuilding package providers before dependency resolution.',
            $reimported,
            $removed,
            $reimportFailures,
            $dependencyFailures
        ));

        $projectionProgress = function (array $inner) use (
            $context,
            &$reimported,
            &$removed,
            &$reimportFailures,
            &$dependencyFailures
        ): void {
            $innerPercent = max(0, min(100, (int)($inner['percent'] ?? 0)));
            $context->heartbeatIfDue($this->progress(
                'full_sync_providers',
                $innerPercent,
                100,
                70 + (int)floor(($innerPercent * 4) / 100),
                (string)($inner['message'] ?? 'Preparing package providers.'),
                $reimported,
                $removed,
                $reimportFailures,
                $dependencyFailures
            ));
        };

        try {
            (new CatalogFullSyncProjectionService($this->db, $projectionProgress))->prepareDependencies($gameId);
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (Throwable $error) {
            $providerPreparationFailed = true;
            $this->appendFailure(
                $failureSample,
                'Provider projection preparation failed: ' . $error->getMessage()
            );
            $this->recordFailure($gameId, 0, (string)$game['name'], 'sync_prepare_dependencies', $error);
        }

        $dependencyFiles = $this->verifiedFiles($gameId);
        $dependencyTotal = count($dependencyFiles);
        $dependencyDone = 0;

        $context->checkpoint($this->progress(
            'full_sync_dependencies',
            0,
            max(1, $dependencyTotal),
            74,
            'Refreshing dependencies for ' . $dependencyTotal . ' verified package(s).',
            $reimported,
            $removed,
            $reimportFailures,
            $dependencyFailures
        ));

        foreach (array_chunk($dependencyFiles, CatalogFullSyncDependencyBatchService::MAX_BATCH_SIZE) as $batch) {
            $batchIds = array_map(static fn(array $row): int => (int)$row['id'], $batch);
            $batchSize = count($batchIds);
            $completedBefore = $dependencyDone;
            $batchProgress = function (array $inner) use (
                $context,
                $dependencyTotal,
                $completedBefore,
                $batchSize,
                &$reimported,
                &$removed,
                &$reimportFailures,
                &$dependencyFailures
            ): void {
                $localDone = max(0, min($batchSize, (int)($inner['done'] ?? 0)));
                $done = min($dependencyTotal, $completedBefore + $localDone);
                $overall = $dependencyTotal > 0
                    ? 74 + (int)floor(($done * 23) / $dependencyTotal)
                    : 97;
                $context->heartbeatIfDue($this->progress(
                    'full_sync_dependencies',
                    $done,
                    max(1, $dependencyTotal),
                    $overall,
                    (string)($inner['message'] ?? 'Refreshing dependency batch.'),
                    $reimported,
                    $removed,
                    $reimportFailures,
                    $dependencyFailures
                ));
            };

            $context->checkpoint($this->progress(
                'full_sync_dependencies',
                $dependencyDone,
                max(1, $dependencyTotal),
                $dependencyTotal > 0
                    ? 74 + (int)floor(($dependencyDone * 23) / $dependencyTotal)
                    : 97,
                'Starting dependency batch at package ' . ($dependencyDone + 1) . '.',
                $reimported,
                $removed,
                $reimportFailures,
                $dependencyFailures
            ));

            $result = (new CatalogFullSyncDependencyBatchService(
                $this->db,
                $this->config,
                $batchProgress
            ))->refresh($gameId, $batchIds);

            $dependencyFailures += (int)($result['failed'] ?? 0);
            foreach ((array)($result['failures'] ?? []) as $failure) {
                if (!is_array($failure)) {
                    continue;
                }
                $this->appendFailure(
                    $failureSample,
                    'Dependency refresh failed — '
                        . (string)($failure['original_name'] ?? ('file #' . (int)($failure['file_id'] ?? 0)))
                        . ': ' . (string)($failure['error'] ?? 'Unknown error')
                );
            }
            $dependencyDone += $batchSize;
        }

        $context->checkpoint($this->progress(
            'full_sync_finalize',
            0,
            100,
            97,
            'Finalizing dependency summaries and cached game statistics.',
            $reimported,
            $removed,
            $reimportFailures,
            $dependencyFailures
        ));

        $finalProgress = function (array $inner) use (
            $context,
            &$reimported,
            &$removed,
            &$reimportFailures,
            &$dependencyFailures
        ): void {
            $innerPercent = max(0, min(100, (int)($inner['percent'] ?? 0)));
            $context->heartbeatIfDue($this->progress(
                'full_sync_finalize',
                $innerPercent,
                100,
                97 + (int)floor(($innerPercent * 3) / 100),
                (string)($inner['message'] ?? 'Finalizing Full Sync projections.'),
                $reimported,
                $removed,
                $reimportFailures,
                $dependencyFailures
            ));
        };

        try {
            $final = (new CatalogFullSyncProjectionService($this->db, $finalProgress))->finalize($gameId);
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->recordFailure($gameId, 0, (string)$game['name'], 'sync_finalize_game', $error);
            throw new RuntimeException('Full Sync finalization failed: ' . $error->getMessage(), 0, $error);
        }

        $stats = is_array($final['stats'] ?? null) ? $final['stats'] : [];
        $failureCount = $reimportFailures + $dependencyFailures + ($providerPreparationFailed ? 1 : 0);
        $message = 'Full Sync complete for ' . (string)$game['name']
            . ': reimported=' . $reimported
            . ', removed=' . $removed
            . ', reimport failures=' . $reimportFailures
            . ', dependency failures=' . $dependencyFailures
            . ', missing dependencies=' . (int)($stats['missing_dependency_count'] ?? 0)
            . ', missing packages=' . (int)($stats['missing_package_count'] ?? 0) . '.';

        $context->checkpoint($this->progress(
            'complete',
            100,
            100,
            100,
            $message,
            $reimported,
            $removed,
            $reimportFailures,
            $dependencyFailures,
            [
                'missing_dependency_count' => (int)($stats['missing_dependency_count'] ?? 0),
                'missing_package_count' => (int)($stats['missing_package_count'] ?? 0),
                'failure_count' => $failureCount,
            ]
        ));

        return [
            'operation' => 'full_sync_game',
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'initial_verified_files' => $total,
            'final_verified_files' => $dependencyTotal,
            'reimported' => $reimported,
            'removed_missing' => $removed,
            'reimport_failure_count' => $reimportFailures,
            'dependency_failure_count' => $dependencyFailures,
            'provider_preparation_failed' => $providerPreparationFailed,
            'failure_count' => $failureCount,
            'failure_sample' => $failureSample,
            'failure_sample_truncated' => $failureCount > count($failureSample),
            'stats' => $stats,
            'message' => $message,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function verifiedFiles(int $gameId): array
    {
        $statement = $this->db->prepare(
            'SELECT id,original_name,package_name,md5,package_guid FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" ORDER BY package_name,original_name,id'
        );
        $statement->execute([$gameId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<mixed> $arguments @return array<string,mixed>|null */
    private function one(string $sql, array $arguments): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<string> $failures */
    private function appendFailure(array &$failures, string $message): void
    {
        if (count($failures) < self::FAILURE_SAMPLE_LIMIT) {
            $failures[] = $message;
        }
    }

    private function recordFailure(
        int $gameId,
        int $fileId,
        string $name,
        string $operation,
        Throwable $error
    ): void {
        CatalogSystemErrorRecorder::record([
            'source_kind' => 'full-sync-job',
            'severity' => 'error',
            'error_type' => get_class($error),
            'message' => $operation . ' failed for ' . $name . ': ' . $error->getMessage(),
            'source_file' => $error->getFile(),
            'source_line' => $error->getLine(),
            'trace_text' => $error->getTraceAsString(),
            'context' => [
                'operation' => $operation,
                'game_id' => $gameId,
                'file_id' => $fileId,
                'original_name' => $name,
            ],
        ]);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(
        string $stage,
        int $done,
        int $total,
        int $percent,
        string $message,
        int $reimported,
        int $removed,
        int $reimportFailures,
        int $dependencyFailures,
        array $extra = []
    ): array {
        return [
            'stage' => $stage,
            'done' => $done,
            'total' => $total,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
            'reimported' => $reimported,
            'removed_missing' => $removed,
            'reimport_failures' => $reimportFailures,
            'dependency_failures' => $dependencyFailures,
        ] + $extra;
    }

    private function phasePercent(int $start, int $end, int $index, int $total, int $innerPercent): int
    {
        $total = max(1, $total);
        $position = max(0, min($total, $index + ($innerPercent / 100)));
        return $start + (int)floor((($end - $start) * $position) / $total);
    }
}
