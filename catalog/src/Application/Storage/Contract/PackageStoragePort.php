<?php
/**
 * Stable package-storage boundary for workflows that must not know local paths.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Storage\Contract;

interface PackageStoragePort
{
    /**
     * Resolve an existing catalog-relative package path to an implementation-specific readable path.
     */
    public function resolveExisting(string $relativePath): string;

    /**
     * Store a verified package under its canonical game/hash identity.
     *
     * @return array{stored_name:string,destination:string,relative_path:string,created:bool,source_path:string}
     */
    public function storeVerified(
        string $sourcePath,
        string $gameSlug,
        string $md5,
        string $extension,
        bool $discardDuplicateSource = true
    ): array;

    /**
     * Compensate a just-created verified package after downstream persistence fails.
     *
     * @param array{destination?:string,created?:bool,source_path?:string} $stored
     */
    public function rollbackVerified(array $stored): void;

    /** @return array{path:string,available:bool,readable:bool,writable:bool,total_bytes:int|null,free_bytes:int|null} */
    public function health(): array;
}
