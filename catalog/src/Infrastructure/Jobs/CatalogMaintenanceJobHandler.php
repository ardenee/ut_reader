<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogMaintenanceJobHandler` for catalog maintenance job handler.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\UploadProgressPruner;

/**
 * Bridges durable maintenance jobs to existing scanner/progress implementations.
 */
final class CatalogMaintenanceJobHandler implements JobHandler
{
    private const MAINTENANCE_WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';
    private const MAINTENANCE_WRITE_LOCK_WAIT = 45;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [
            JobType::REPAIR_SOURCE_IDENTITY_FILE,
            JobType::REPAIR_SOURCE_IDENTITY_GAME,
            JobType::PRUNE_UPLOAD_PROGRESS,
        ], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return match ($job->type) {
            JobType::REPAIR_SOURCE_IDENTITY_FILE => $this->repairSourceIdentityFile($job, $context),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => $this->repairSourceIdentityGame($job, $context),
            JobType::PRUNE_UPLOAD_PROGRESS => $this->pruneUploadProgress($job, $context),
            default => throw new \RuntimeException('Unsupported catalog maintenance job: ' . $job->type),
        };
    }

    private function repairSourceIdentityFile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->requiredPositiveInt($job->payload, 'file_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        require_once __DIR__ . '/../../../lib/CatalogSourceIdentity.php';

        return $this->withMaintenanceWriteLock(function () use ($fileId, $context): array {
            $context->checkpoint([
                'stage' => 'source_identity',
                'done' => 0,
                'total' => 1,
                'percent' => 0,
                'message' => 'Preparing canonical source identity repair.',
            ]);
            $result = \catalog_source_identity_rebuild_file(
                $this->db,
                $this->config,
                $fileId,
                static function (array $progress) use ($context): void {
                    $context->heartbeatIfDue($progress);
                },
                true
            );
            $context->checkpoint([
                'stage' => 'source_identity',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'message' => !empty($result['changed'])
                    ? 'Canonical source identity repair complete.'
                    : 'The file already matches its mounted source path.',
            ]);

            return ['operation' => 'repair_source_identity_file'] + $result;
        });
    }

    private function repairSourceIdentityGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->requiredPositiveInt($job->payload, 'game_id');
        $game = $this->fetchOne('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new \RuntimeException('Game no longer exists: ' . $gameId);
        }

        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        require_once __DIR__ . '/../../../lib/CatalogSourceIdentity.php';

        return $this->withMaintenanceWriteLock(function () use ($gameId, $game, $context): array {
            $statement = $this->db->prepare(
                'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,id'
            );
            $statement->execute([$gameId]);
            $files = $statement->fetchAll(PDO::FETCH_ASSOC);
            $total = count($files);
            $changed = 0;
            $aliases = 0;
            $failureCount = 0;
            $failures = [];

            $context->checkpoint([
                'stage' => 'source_identity',
                'done' => 0,
                'total' => max(1, $total),
                'percent' => 0,
                'message' => $total > 0
                    ? 'Preparing canonical source identity repair for ' . $total . ' files.'
                    : 'No verified files were found for this game.',
                'changed' => 0,
                'aliases' => 0,
                'failures' => 0,
            ]);

            foreach ($files as $index => $file) {
                $fileId = (int)$file['id'];
                $packageName = (string)$file['package_name'];
                $basePercent = (int)floor(($index * 80) / max(1, $total));
                try {
                    $result = \catalog_source_identity_rebuild_file(
                        $this->db,
                        $this->config,
                        $fileId,
                        static function (array $progress) use ($context, $index, $total, $packageName, $basePercent): void {
                            $context->heartbeatIfDue([
                                'stage' => 'source_identity',
                                'done' => $index,
                                'total' => max(1, $total),
                                'percent' => $basePercent,
                                'message' => 'Repairing ' . ($index + 1) . '/' . $total . ': ' . $packageName
                                    . (!empty($progress['message']) ? ' — ' . (string)$progress['message'] : ''),
                            ]);
                        },
                        false
                    );
                    if (!empty($result['changed'])) {
                        $changed++;
                    }
                    $aliases += (int)($result['alias_count'] ?? 0);
                } catch (JobCancellationRequested $error) {
                    throw $error;
                } catch (Throwable $error) {
                    $failureCount++;
                    if (count($failures) < 100) {
                        $failures[] = $packageName . ': ' . $error->getMessage();
                    }
                }

                $context->checkpoint([
                    'stage' => 'source_identity',
                    'done' => $index + 1,
                    'total' => max(1, $total),
                    'percent' => (int)floor((($index + 1) * 80) / max(1, $total)),
                    'message' => 'Processed source identity ' . ($index + 1) . '/' . $total . ': ' . $packageName,
                    'changed' => $changed,
                    'aliases' => $aliases,
                    'failures' => $failureCount,
                ]);
            }

            \scanner_rebuild_game(
                $this->db,
                $this->config,
                $gameId,
                static function (array $progress) use ($context, $total, $changed, $aliases, $failureCount): void {
                    $innerPercent = max(0, min(100, (int)($progress['percent'] ?? 0)));
                    $context->heartbeatIfDue([
                        'stage' => 'dependencies',
                        'done' => $total,
                        'total' => max(1, $total),
                        'percent' => 80 + (int)floor($innerPercent / 5),
                        'message' => (string)($progress['message'] ?? 'Rebuilding dependencies after source identity repair.'),
                        'changed' => $changed,
                        'aliases' => $aliases,
                        'failures' => $failureCount,
                    ]);
                },
                0,
                100
            );

            $context->checkpoint([
                'stage' => 'complete',
                'done' => max(1, $total),
                'total' => max(1, $total),
                'percent' => 100,
                'message' => 'Canonical source identity repair and dependency refresh complete.',
                'changed' => $changed,
                'aliases' => $aliases,
                'failures' => $failureCount,
            ]);

            return [
                'operation' => 'repair_source_identity_game',
                'game_id' => $gameId,
                'game_name' => (string)$game['name'],
                'total' => $total,
                'changed' => $changed,
                'aliases' => $aliases,
                'failure_count' => $failureCount,
                'failures' => $failures,
                'failures_truncated' => $failureCount > count($failures),
                'dependencies_rebuilt' => true,
            ];
        });
    }

    private function pruneUploadProgress(ClaimedJob $job, JobExecutionContext $context): array
    {
        $maxAge = isset($job->payload['max_age_seconds'])
            ? max(60, min((int)$job->payload['max_age_seconds'], 604800))
            : 86400;
        $context->checkpoint(['stage' => 'pruning_upload_progress', 'max_age_seconds' => $maxAge]);
        $removed = (new UploadProgressPruner())->prune($maxAge);
        $context->checkpoint(['stage' => 'pruned_upload_progress', 'removed_files' => $removed]);

        return [
            'max_age_seconds' => $maxAge,
            'removed_files' => $removed,
            'operation' => 'prune_upload_progress',
        ];
    }

    /** @return array<string,mixed>|null */
    private function fetchOne(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function withMaintenanceWriteLock(callable $operation): array
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([self::MAINTENANCE_WRITE_LOCK, self::MAINTENANCE_WRITE_LOCK_WAIT]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Another catalog maintenance write task is running.');
        }

        try {
            $result = $operation();
            if (!is_array($result)) {
                throw new \RuntimeException('Maintenance operation returned an invalid result.');
            }
            return $result;
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::MAINTENANCE_WRITE_LOCK]);
            } catch (Throwable $releaseError) {
                error_log('[UnrealDB jobs] Could not release maintenance write lock: ' . $releaseError->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredPositiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Job payload requires positive ' . $field . '.');
        }
        return $value;
    }
}
