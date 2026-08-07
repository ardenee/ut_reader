<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the public scanner package-import function while delegating the implementation to Infrastructure.
 * Role: Thin compatibility bridge for legacy pages and helpers during scanner retirement.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;

function scanner_scan_uploaded_file(PDO $db, array $config, int $gameId, string $tmp, string $originalName, ?int $userId, bool $strictProfile = true, ?callable $progress = null, bool $allowProfileOverride = false, array $scannerOptions = []): array
{
    if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
        $scannerOptions['defer_dependency_rebuild'] = true;
    }

    return (new PdoCatalogPackageImporter($db, $config))->importUploadedFile(
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
