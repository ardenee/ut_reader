<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation dependency-download helper API for existing callers.
 * Why: Missing-dependency checks, approved-parent file caching and download queue orchestration now live under Infrastructure.
 * Role: Thin compatibility facade; do not add federation dependency-download persistence, policy or network implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/FederationPeerSecret.php';
require_once __DIR__ . '/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApprovedParentFileCache;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationDependencyDownloadQueueService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationDependencyNeedQuery;

function federation_dependency_request_still_needed(PDO $db, string $requiredPackage, string $requiredObjectPath = ''): bool
{
    return (new CatalogFederationDependencyNeedQuery($db))->requestStillNeeded(
        $requiredPackage,
        $requiredObjectPath
    );
}

/** @param array<string,mixed> $item */
function federation_dependency_item_already_local(PDO $db, array $item): bool
{
    return (new CatalogFederationDependencyNeedQuery($db))->itemAlreadyLocal($item);
}

/** @param array<string,mixed> $item */
function federation_cache_approved_parent_file(PDO $db, int $peerId, array $item): void
{
    (new CatalogFederationApprovedParentFileCache($db))->cache($peerId, $item);
}

/** @return array<string,mixed> */
function federation_queue_approved_dependency_downloads(PDO $db): array
{
    return (new CatalogFederationDependencyDownloadQueueService($db))->queueApproved();
}
