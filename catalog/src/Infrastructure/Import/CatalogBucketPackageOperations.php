<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the explicit internal operations shared by Upload Bucket identity-aware processing and metadata repair.
 * Why: These workflows previously reached private CatalogBucketUploadProcessor methods directly through ReflectionMethod.
 * Role: Infrastructure-only contract that decouples callers from private processor implementation details and makes the shared operations injectable/testable.
 * Audit: Keep this boundary internal to Infrastructure; remove the legacy adapter once the underlying hash/store/index responsibilities are extracted.
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
