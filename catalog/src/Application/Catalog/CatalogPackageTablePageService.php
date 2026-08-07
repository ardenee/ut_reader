<?php
/**
 * Stable application-facing package-table paging API.
 *
 * Persistence and compact/legacy metadata reads are implemented by
 * PdoPackageTablePageQuery. Keep this facade only while legacy examine pages
 * still reference the original application class.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Catalog;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;

final class CatalogPackageTablePageService
{
    public const DEFAULT_PAGE_SIZE = PdoPackageTablePageQuery::DEFAULT_PAGE_SIZE;

    /** @return array{table:string,index_column:string,count_column:string,columns:list<string>} */
    public static function definition(string $table): array
    {
        return PdoPackageTablePageQuery::definition($table);
    }

    public static function normalizeTable(string $table): string
    {
        return PdoPackageTablePageQuery::normalizeTable($table);
    }

    public static function normalizePageSize(int $size): int
    {
        return PdoPackageTablePageQuery::normalizePageSize($size);
    }

    public static function targetIndex(string $target, string $table): ?int
    {
        return PdoPackageTablePageQuery::targetIndex($target, $table);
    }

    public static function pageForIndex(int $index, int $pageSize): int
    {
        return PdoPackageTablePageQuery::pageForIndex($index, $pageSize);
    }

    /** @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int,page_size:int,start:int,end:int} */
    public static function fetchPage(PDO $db, array $file, string $table, int $page, int $pageSize): array
    {
        return PdoPackageTablePageQuery::fetchPage($db, $file, $table, $page, $pageSize);
    }

    /** @param list<string> $values @return array<string,int> */
    public static function nameLookup(PDO $db, int $fileId, array $values): array
    {
        return PdoPackageTablePageQuery::nameLookup($db, $fileId, $values);
    }

    /**
     * @param list<string> $names
     * @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}>
     */
    public static function nameUsage(PDO $db, int $fileId, array $names): array
    {
        return PdoPackageTablePageQuery::nameUsage($db, $fileId, $names);
    }

    /** @param list<array<string,mixed>> $imports @return array<int,array<string,mixed>> */
    public static function dependencyMap(PDO $db, int $fileId, array $imports): array
    {
        return PdoPackageTablePageQuery::dependencyMap($db, $fileId, $imports);
    }
}
