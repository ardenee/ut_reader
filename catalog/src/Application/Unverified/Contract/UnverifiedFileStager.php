<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

interface UnverifiedFileStager
{
    /**
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}
     */
    public function stageBucketUpload(
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): array;

    /**
     * Retains only files with the Unreal package magic. Non-package files are deleted.
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
}
