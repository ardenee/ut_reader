<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the public scanner package-import function while delegating the implementation to Infrastructure.
 * Role: Thin compatibility bridge for legacy pages and helpers during scanner retirement.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Composition\CatalogPackageImporterFactory;

function scanner_scan_uploaded_file(PDO $db, array $config, int $gameId, string $tmp, string $originalName, ?int $userId, bool $strictProfile = true, ?callable $progress = null, bool $allowProfileOverride = false, array $scannerOptions = []): array
{
    // Transport-specific state belongs at the caller boundary. Full Sync and
    // maintenance callers explicitly pass defer_dependency_rebuild in
    // $scannerOptions instead of the scanner inspecting $_POST.
    return CatalogPackageImporterFactory::create($db, $config)->importUploadedFile(
        $gameId,
        $tmp,
        $originalName,
        $userId,
        $strictProfile,
        $progress,
        $allowProfileOverride,
        $scannerOptions
    );
}
