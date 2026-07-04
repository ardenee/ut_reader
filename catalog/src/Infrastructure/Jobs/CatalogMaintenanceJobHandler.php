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
            JobType::REBUILD_AFFECTED_DEPENDENCIES => $this->rebuildAffected($job, $context),
            JobType::PRUNE_UPLOAD_PROGRESS => $this->pruneUploadProgress($job, $context),
            default => throw new \RuntimeException('Unsupported catalog maintenance job: ' . $job->type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuildGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->requiredPositiveInt($job->payload, 'game_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_game(
            $this->db,
            $this->config,
            $gameId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue();
            }
        );

        return ['game_id' => $gameId, 'operation' => 'rebuild_game_dependencies'];
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuildAffected(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->requiredPositiveInt($job->payload, 'file_id');
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        \scanner_rebuild_affected_dependencies(
            $this->db,
            $this->config,
            $fileId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue();
            }
        );

        return ['file_id' => $fileId, 'operation' => 'rebuild_affected_dependencies'];
    }

    /**
     * @return array<string, mixed>
     */
    private function pruneUploadProgress(ClaimedJob $job, JobExecutionContext $context): array
    {
        $context->heartbeatIfDue();
        $maxAge = isset($job->payload['max_age_seconds'])
            ? max(60, min((int)$job->payload['max_age_seconds'], 604800))
            : 86400;
        $removed = (new UploadProgressPruner())->prune($maxAge);
        $context->heartbeatIfDue();

        return [
            'max_age_seconds' => $maxAge,
            'removed_files' => $removed,
            'operation' => 'prune_upload_progress',
        ];
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
