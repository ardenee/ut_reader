<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Composes package hashing, Upload Bucket storage and unverified indexing behind one explicit operations contract.
 * Why: Identity-aware upload and metadata-repair workflows need the same production operations without Reflection or private-method coupling.
 * Role: Infrastructure composition service implementing CatalogBucketPackageOperations.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;

final class CatalogBucketPackageOperationsService implements CatalogBucketPackageOperations
{
    private readonly CatalogPackageIdentityHasher $hasher;
    private readonly CatalogUploadBucketStorage $storage;
    private readonly CatalogUnverifiedPackageIndexer $indexer;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $runtime = new CatalogUnverifiedPackageRuntime($db, $config);
        $this->hasher = new CatalogPackageIdentityHasher();
        $this->storage = new CatalogUploadBucketStorage($runtime);
        $this->indexer = new CatalogUnverifiedPackageIndexer($db, $config, $runtime);
    }

    public function hash(string $path, int $size, ?callable $progress = null): array
    {
        return $this->hasher->hash($path, $size, $progress);
    }

    public function store(string $sourcePath, string $originalName, string $reason): array
    {
        return $this->storage->store($sourcePath, $originalName, $reason);
    }

    public function index(
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        int $size,
        string $md5,
        string $sha1,
        ?callable $progress = null
    ): array {
        return $this->indexer->index(
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
    }
}
