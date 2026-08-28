<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `UnverifiedFileStager` for unverified file stager.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

interface UnverifiedFileStager
{
    /**
     * Stores and indexes an Upload Bucket file. A physically confirmed exact
     * size+MD5+SHA-1 match already present in verified/unverified storage returns
     * status=duplicate and the existing file identity; the incoming temporary copy
     * is discarded and no new row is created.
     *
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string,md5?:string,sha1?:string}
     */
    public function stageBucketUpload(
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): array;

    /**
     * Moves a failed temporary upload into unverified storage. Retains only files
     * with the Unreal package magic; non-package temporary files are deleted.
     *
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}|null
     */
    public function stageFailedUpload(
        int $queueGameId,
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): ?array;

    /**
     * Copies a failed package into unverified storage while preserving the source
     * file. Non-package source files are ignored and are never deleted.
     *
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}|null
     */
    public function stageFailedCopy(
        int $queueGameId,
        string $sourcePath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): ?array;
}
