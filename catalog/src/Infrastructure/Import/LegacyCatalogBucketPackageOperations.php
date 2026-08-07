<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Adapts the existing monolithic CatalogBucketUploadProcessor private operations to the explicit package-operations contract.
 * Why: It isolates the remaining reflection compatibility shim to one Infrastructure class while callers migrate to an explicit contract.
 * Role: Temporary Infrastructure compatibility adapter; hashing, storage and indexing will be extracted from CatalogBucketUploadProcessor behind the same contract.
 * Audit: This is the only permitted ReflectionMethod bridge for Upload Bucket package operations. Delete it when those responsibilities are extracted.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use ReflectionMethod;

final class LegacyCatalogBucketPackageOperations implements CatalogBucketPackageOperations
{
    private readonly CatalogBucketUploadProcessor $processor;
    private readonly ReflectionMethod $hashMethod;
    private readonly ReflectionMethod $storeMethod;
    private readonly ReflectionMethod $indexMethod;

    /** @param array<string,mixed> $config */
    public function __construct(\PDO $db, array $config)
    {
        $this->processor = new CatalogBucketUploadProcessor($db, $config);
        $this->hashMethod = $this->method('hashIdentity');
        $this->storeMethod = $this->method('storeBucketUpload');
        $this->indexMethod = $this->method('indexStored');
    }

    public function hash(string $path, int $size, ?callable $progress = null): array
    {
        /** @var array{md5:string,sha1:string} $identity */
        $identity = $this->hashMethod->invoke($this->processor, $path, $size, $progress);
        return $identity;
    }

    public function store(string $sourcePath, string $originalName, string $reason): array
    {
        /** @var array{queue_name:string,original_name:string,size:int,path:string} $stored */
        $stored = $this->storeMethod->invoke($this->processor, $sourcePath, $originalName, $reason);
        return $stored;
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
        /** @var array<string,mixed> $indexed */
        $indexed = $this->indexMethod->invoke(
            $this->processor,
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
        return $indexed;
    }

    private function method(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(CatalogBucketUploadProcessor::class, $name);
        $method->setAccessible(true);
        return $method;
    }
}
