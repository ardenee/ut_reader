<?php
/**
 * Application catalogue search use case.
 *
 * Search sequencing is exposed through an Application port; SQL, PDO and compact
 * projection availability live in the Infrastructure repository implementation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

final class CatalogSearchService
{
    public function __construct(private readonly CatalogSearchRepository $repository)
    {
    }

    /** @return list<array<string,mixed>> */
    public function findFiles(string $query, int $limit = 200, ?int $gameId = null, array $filters = []): array
    {
        return $this->repository->findFiles($query, $limit, $gameId, $filters);
    }
}
