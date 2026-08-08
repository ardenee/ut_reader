<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rebuilds metadata for a package already physically stored in the neutral Upload Bucket.
 * Why: Metadata repair reuses the same explicit hash/index collaborators as new Upload Bucket processing without moving or duplicating bytes.
 * Role: Infrastructure import orchestration.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;

final class CatalogUnverifiedMetadataRepairProcessor
{
    private readonly CatalogBucketPackageOperations $operations;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config, ?CatalogBucketPackageOperations $operations = null)
    {
        $this->operations = $operations ?? new CatalogBucketPackageOperationsService($db, $config);
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array<string,mixed>
     */
    public function repair(
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        ?callable $progress = null
    ): array {
        $queueName = basename($queueName);
        if ($queueName === '' || !is_file($path)) {
            throw new \RuntimeException('The Upload Bucket package to repair is unavailable.');
        }
        $size = (int)(filesize($path) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('The Upload Bucket package to repair is empty.');
        }

        $identity = $this->operations->hash($path, $size, $progress);
        $md5 = strtolower(trim((string)($identity['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($identity['sha1'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Metadata repair could not calculate the package MD5 and SHA-1.');
        }

        $result = $this->operations->index(
            $queueName,
            $path,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            $size,
            $md5,
            $sha1,
            $progress
        );
        return $result + ['md5' => $md5, 'sha1' => $sha1];
    }
}
