<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application port used to import or preserve one profile-targeted package.
 * Why: Application upload orchestration must not know about PDO, runtime config arrays, or the legacy scanner implementation.
 * Role: Application-layer boundary implemented by the infrastructure package-import adapter.
 * Audit: Keep persistence, parser, filesystem, and configuration details out of this contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload\Contract;

interface CatalogPackageImporter
{
    /**
     * @return array{0:string,1:int,2:string,3:array<string,mixed>,4?:array<string,mixed>}
     */
    public function import(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array;

    public function preserveFailedUpload(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason
    ): void;
}
