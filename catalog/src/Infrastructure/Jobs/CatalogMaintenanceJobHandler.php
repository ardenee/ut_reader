<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/**
 * Bridges durable maintenance jobs to existing scanner/progress implementations.
 * Existing synchronous behaviour is unchanged; these handlers are only used by
 * explicit API/CLI queue work until callers choose to enqueue work.
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

    public function handle(ClaimedJob $job): array
    {
        return match ($job->type) {
            JobType::REBUILD_GAME_DEPENDENCIES => $this->rebuildGame($job),
            JobType::REBUILD_AFFECTED_DEPENDENCIES => $this->rebuildAffected($job),
            JobType::PRUNE_UPLOAD_PROGRESS => $this->pruneUploadProgress($job),
            default => throw new \RuntimeException('Unsupported catalog maintenance job: ' . $job->type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuildGame(ClaimedJob $job): array
    {
        $gameId = $this->requiredPositiveInt($job->payload, 'game_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_game($this->db, $this->config, $gameId);

        return ['game_id' => $gameId, 'operation' => 'rebuild_game_dependencies'];
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuildAffected(ClaimedJob $job): array
    {
        $fileId = $this->requiredPositiveInt($job->payload, 'file_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_affected_dependencies($this->db, $this->config, $fileId);

        return ['file_id' => $fileId, 'operation' => 'rebuild_affected_dependencies'];
    }

    /**
     * @return array<string, mixed>
     */
    private function pruneUploadProgress(ClaimedJob $job): array
    {
        $maxAge = isset($job->payload['max_age_seconds'])
            ? max(60, min((int)$job->payload['max_age_seconds'], 604800))
            : 86400;
        require_once __DIR__ . '/../../../lib/UploadProgress.php';
        \upload_progress_cleanup($maxAge, true);

        return ['max_age_seconds' => $maxAge, 'operation' => 'prune_upload_progress'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredPositiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Job payload requires positive ' . $field . '.');
        }
        return $value;
    }
}
