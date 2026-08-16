<?php
/**
 * Legacy global facade for catalogue search.
 *
 * The global surface is retained for existing pages. Application search is now
 * persistence-free; this compatibility boundary composes the PDO repository.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Application\Search\CatalogSearchService as ApplicationCatalogSearchService;
use UnrealDb\Catalog\Application\Search\CatalogSearchUnavailableException as ApplicationSearchUnavailableException;
use UnrealDb\Catalog\Infrastructure\Search\PdoCatalogSearchRepository;

class_alias(ApplicationSearchUnavailableException::class, 'CatalogSearchUnavailableException');

final class CatalogSearchService
{
    /** @return list<array<string,mixed>> */
    public static function findFiles(PDO $db, string $query, int $limit = 200, ?int $gameId = null): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, 500));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;

        // The repository itself now keeps every search stage bounded. Do one
        // global search rather than repeating the same search independently for
        // every game; the old fan-out multiplied SQL work by the game count and
        // made administrator searches disproportionately expensive.
        return (new ApplicationCatalogSearchService(new PdoCatalogSearchRepository($db)))
            ->findFiles($query, $limit, $gameId);
    }
}
