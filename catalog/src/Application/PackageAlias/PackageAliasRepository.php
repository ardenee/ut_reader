<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `PackageAliasRepository` for package alias repository.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\PackageAlias;

/** Persistence port for logical package names sharing one physical file. */
interface PackageAliasRepository
{
    public function exists(int $fileId, int $gameId, string $packageName): bool;

    public function add(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        string $packageGuid,
        string $md5,
        int $fileSize
    ): bool;
}
