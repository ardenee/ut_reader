<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the public scanner dependency-rebuild function API while delegating persistence to Infrastructure.
 * Role: Thin compatibility bridge for existing jobs/pages during scanner decomposition.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;

/** @param array<string,mixed> $config */
function scanner_dependency_rebuilder(PDO $db, array $config): PdoCatalogDependencyRebuilder
{
    return new PdoCatalogDependencyRebuilder($db, $config);
}

function scanner_rebuild_dependencies(PDO $db, array $config, int $fileId, ?callable $progress = null, int $startPercent = 0, int $endPercent = 100, string $prefix = 'Rebuilding dependencies'): void
{
    scanner_dependency_rebuilder($db, $config)->rebuild($fileId, $progress, $startPercent, $endPercent, $prefix);
}

function scanner_rebuild_game(PDO $db, array $config, int $gameId, ?callable $progress = null, int $startPercent = 56, int $endPercent = 99): void
{
    scanner_dependency_rebuilder($db, $config)->rebuildGame($gameId, $progress, $startPercent, $endPercent);
}

function scanner_rebuild_affected_dependencies(PDO $db, array $config, int $newFileId, ?callable $progress = null, int $startPercent = 56, int $endPercent = 99): void
{
    scanner_dependency_rebuilder($db, $config)->rebuildAffected($newFileId, $progress, $startPercent, $endPercent);
}

function scanner_rebuild_affected_dependencies_for_package(PDO $db, array $config, int $gameId, string $packageName, ?callable $progress = null, int $startPercent = 56, int $endPercent = 99, int $providerFileId = 0): void
{
    scanner_dependency_rebuilder($db, $config)->rebuildAffectedForPackage(
        $gameId,
        $packageName,
        $progress,
        $startPercent,
        $endPercent,
        $providerFileId
    );
}
