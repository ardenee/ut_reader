<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues a verified package from one game for import into another compatible game when it can satisfy exact missing dependencies.
 * Why: Cross-game dependency repair must create a real game-scoped verified row rather than an alias or presentation-only relationship.
 * Role: Mutation service behind the dependency cross-examine administration page.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;

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
                'This source file is no longer an exact compatible provider for a missing dependency in the target game.'
            );
        }

        $sourcePath = $this->physicalPath((string)($candidate['relative_path'] ?? ''));
        $originalName = trim((string)($candidate['original_name'] ?? ''));
        if ($originalName === '') {
            throw new \RuntimeException('The source package filename is unavailable.');
        }

        $sourceRow = \catalog_one(
            $this->db,
            'SELECT source_relative_path FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
            [$sourceFileId]
        ) ?: [];
        $sourceRelativePath = trim((string)($sourceRow['source_relative_path'] ?? ''));
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $originalName;
        }

        $store = new CatalogIncomingFileStore($this->config);
        $staged = $store->stageLocalFile($sourcePath, $originalName);
        try {
            $queued = (new CatalogProfiledUploadQueue($this->db, $this->config))->enqueueStaged(
                $targetGameId,
                $staged,
                $originalName,
                $sourceRelativePath,
                true,
                $userId,
                false
            );
        } catch (\Throwable $error) {
            $store->delete((string)$staged['relative_path']);
            throw $error;
        }

        return [
            'source_file_id' => $sourceFileId,
            'source_game' => (string)$candidate['source_game_name'],
            'target_game_id' => $targetGameId,
            'target_game' => (string)$candidate['target_game_name'],
            'package_name' => (string)$candidate['package_name'],
            'original_name' => $originalName,
            'exact_object_matches' => (int)$candidate['exact_object_matches'],
            'target_missing_count' => (int)$candidate['target_missing_count'],
            'job_id' => (int)$queued['job_id'],
            'deduplicated' => !empty($queued['deduplicated']),
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
}
