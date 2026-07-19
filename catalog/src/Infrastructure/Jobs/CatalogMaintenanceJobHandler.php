<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
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
        return in_array($jobType, JobType::all(), true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return match ($job->type) {
            JobType::REBUILD_GAME_DEPENDENCIES => $this->rebuildGame($job, $context),
            JobType::REBUILD_FILE_DEPENDENCIES => $this->rebuildFile($job, $context),
            JobType::REBUILD_AFFECTED_DEPENDENCIES => $this->rebuildAffected($job, $context),
            JobType::REPAIR_SOURCE_IDENTITY_FILE => $this->repairSourceIdentityFile($job, $context),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => $this->repairSourceIdentityGame($job, $context),
            JobType::PRUNE_UPLOAD_PROGRESS => $this->pruneUploadProgress($job, $context),
            default => throw new \RuntimeException('Unsupported catalog maintenance job: ' . $job->type),
        };
    }

    private function rebuildGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->requiredPositiveInt($job->payload, 'game_id');
        $offset = max(0, (int)($job->payload['offset'] ?? 0));
        $game = $this->fetchOne('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new \RuntimeException('Game no longer exists: ' . $gameId);
        }

        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        $statement = $this->db->prepare(
            'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" '
            . 'ORDER BY package_name,id LIMIT 18446744073709551615 OFFSET ' . $offset
        );
        $statement->execute([$gameId]);
        $files = $statement->fetchAll(PDO::FETCH_ASSOC);
        $total = count($files);
        $processedIds = [];

        if ($total === 0) {
            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'message' => 'No verified files were found for the selected game and offset.',
            ]);
        }

        foreach ($files as $index => $file) {
            $fileId = (int)$file['id'];
            $processedIds[] = $fileId;
            $start = (int)floor(($index * 100) / max(1, $total));
            $end = (int)floor((($index + 1) * 100) / max(1, $total));
            \scanner_rebuild_dependencies(
                $this->db,
                $this->config,
                $fileId,
                static function (array $progress) use ($context): void {
                    $context->heartbeatIfDue($progress);
                },
                $start,
                $end,
                'Refreshing game dependency links ' . ($index + 1) . '/' . $total . ' (' . (string)$file['package_name'] . ')'
            );
        }

        return [
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'offset' => $offset,
            'processed_files' => $total,
            'operation' => 'rebuild_game_dependencies',
            'stats' => $this->dependencyStatsForFileIds($processedIds),
        ];
    }

    private function rebuildFile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->requiredPositiveInt($job->payload, 'file_id');
        $file = $this->fetchOne(
            'SELECT id,game_id,original_name,package_name FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if ($file === null) {
            throw new \RuntimeException('Verified file no longer exists: ' . $fileId);
        }

        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_dependencies(
            $this->db,
            $this->config,
            $fileId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue($progress);
            },
            0,
            100,
            'Refreshing file dependency links'
        );

        return [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'original_name' => (string)$file['original_name'],
            'package_name' => (string)$file['package_name'],
            'operation' => 'rebuild_file_dependencies',
            'stats' => $this->dependencyStatsForFileIds([$fileId]),
        ];
    }

    private function rebuildAffected(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->requiredPositiveInt($job->payload, 'file_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_affected_dependencies(
            $this->db,
            $this->config,
            $fileId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue($progress);
            },
            0,
            100
        );

        return ['file_id' => $fileId, 'operation' => 'rebuild_affected_dependencies'];
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

    /** @param list<int> $fileIds
     *  @return array{total:int,resolved:int,missing:int,package_only:int,common:int}
     */
    private function dependencyStatsForFileIds(array $fileIds): array
    {
        $stats = [
            'total' => 0,
            'resolved' => 0,
            'missing' => 0,
            'package_only' => 0,
            'common' => 0,
        ];
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn(int $id): bool => $id > 0)));
        if ($fileIds === []) {
            return $stats;
        }

        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_dependencies WHERE file_id IN ('
            . implode(',', array_fill(0, count($fileIds), '?'))
            . ') GROUP BY status'
        );
        $statement->execute($fileIds);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $status = (string)($row['status'] ?? '');
            $count = (int)($row['c'] ?? 0);
            $stats['total'] += $count;
            if (array_key_exists($status, $stats)) {
                $stats[$status] += $count;
            }
        }
        return $stats;
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
