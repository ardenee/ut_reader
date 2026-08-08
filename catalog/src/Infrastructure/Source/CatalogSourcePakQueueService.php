<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Discovers and queues local UE4/UE5 PAK containers before the normal package source scan.
 * Why: Source/profile lookup, PAK traversal, enqueueing and queue-progress accounting are one filesystem/job boundary and do not belong in the job handler.
 * Role: Infrastructure source-scan container pre-queue collaborator; PAK import behavior remains delegated to CatalogProfiledUploadQueue.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;

final class CatalogSourcePakQueueService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{pak_jobs_queued:int,pak_job_ids:list<int>,pak_job_errors:list<string>}
     */
    public function queue(
        int $sourceId,
        bool $strictProfile,
        ?int $userId,
        ?callable $progress = null
    ): array {
        $statement = $this->db->prepare(
            'SELECT s.id,s.game_id,s.source_type,s.base_path,p.engine_key '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?'
        );
        $statement->execute([$sourceId]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source) || (string)$source['source_type'] !== 'local_path') {
            return $this->emptyResult();
        }
        if (preg_match('/^UE[45]/i', trim((string)($source['engine_key'] ?? ''))) !== 1) {
            return $this->emptyResult();
        }

        $basePath = realpath((string)$source['base_path']);
        if ($basePath === false || !is_dir($basePath) || !is_readable($basePath)) {
            throw new \RuntimeException('Source path is not readable: ' . (string)$source['base_path']);
        }

        $paks = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && !$item->isLink()
                && strtolower($item->getExtension()) === 'pak') {
                $paks[] = $item->getPathname();
            }
        }
        if ($paks === []) {
            return $this->emptyResult();
        }

        $queue = new CatalogProfiledUploadQueue($this->db, $this->config);
        $jobIds = [];
        $errors = [];
        $basePrefix = rtrim(str_replace('\\', '/', $basePath), '/') . '/';

        foreach ($paks as $index => $path) {
            try {
                $normalized = str_replace('\\', '/', realpath($path) ?: $path);
                $relative = str_starts_with($normalized, $basePrefix)
                    ? ltrim(substr($normalized, strlen($basePrefix)), '/')
                    : basename($path);
                $queued = $queue->enqueueLocalPak(
                    (int)$source['game_id'],
                    $path,
                    $relative,
                    $strictProfile,
                    $userId,
                    $sourceId
                );
                $jobIds[] = (int)$queued['job_id'];
            } catch (Throwable $error) {
                if (count($errors) < 50) {
                    $errors[] = $path . ' - ' . $error->getMessage();
                }
            }

            if ($progress !== null) {
                $progress([
                    'stage' => 'container_queue',
                    'done' => $index + 1,
                    'total' => count($paks),
                    'percent' => (int)floor((($index + 1) * 100) / max(1, count($paks))),
                    'message' => 'Queued local PAK ' . ($index + 1) . '/' . count($paks) . ': ' . basename($path),
                    'pak_jobs_queued' => count($jobIds),
                    'pak_job_errors' => count($errors),
                ]);
            }
        }

        return [
            'pak_jobs_queued' => count($jobIds),
            'pak_job_ids' => $jobIds,
            'pak_job_errors' => $errors,
        ];
    }

    /** @return array{pak_jobs_queued:int,pak_job_ids:list<int>,pak_job_errors:list<string>} */
    private function emptyResult(): array
    {
        return ['pak_jobs_queued' => 0, 'pak_job_ids' => [], 'pak_job_errors' => []];
    }
}
