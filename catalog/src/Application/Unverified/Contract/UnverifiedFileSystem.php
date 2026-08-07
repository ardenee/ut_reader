<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `UnverifiedFileSystem` for unverified file system.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

/** Filesystem operations required by exact duplicate cleanup. */
interface UnverifiedFileSystem
{
    public function exists(string $path): bool;

    public function size(string $path): int;

    /** @param null|callable(int,int):void $progress Receives bytes read and total size. */
    public function md5(string $path, ?callable $progress = null): ?string;

    public function delete(string $path): bool;
}
