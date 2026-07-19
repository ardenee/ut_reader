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
        $game = $this->fetchOne('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new \RuntimeException('Game no longer exists: ' . $gameId);
        }

        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_game(
            $this->db,
            $this->config,
            $gameId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue($progress);
            },
            0,
            100
        );

        return [
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'operation' => 'rebuild_game_dependencies',
            'stats' => $this->dependencyStats('JOIN ue_files f ON f.id=d.file_id WHERE f.game_id=?', [$gameId]),
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
            'stats' => $this->dependencyStats('WHERE d.file_id=?', [$fileId]),
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

    /** @return array{total:int,resolved:int,missing:int,package_only:int,common:int} */
    private function dependencyStats(string $scopeSql, array $params): array
    {
        $stats = [
            'total' => 0,
            'resolved' => 0,
            'missing' => 0,
            'package_only' => 0,
            'common' => 0,
        ];
        $statement = $this->db->prepare('SELECT d.status,COUNT(*) c FROM ue_dependencies d ' . $scopeSql . ' GROUP BY d.status');
        $statement->execute($params);
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
