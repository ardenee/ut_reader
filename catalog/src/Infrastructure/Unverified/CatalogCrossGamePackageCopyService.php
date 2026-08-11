<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues a verified package from one sibling game into another when it exactly satisfies missing dependency objects.
 * Why: Cross-game dependency repair must create a real game-scoped verified row without duplicating trusted source bytes before queueing.
 * Role: Mutation service behind the dependency cross-examine administration workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogCrossGamePackageCopyService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    /** @return array<string,mixed> */
    public function queue(int $sourceFileId, int $targetGameId, ?int $userId): array
    {
        $candidate = (new PdoGameDependencyCrossExamineQuery($this->db, $this->config))
            ->one($sourceFileId, $targetGameId);
        if ($candidate === null) {
            throw new \RuntimeException(
                'This source file no longer exports an object required by a missing dependency in the target game.'
            );
        }
        if (!empty($candidate['already_in_target'])) {
            throw new \RuntimeException(
                'The same package bytes are already verified in the target game as file #'
                . (int)($candidate['target_existing_file_id'] ?? 0)
                . '. Rebuild the target dependencies instead of copying the file again.'
            );
        }

        $sourcePath = $this->physicalPath((string)($candidate['relative_path'] ?? ''));
        $originalName = trim((string)($candidate['original_name'] ?? ''));
        if ($originalName === '') {
            throw new \RuntimeException('The source package filename is unavailable.');
        }

        $sourceRow = \catalog_one(
            $this->db,
            'SELECT source_relative_path,file_size,md5 FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
            [$sourceFileId]
        ) ?: [];
        $sourceRelativePath = trim((string)($sourceRow['source_relative_path'] ?? ''));
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $originalName;
        }
        $fileSize = (int)($sourceRow['file_size'] ?? 0);
        if ($fileSize < 1) {
            $physicalSize = filesize($sourcePath);
            $fileSize = $physicalSize === false ? 0 : (int)$physicalSize;
        }
        if ($fileSize < 1) {
            throw new \RuntimeException('Verified source package size is unavailable.');
        }

        // Do not copy/hash the already-verified package just to create a queue
        // record. The import worker resolves this read-only catalog-local source
        // and creates its normal parser working hardlink/copy there. The original
        // verified source path is never moved or deleted.
        $payload = [
            'game_id' => $targetGameId,
            'staged_path' => 'local-catalog:' . $this->encodeLocalPath($sourcePath),
            'original_name' => $originalName,
            'source_relative_path' => $sourceRelativePath,
            'strict_profile' => false,
            'user_id' => $userId,
            'size' => $fileSize,
            'cross_game_source_file_id' => $sourceFileId,
        ];
        $md5 = strtolower(trim((string)($sourceRow['md5'] ?? $candidate['md5'] ?? '')));
        $identity = $md5 !== '' ? $md5 : (string)$sourceFileId;
        $dedupeKey = 'cross-game-copy:' . hash(
            'sha256',
            $targetGameId . "\0" . $sourceFileId . "\0" . $identity
        );
        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queue = new PdoJobQueue($this->db);

        $existing = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        $existingJobId = (int)($existing->fetchColumn() ?: 0);

        $jobId = $queue->enqueue(
            $queueName,
            JobType::IMPORT_STAGED_PACKAGE,
            $payload,
            5,
            null,
            $dedupeKey,
            $userId,
            3
        );

        return [
            'source_file_id' => $sourceFileId,
            'source_game' => (string)$candidate['source_game_name'],
            'target_game_id' => $targetGameId,
            'target_game' => (string)$candidate['target_game_name'],
            'package_name' => (string)$candidate['package_name'],
            'original_name' => $originalName,
            'exact_object_matches' => (int)$candidate['exact_object_matches'],
            'target_missing_count' => (int)$candidate['target_missing_count'],
            'job_id' => $jobId,
            'deduplicated' => $existingJobId > 0 && $existingJobId === $jobId,
        ];
    }

    private function physicalPath(string $rawPath): string
    {
        $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            throw new \RuntimeException('Catalog storage root is unavailable.');
        }
        $rawPath = trim($rawPath);
        if ($rawPath === '' || str_contains($rawPath, "\0")) {
            throw new \RuntimeException('Verified source package path is unavailable.');
        }

        $normalized = str_replace('\\', '/', $rawPath);
        $candidates = [];
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        }
        $relative = ltrim($normalized, '/');
        if (str_starts_with(strtolower($relative), 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        if ($relative !== '' && preg_match('#(^|/)\.\.(/|$)#', $relative) !== 1) {
            $candidates[] = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate) || is_link($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }
            $realNormalized = str_replace('\\', '/', $real);
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($realNormalized), strtolower($rootPrefix))
                : str_starts_with($realNormalized, $rootPrefix);
            if ($inside) {
                return $real;
            }
        }
        throw new \RuntimeException('Verified source package is missing from controlled storage.');
    }

    private function encodeLocalPath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }
}
