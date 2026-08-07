<?php
/**
 * Transitional scanner-facing facade for affected dependency refreshes.
 *
 * Legacy scanner code still calls this Application namespace directly. The
 * persistence/queue implementation lives in Infrastructure; remove this facade
 * when CatalogScanner is decomposed and migrated to namespaced collaborators.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogAffectedDependencyRefreshCoordinator;

final class CatalogAffectedDependencyRefreshService
{
    /** @return list<int> */
    public static function findAffectedFileIds(PDO $db, int $gameId, int $newFileId, string $packageName): array
    {
        // Full Sync deliberately defers dependency rebuilding to its final pass.
        if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
            return [];
        }

        return CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $db,
            $gameId,
            $newFileId,
            $packageName
        );
    }

    public static function enqueueIfNeeded(
        PDO $db,
        int $gameId,
        int $newFileId,
        string $packageName,
        bool $sourceSummaryReady = false,
        bool $providerReady = false
    ): int {
        return CatalogAffectedDependencyRefreshCoordinator::enqueueIfNeeded(
            $db,
            $gameId,
            $newFileId,
            $packageName,
            $sourceSummaryReady,
            $providerReady
        );
    }
}
