<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the explicit internal operations shared by Upload Bucket identity-aware processing and metadata repair.
 * Why: Hashing, physical storage and unverified indexing are separate production collaborators rather than private methods of a monolithic processor.
 * Role: Infrastructure-only contract that keeps import orchestration injectable and testable.
 * Audit: Authoritative Upload Bucket operations boundary; do not reintroduce reflection or parallel implementations.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

interface CatalogBucketPackageOperations
{
    /** @param callable(array<string,mixed>):void|null $progress @return array{md5:string,sha1:string} */
    public function hash(string $path, int $size, ?callable $progress = null): array;

    /** @return array{queue_name:string,original_name:string,size:int,path:string} */
    public function store(string $sourcePath, string $originalName, string $reason): array;

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}
     */
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
    ): array;
}
