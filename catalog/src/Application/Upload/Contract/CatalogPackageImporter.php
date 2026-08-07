<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `CatalogPackageImporter` for catalog package importer.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload\Contract;

use PDO;

/**
 * Boundary between upload orchestration and the package reader/scanner stack.
 *
 * The current implementation delegates to the established procedural scanner.
 * A future worker or reader implementation can satisfy this contract without
 * changing controller or upload-batch behaviour.
 */
interface CatalogPackageImporter
{
    /**
     * @param array<string, mixed> $config
     * @return array{0:string,1:int,2:string,3:array<string, mixed>,4?:array<string, mixed>}
     */
    public function import(
        PDO $db,
        array $config,
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array;

    /**
     * @param array<string, mixed> $config
     */
    public function preserveFailedUpload(
        array $config,
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason
    ): void;
}
