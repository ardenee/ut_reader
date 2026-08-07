<?php
/**
 * Compatibility facade for Missing Files drill-down queries while execution lives in Infrastructure.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoMissingDetailListQuery;

final class CatalogMissingDetailListService
{
    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public static function fetchPackageObjects(
        PDO $db,
        string $packageName,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        return (new PdoMissingDetailListQuery($db))->fetchPackageObjects($packageName, $limit, $cursor, $move);
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public static function fetchFileObjects(
        PDO $db,
        int $fileId,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        return (new PdoMissingDetailListQuery($db))->fetchFileObjects($fileId, $limit, $cursor, $move);
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public static function fetchPackageFiles(
        PDO $db,
        bool $summaryAvailable,
        string $packageName,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        return (new PdoMissingDetailListQuery($db))->fetchPackageFiles(
            $summaryAvailable,
            $packageName,
            $limit,
            $cursor,
            $move
        );
    }
}
