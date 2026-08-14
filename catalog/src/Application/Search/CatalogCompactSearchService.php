<?php
/**
 * Compatibility Application wrapper for callers that conceptually request the
 * compact-aware catalogue search. The repository decides which compact
 * projections are available; this layer contains no persistence logic.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

final class CatalogCompactSearchService
{
    private readonly CatalogSearchService $search;

    public function __construct(CatalogSearchRepository $repository)
    {
        $this->search = new CatalogSearchService($repository);
    }

    /** @return list<array<string,mixed>> */
    public function findFiles(string $query, int $limit = 200, ?int $gameId = null): array
    {
        return $this->search->findFiles($query, $limit, $gameId);
    }
}
