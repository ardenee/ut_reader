<?php
/**
 * Repairs one verified file's format-2 metadata from its authoritative stored
 * package and then runs the normal durable affected-dependency workflow.
 *
 * The repair is intentionally one-file bounded. If the compact container is
 * already valid when the job starts/restarts, reparsing is skipped and only the
 * dependency follow-up is ensured. A failed dependency child can be restarted
 * without reparsing the repaired package.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogCompactMetadataRepairJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REPAIR_COMPACT_METADATA_FILE;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = (int)($job->payload['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('Compact metadata repair requires a positive file_id.');
        }
        $file = $this->file($fileId);
        if ($file === null) {
            return [
                'operation' => 'repair_compact_metadata_file',
                'file_id' => $fileId,
                'status' => 'already_removed',
                'message' => 'The verified file no longer exists; compact metadata repair is no longer required.',
            ];
        }

        $requestedBy = (int)($job->payload['requested_by'] ?? 0);
        $requestedBy = $requestedBy > 0 ? $requestedBy : null;
        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'compact_repair_verify';
        }

        $repaired = !empty($resume['repaired']);
        if ($stage === 'compact_repair_verify') {
            $valid = $this->compactMetadataValid($fileId);
            if (!$valid) {
                $context->checkpoint($this->progress(
                    'compact_repair_reimport',
                    10,
                    'Compact metadata is unreadable for ' . (string)$file['original_name']
                        . '; reparsing the authoritative stored package.',
                    ['file_id' => $fileId, 'repaired' => false]
                ));
                $stage = 'compact_repair_reimport';
            } else {
                CatalogSystemErrorRecorder::resolveCompactMetadataProvider($fileId);
                $context->checkpoint($this->progress(
                    'compact_repair_dependency_plan',
                    55,
                    'Compact metadata already verifies for ' . (string)$file['original_name']
                        . '; ensuring affected dependencies are refreshed.',
                    ['file_id' => $fileId, 'repaired' => $repaired]
                ));
                $stage = 'compact_repair_dependency_plan';
            }
        }

        if ($stage === 'compact_repair_reimport') {
            $context->heartbeatIfDue($this->progress(
                'compact_repair_reimport',
                20,
                'Rebuilding format-2 metadata for ' . (string)$file['original_name'] . '.',
                ['file_id' => $fileId]
            ));
            $maintenance = new CatalogFileMaintenanceActionService(
                $this->db,
                $this->config,
                $requestedBy,
                static function (array $inner) use ($context, $fileId): void {
                    $innerPercent = max(0, min(100, (int)($inner['percent'] ?? 0)));
                    $context->heartbeatIfDue([
                        'stage' => 'compact_repair_reimport',
                        'done' => $innerPercent,
                        'total' => 100,
                        'percent' => 10 + (int)floor(($innerPercent * 40) / 100),
                        'file_id' => $fileId,
                        'message' => (string)($inner['message'] ?? 'Reparsing stored package.'),
                    ]);
                }
            );
            $maintenance->execute('sync_reimport', [
                'file_id' => $fileId,
                'game_id' => (int)$file['game_id'],
                'package_name' => (string)$file['package_name'],
                'md5' => (string)$file['md5'],
                'package_guid' => (string)($file['package_guid'] ?? ''),
            ]);
            if (!$this->compactMetadataValid($fileId)) {
                throw new RuntimeException(
                    'Compact metadata still does not verify after reparsing file #' . $fileId . '.'
                );
            }
            CatalogSystemErrorRecorder::resolveCompactMetadataProvider($fileId);
            $repaired = true;
            $context->checkpoint($this->progress(
                'compact_repair_dependency_plan',
                55,
                'Format-2 metadata repaired for ' . (string)$file['original_name']
                    . '; planning affected dependency refresh.',
                ['file_id' => $fileId, 'repaired' => true]
            ));
            $stage = 'compact_repair_dependency_plan';
        }

        if ($stage === 'compact_repair_dependency_plan') {
            $dependencyJobId = (new PdoJobQueue($this->db))->enqueue(
                $job->queue,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                ['file_id' => $fileId, 'requested_by' => $requestedBy],
                20,
                null,
                null,
                $requestedBy,
                5,
                $job->id,
                'dependencies'
            );
            $context->checkpoint($this->progress(
                'compact_repair_dependency_wait',
                60,
                'Affected dependency workflow #' . $dependencyJobId . ' queued for repaired provider file #' . $fileId . '.',
                [
                    'file_id' => $fileId,
                    'repaired' => $repaired,
                    'dependency_job_id' => $dependencyJobId,
                ]
            ));
            $stage = 'compact_repair_dependency_wait';
        }

        if ($stage === 'compact_repair_dependency_wait') {
            $dependency = $this->child($job->id, 'dependencies');
            if ($dependency === null) {
                $context->checkpoint($this->progress(
                    'compact_repair_dependency_plan',
                    55,
                    'Affected dependency child is missing; replanning it.',
                    ['file_id' => $fileId, 'repaired' => $repaired]
                ));
                $context->defer(1);
            }
            $status = (string)($dependency['status'] ?? 'queued');
            if (in_array($status, ['failed', 'dead_letter', 'cancelled'], true)) {
                $context->defer(30, $this->progress(
                    'compact_repair_dependency_wait',
                    75,
                    'Affected dependency child #' . (int)$dependency['id']
                        . ' requires attention. Restart that child only; repaired metadata is retained.',
                    [
                        'file_id' => $fileId,
                        'repaired' => $repaired,
                        'dependency_job_id' => (int)$dependency['id'],
                        'dependency_status' => $status,
                    ]
                ));
            }
            if ($status !== 'completed') {
                $inner = json_decode((string)($dependency['progress_json'] ?? ''), true);
                $innerPercent = is_array($inner) ? max(0, min(100, (int)($inner['percent'] ?? 0))) : 0;
                $context->defer(2, $this->progress(
                    'compact_repair_dependency_wait',
                    60 + (int)floor(($innerPercent * 39) / 100),
                    'Affected dependency workflow #' . (int)$dependency['id'] . ' is ' . $status . '.',
                    [
                        'file_id' => $fileId,
                        'repaired' => $repaired,
                        'dependency_job_id' => (int)$dependency['id'],
                    ]
                ));
            }
        }

        $message = ($repaired ? 'Repaired' : 'Verified') . ' compact metadata for '
            . (string)$file['original_name'] . ' (#' . $fileId . ') and completed affected dependency refresh.';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'file_id' => $fileId,
            'repaired' => $repaired,
        ]));
        return [
            'operation' => 'repair_compact_metadata_file',
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'file' => (string)$file['original_name'],
            'status' => 'completed',
            'repaired' => $repaired,
            'message' => $message,
        ];
    }

    private function compactMetadataValid(int $fileId): bool
    {
        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata repair.');
        }
        try {
            clearstatcache();
            (new BlockedCompressedMetadataReader($this->db, $storageRoot))->verify($fileId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function file(int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,md5,package_guid FROM ue_files '
            . 'WHERE id=? AND scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function child(int $parentJobId, string $unitKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,status,progress_json,result_json,last_error FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key=? LIMIT 1'
        );
        $statement->execute([$parentJobId, $unitKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(string $stage, int $percent, string $message, array $extra = []): array
    {
        $percent = max(0, min(100, $percent));
        return $extra + [
            'stage' => $stage,
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'message' => $message,
        ];
    }
}
