<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
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
