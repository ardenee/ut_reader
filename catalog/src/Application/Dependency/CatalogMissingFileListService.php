<?php
/**
 * Compatibility facade for the Missing Files page while query execution lives in Infrastructure.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoMissingFileListQuery;

final class CatalogMissingFileListService
{
    /**
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public static function fetchCursorPage(
        PDO $db,
        int $limit,
        ?array $cursor,
        string $move = 'first'
    ): array {
        return (new PdoMissingFileListQuery($db))->fetchCursorPage(
            $limit,
            $cursor,
            $move
        );
    }
}
