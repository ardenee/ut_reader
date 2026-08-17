<?php
/**
 * Assigns a neutral Upload Bucket PAK to one UE4/UE5 game by handing a durable
 * copy to the established selected-game PAK workflow, then removing the bucket
 * parent and only the extracted child rows/files owned by that parent.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;

final class CatalogUnverifiedPakAssignmentService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function assign(
        array $source,
        int $targetGameId,
        ?int $userId,
        ?callable $emit = null
    ): array {
        if ($targetGameId < 1) {
            throw new \RuntimeException('Choose one UE4/UE5 target game for the PAK container.');
        }
        if ((int)($source['game']['id'] ?? -1) !== 0) {
            throw new \RuntimeException('PAK container assignment is only supported from the neutral Upload Bucket.');
        }
        if (strtolower((string)($source['extension'] ?? '')) !== 'pak') {
            throw new \InvalidArgumentException('The selected unverified file is not a PAK container.');
        }
        $parentFileId = (int)($source['file_id'] ?? 0);
        $path = (string)($source['path'] ?? '');
        if ($parentFileId < 1 || !is_file($path) || !is_readable($path) || is_link($path)) {
            throw new \RuntimeException('The retained Upload Bucket PAK is unavailable.');
        }

        if ($emit !== null) {
            $emit('pak_assignment', 20, 'Preparing retained PAK for the selected game');
        }

        $incoming = new CatalogIncomingFileStore($this->config);
        $staged = $incoming->stageLocalFile($path, (string)$source['original_name']);
        try {
            $queued = (new CatalogProfiledUploadQueue($this->db, $this->config))->enqueueStaged(
                $targetGameId,
                $staged,
                (string)$source['original_name'],
                (string)($source['source_relative_path'] ?? $source['original_name']),
                true,
                $userId,
                false
            );
        } catch (Throwable $error) {
            $incoming->delete((string)$staged['relative_path']);
            throw $error;
        }

        $target = $this->db->prepare('SELECT name FROM ue_games WHERE id=? LIMIT 1');
        $target->execute([$targetGameId]);
        $targetName = (string)($target->fetchColumn() ?: ('game #' . $targetGameId));

        $cleanupWarning = '';
        try {
            (new CatalogUnverifiedPakCleanupService($this->db, $this->config))->remove($parentFileId);
        } catch (Throwable $error) {
            $cleanupWarning = 'PAK import was queued, but the old Upload Bucket parent could not be removed: '
                . trim($error->getMessage());
        }

        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $workerState = (new CatalogQueueWorkerStarter($this->db, $this->config))->start($queueName, true, $userId);
        $workerWarning = trim((string)($workerState['worker_error'] ?? ''));
        $warning = trim(implode(' ', array_filter([$cleanupWarning, $workerWarning])));

        if ($emit !== null) {
            $emit('pak_assignment', 95, 'PAK and its contained packages are queued for ' . $targetName);
        }

        return [
            'result' => [
                'status' => 'queued',
                'pak_container' => true,
                'original_name' => (string)$source['original_name'],
                'file_id' => $parentFileId,
                'target_game' => $targetName,
                'target_game_id' => $targetGameId,
                'pak_job_id' => (int)$queued['job_id'],
                'dependency_jobs' => [],
            ],
            'details' => [
                'pak_container' => true,
                'name_count' => 0,
                'import_count' => 0,
                'export_count' => 0,
                'package_guid' => '',
            ],
            'warning' => $warning,
            'recovery' => is_array($workerState['recovery'] ?? null) ? $workerState['recovery'] : null,
        ];
    }
}
