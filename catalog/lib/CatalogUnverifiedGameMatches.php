<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for unverified game-match ranking.
 * Why: Existing callers retain their procedural contract while the query/scoring implementation lives under src/.
 * Role: Transitional legacy facade; new code should use PdoUnverifiedGameMatchQuery directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchQuery;

/** @return list<array<string,mixed>> */
function catalog_unverified_game_matches_v2(PDO $db, int $fileId): array
{
    return (new PdoUnverifiedGameMatchQuery($db))->one($fileId);
}

/**
 * @param list<int> $fileIds
 * @return array<int,list<array<string,mixed>>>
 */
function catalog_unverified_game_matches_bulk(PDO $db, array $fileIds): array
{
    return (new PdoUnverifiedGameMatchQuery($db))->bulk($fileIds);
}
