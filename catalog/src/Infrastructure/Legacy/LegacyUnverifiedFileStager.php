<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Legacy;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedFileStager;

final class LegacyUnverifiedFileStager implements UnverifiedFileStager
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/UnverifiedFileManager.php';
        require_once __DIR__ . '/../../../lib/CatalogUnverifiedIndex.php';
    }

    public function stageBucketUpload(
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): array {
        $stored = \uvf_store_bucket_upload($this->config, $temporaryPath, $originalName, $reason);

        return $this->indexStored(
            0,
            (string)$stored['queue_name'],
            (string)$stored['path'],
            (string)$stored['original_name'],
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            (int)$stored['size']
        );
    }

    public function stageFailedUpload(
        int $queueGameId,
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): ?array {
        return $this->stageFailedPath(
            $queueGameId,
            $temporaryPath,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            false
        );
    }

    public function stageFailedCopy(
        int $queueGameId,
        string $sourcePath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): ?array {
        return $this->stageFailedPath(
            $queueGameId,
            $sourcePath,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            true
        );
    }

    /**
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}|null
     */
    private function stageFailedPath(
        int $queueGameId,
        string $sourcePath,
        string $originalName,
        string $reason,
        ?int $uploadedBy,
        string $sourceRelativePath,
        bool $copySource
    ): ?array {
        if (!is_file($sourcePath)) {
            return null;
        }
        if (!\scanner_file_has_unreal_package_magic($sourcePath)) {
            if (!$copySource) {
                @unlink($sourcePath);
            }
            return null;
        }

        $game = \catalog_one(
            $this->db,
            'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?',
            [$queueGameId]
        );
        if (!$game) {
            throw new RuntimeException('Target unverified queue game was not found.');
        }

        $directory = \uvf_unverified_dir($this->config, $game, true);
        $cleanName = \scanner_clean_original_filename($originalName);
        $queueName = \uvf_safe_queue_name($cleanName);
        $destination = \uvf_unique_destination($directory, $queueName);

        if ($copySource) {
            $stored = @copy($sourcePath, $destination);
        } elseif (is_uploaded_file($sourcePath)) {
            $stored = @move_uploaded_file($sourcePath, $destination);
        } else {
            $stored = @rename($sourcePath, $destination);
        }
        if (!$stored) {
            throw new RuntimeException(
                $copySource
                    ? 'Could not copy the failed package into unverified storage.'
                    : 'Could not move the failed package into unverified storage.'
            );
        }

        @file_put_contents($destination . '.txt', $reason);

        return $this->indexStored(
            $queueGameId,
            basename($destination),
            $destination,
            $cleanName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            (int)(filesize($destination) ?: 0)
        );
    }

    /**
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}
     */
    private function indexStored(
        int $queueGameId,
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        ?int $uploadedBy,
        string $sourceRelativePath,
        int $size
    ): array {
        try {
            $indexed = \catalog_unverified_index_path(
                $this->db,
                $this->config,
                $queueGameId,
                $queueName,
                $path,
                $originalName,
                $reason,
                $uploadedBy,
                $sourceRelativePath,
                false
            );
        } catch (Throwable $error) {
            $failure = 'Database staging failed: ' . trim($error->getMessage());
            @file_put_contents($path . '.txt', "\n" . $failure, FILE_APPEND);
            error_log('[UnrealDB unverified staging] ' . $originalName . ': ' . $error->getMessage());
            throw new RuntimeException(
                'The file was retained in the unverified queue, but database staging failed.',
                0,
                $error
            );
        }

        return [
            'status' => (string)$indexed['status'],
            'file_id' => (int)$indexed['file_id'],
            'queue_name' => $queueName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $size,
            'message' => (string)$indexed['message'],
            'parse_error' => isset($indexed['parse_error']) && $indexed['parse_error'] !== null
                ? (string)$indexed['parse_error']
                : null,
        ];
    }
}
