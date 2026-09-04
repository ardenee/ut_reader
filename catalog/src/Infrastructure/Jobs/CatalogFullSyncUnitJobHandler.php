<?php
/**
 * Executes one durable Full Sync unit. A failed package affects only this row;
 * successful sibling units are never replayed by workflow recovery.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncDependencyBatchService;

final class CatalogFullSyncUnitJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::FULL_SYNC_FILE, JobType::FULL_SYNC_DEPENDENCY_FILE], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return $job->type === JobType::FULL_SYNC_FILE
            ? $this->reimport($job, $context)
            : $this->dependencies($job, $context);
    }

    /** @return array<string,mixed> */
    private function reimport(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positive($job->payload, 'game_id');
        $fileId = $this->positive($job->payload, 'file_id');
        $file = $this->file($gameId, $fileId);
        if ($file === null) {
            return [
                'operation' => 'full_sync_file',
                'game_id' => $gameId,
                'file_id' => $fileId,
                'status' => 'already_removed',
                'message' => 'The Full Sync file unit is already absent; no work remains.',
            ];
        }

        $name = (string)$file['original_name'];
        $context->checkpoint([
            'stage' => 'full_sync_file',
            'done' => 0,
            'total' => 1,
            'percent' => 5,
            'file_id' => $fileId,
            'file_name' => $name,
            'message' => 'Reimporting ' . $name . '.',
        ]);

        $maintenance = new CatalogFileMaintenanceActionService(
            $this->db,
            $this->config,
            (int)($job->payload['requested_by'] ?? 0) ?: null,
            static function (array $progress) use ($context, $fileId, $name): void {
                $progress['file_id'] = $fileId;
                $progress['file_name'] = $name;
                $context->heartbeatIfDue($progress);
            }
        );

        // Full Sync is a reconciliation operation, not a destructive validity sweep.
        // A parser/reader regression or newly tightened validation must never delete
        // an otherwise present verified package. Let the child fail visibly so the
        // operator can inspect/retry it while preserving the file and stable row.
        $result = $maintenance->execute('sync_reimport', [
            'file_id' => $fileId,
            'game_id' => $gameId,
            'package_name' => (string)$file['package_name'],
            'md5' => (string)$file['md5'],
            'package_guid' => (string)($file['package_guid'] ?? ''),
        ]);

        $status = (string)($result['status'] ?? 'reimported');
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'file_id' => $fileId,
            'file_name' => $name,
            'message' => 'Full Sync package unit complete for ' . $name . '.',
        ]);

        return [
            'operation' => 'full_sync_file',
            'game_id' => $gameId,
            'file_id' => $fileId,
            'status' => $status,
            'message' => 'Full Sync package unit complete for ' . $name . '.',
        ];
    }

    /** @return array<string,mixed> */
    private function dependencies(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positive($job->payload, 'game_id');
        $fileId = $this->positive($job->payload, 'file_id');
        $file = $this->file($gameId, $fileId);
        if ($file === null) {
            return [
                'operation' => 'full_sync_dependency_file',
                'game_id' => $gameId,
                'file_id' => $fileId,
                'status' => 'already_removed',
                'message' => 'The dependency owner is no longer verified; no dependency work remains.',
            ];
        }

        $name = (string)$file['original_name'];
        $context->checkpoint([
            'stage' => 'full_sync_dependency_file',
            'done' => 0,
            'total' => 1,
            'percent' => 5,
            'file_id' => $fileId,
            'file_name' => $name,
            'message' => 'Refreshing dependencies for ' . $name . '.',
        ]);

        $result = (new CatalogFullSyncDependencyBatchService(
            $this->db,
            $this->config,
            static function (array $progress) use ($context, $fileId, $name): void {
                $progress['file_id'] = $fileId;
                $progress['file_name'] = $name;
                $context->heartbeatIfDue($progress);
            },
            false
        ))->refresh($gameId, [$fileId]);

        if ((int)($result['failed'] ?? 0) > 0) {
            $failure = is_array($result['failures'][0] ?? null) ? $result['failures'][0] : [];
            throw new \RuntimeException(
                'Dependency refresh failed for ' . $name . ': '
                . trim((string)($failure['error'] ?? 'Unknown dependency refresh error.'))
            );
        }

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'file_id' => $fileId,
            'file_name' => $name,
            'message' => 'Dependency unit complete for ' . $name . '.',
        ]);

        return [
            'operation' => 'full_sync_dependency_file',
            'game_id' => $gameId,
            'file_id' => $fileId,
            'status' => 'completed',
            'metadata_repairs' => (int)($result['metadata_repairs'] ?? 0),
            'message' => 'Dependency unit complete for ' . $name . '.',
        ];
    }

    /** @return array<string,mixed>|null */
    private function file(int $gameId, int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,md5,package_guid FROM ue_files '
            . 'WHERE id=? AND game_id=? AND scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId, $gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $payload */
    private function positive(array $payload, string $key): int
    {
        $value = (int)($payload[$key] ?? 0);
        if ($value < 1) {
            throw new \RuntimeException('Full Sync unit requires a positive ' . $key . '.');
        }
        return $value;
    }
}
