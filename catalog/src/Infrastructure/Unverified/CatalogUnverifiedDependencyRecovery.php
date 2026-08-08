<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues post-import dependency work and repairs the established legacy staging collision case.
 * Why: Dependency persistence/recovery must not live inside the unverified HTTP action endpoint.
 * Role: Infrastructure compatibility service for unverified promotion dependency handoff.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Dependency\CatalogPostImportDependencyQueue;

final class CatalogUnverifiedDependencyRecovery
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
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
        $error = $initialError;
        $removed = 0;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $importId = $this->collisionImportId($error);
            if ($importId < 1) {
                break;
            }

            if ($emit !== null) {
                $emit('dependency_recovery', 58, 'Repairing a stale dependency collision');
            }

            // Unverified staging still uses the compatibility Names/Imports/
            // Exports rows until promotion finalizes compact metadata. Keep this
            // narrowly scoped cleanup here rather than leaking those tables into
            // Presentation code.
            $statement = $this->db->prepare('DELETE FROM ue_dependencies WHERE import_id=?');
            $statement->execute([$importId]);
            $removed += $statement->rowCount();
            $removed += $this->clearFileDependencies($fileId);

            try {
                $file = \catalog_one(
                    $this->db,
                    'SELECT game_id,package_name FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
                    [$fileId]
                ) ?: [];
                $jobs = $this->queueRefresh(
                    $fileId,
                    (int)($file['game_id'] ?? 0),
                    (string)($file['package_name'] ?? ''),
                    $userId
                );
                return [
                    'recovered' => true,
                    'removed' => $removed,
                    'jobs' => $jobs,
                    'message' => 'Removed a stale duplicate dependency link and queued a fresh dependency scan.',
                ];
            } catch (Throwable $retryError) {
                $error = $retryError;
            }
        }

        return [
            'recovered' => false,
            'removed' => $removed,
            'message' => $this->errorText($error),
        ];
    }

    private function collisionImportId(Throwable $error): int
    {
        $message = $error->getMessage();
        if (!str_contains($message, 'uq_ue_deps_import')) {
            return 0;
        }
        if (preg_match("/Duplicate entry '([0-9]+)'/i", $message, $match) !== 1) {
            return 0;
        }
        return max(0, (int)$match[1]);
    }

    private function clearFileDependencies(int $fileId): int
    {
        if ($fileId < 1) {
            return 0;
        }

        $removed = 0;
        $statement = $this->db->prepare(
            'DELETE d FROM ue_dependencies d INNER JOIN ue_imports i ON i.id=d.import_id WHERE i.file_id=?'
        );
        $statement->execute([$fileId]);
        $removed += $statement->rowCount();

        $statement = $this->db->prepare('DELETE FROM ue_dependencies WHERE file_id=?');
        $statement->execute([$fileId]);
        $removed += $statement->rowCount();
        return $removed;
    }

    private function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        return $message !== '' ? $message : 'Unknown server error';
    }
}
