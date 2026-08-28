<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

interface CatalogSearchRepository
{
    /** @return list<array<string,mixed>> */
    public function findFiles(string $query, int $limit = 200, ?int $gameId = null, array $filters = []): array;
}
