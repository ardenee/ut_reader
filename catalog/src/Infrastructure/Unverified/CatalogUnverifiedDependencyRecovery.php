<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues post-import dependency work and recovers a partially completed unverified promotion.
 * Why: A file can become verified before compact publication or job queueing completes; compressed staging is the durable retry source.
 * Role: Infrastructure recovery service for unverified promotion dependency handoff.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogPostImportDependencyQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogUnverifiedDependencyRecovery
{
    private readonly CatalogUnverifiedCompactMetadataFinalizer $compactFinalizer;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->compactFinalizer = new CatalogUnverifiedCompactMetadataFinalizer($db, $config);
    }

    /**
     * @return array{search_job_id:int,file_job_id:int,affected_job_id:int,worker_started:bool,worker_error:string}
     */
    public function queueRefresh(int $fileId, int $gameId, string $packageName, ?int $userId): array
    {
        if ($fileId < 1 || $gameId < 1 || trim($packageName) === '') {
            throw new \RuntimeException('The verified file is unavailable for queued dependency processing.');
        }

        return CatalogPostImportDependencyQueue::enqueue(
            $this->db,
            $this->config,
            $fileId,
            $gameId,
            $packageName,
            $userId
        );
    }

    /**
     * @param callable(string,int,string):void|null $emit
     * @return array<string,mixed>
     */
    public function recover(
        int $fileId,
        Throwable $initialError,
        ?int $userId = null,
        ?callable $emit = null
    ): array {
        $file = [];
        try {
            $file = \catalog_one(
                $this->db,
                'SELECT id,game_id,package_name,original_name,scan_status FROM ue_files WHERE id=? LIMIT 1',
                [$fileId]
            ) ?: [];
            if ((string)($file['scan_status'] ?? '') !== 'verified') {
                return [
                    'recovered' => false,
                    'removed' => 0,
                    'message' => $this->errorText($initialError),
                ];
            }

            if ($emit !== null) {
                $emit('compact_recovery', 60, 'Verifying compact metadata and repairing from compressed staging if required');
            }
            $this->compactFinalizer->finalize($fileId);

            if ($emit !== null) {
                $emit('dependency_recovery', 72, 'Retrying post-import dependency queueing');
            }
            $jobs = $this->queueRefresh(
                $fileId,
                (int)($file['game_id'] ?? 0),
                (string)($file['package_name'] ?? ''),
                $userId
            );
            return [
                'recovered' => true,
                'removed' => 0,
                'jobs' => $jobs,
                'message' => 'Verified compact metadata and queued a fresh dependency scan.',
            ];
        } catch (Throwable $recoveryError) {
            if ((string)($file['scan_status'] ?? '') === 'verified' && $fileId > 0) {
                try {
                    $repairJobId = $this->queueCompactRepair(
                        $fileId,
                        (int)($file['game_id'] ?? 0),
                        (string)($file['original_name'] ?? ''),
                        $userId
                    );
                    if ($emit !== null) {
                        $emit(
                            'compact_repair_queued',
                            76,
                            'Queued durable compact metadata repair job #' . $repairJobId
                        );
                    }
                    return [
                        'recovered' => true,
                        'recovery_queued' => true,
                        'removed' => 0,
                        'jobs' => [
                            'search_job_id' => 0,
                            'file_job_id' => 0,
                            'affected_job_id' => 0,
                            'compact_repair_job_id' => $repairJobId,
                            'worker_started' => false,
                            'worker_error' => '',
                        ],
                        'message' => 'Synchronous compact metadata recovery failed; durable repair job #'
                            . $repairJobId . ' was queued from authoritative package storage. Cause: '
                            . $this->errorText($recoveryError),
                    ];
                } catch (Throwable $queueError) {
                    return [
                        'recovered' => false,
                        'removed' => 0,
                        'message' => $this->errorText($recoveryError)
                            . ' Durable compact repair could not be queued: '
                            . $this->errorText($queueError),
                    ];
                }
            }

            return [
                'recovered' => false,
                'removed' => 0,
                'message' => $this->errorText($recoveryError),
            ];
        }
    }

    private function queueCompactRepair(
        int $fileId,
        int $gameId,
        string $originalName,
        ?int $userId
    ): int {
        if ($fileId < 1 || $gameId < 1) {
            throw new \RuntimeException('Compact metadata recovery job requires valid file and game IDs.');
        }
        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        return (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::REPAIR_COMPACT_METADATA_FILE,
            [
                'file_id' => $fileId,
                'game_id' => $gameId,
                'requested_by' => $userId,
                'source_relative_path' => 'Compact metadata recovery · '
                    . (trim($originalName) !== '' ? $originalName : ('file #' . $fileId)),
            ],
            15,
            null,
            'compact-metadata-repair:' . $fileId,
            $userId,
            5
        );
    }

    private function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        return $message !== '' ? $message : 'Unknown server error';
    }
}
