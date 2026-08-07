<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the existing game-file list application API while query execution lives in Infrastructure.
 * Why: The active game-files page still calls this class directly; keeping a thin facade avoids changing page behavior during the persistence extraction.
 * Role: Transitional compatibility facade only. New query logic belongs in PdoGameFileListQuery.
 * Audit: Remove this facade once the game-files page composes PdoGameFileListQuery directly.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Catalog;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameFileListQuery;

final class CatalogGameFileListService
{
    /**
     * @param list<mixed> $whereArgs
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public static function fetchCursorPage(
        PDO $db,
        string $whereSql,
        array $whereArgs,
        string $sort,
        string $direction,
        int $limit,
        ?array $cursor,
        string $move = 'first'
    ): array {
        return (new PdoGameFileListQuery($db))->fetchCursorPage(
            $whereSql,
            $whereArgs,
            $sort,
            $direction,
            $limit,
            $cursor,
            $move
        );
    }
}
