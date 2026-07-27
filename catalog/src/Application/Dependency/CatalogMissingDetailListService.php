<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/** Reads bounded object/file drill-down pages from authoritative dependency rows. */
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
        $columns = ['g.name', 'f.package_name', 'f.original_name', 'd.required_object_path', 'd.id'];
        $directions = ['ASC', 'ASC', 'ASC', 'ASC', 'ASC'];
        $select = 'SELECT d.id dependency_id,d.required_object_path,d.required_package,'
            . 'f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,'
            . 'g.id game_id,g.name game_name,i.class_package,i.class_name,i.full_path import_full_path '
            . 'FROM ue_dependencies d JOIN ue_files f ON f.id=d.file_id '
            . 'JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_imports i ON i.id=d.import_id '
            . 'WHERE d.status="missing" AND d.required_package=?';

        return self::fetch(
            $db,
            $select,
            [$packageName],
            $columns,
            $directions,
            $limit,
            $cursor,
            $move,
            static fn(array $row): array => [
                (string)$row['game_name'],
                (string)$row['owner_package_name'],
                (string)$row['owner_original_name'],
                (string)$row['required_object_path'],
                (int)$row['dependency_id'],
            ]
        );
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public static function fetchFileObjects(
        PDO $db,
        int $fileId,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $columns = ['d.required_package', 'd.required_object_path', 'd.id'];
        $directions = ['ASC', 'ASC', 'ASC'];
        $select = 'SELECT d.id dependency_id,d.required_package,d.required_object_path,'
            . 'f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,'
            . 'g.id game_id,g.name game_name,i.class_package,i.class_name,i.full_path import_full_path '
            . 'FROM ue_dependencies d JOIN ue_files f ON f.id=d.file_id '
            . 'JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_imports i ON i.id=d.import_id '
            . 'WHERE d.status="missing" AND d.file_id=?';

        return self::fetch(
            $db,
            $select,
            [$fileId],
            $columns,
            $directions,
            $limit,
            $cursor,
            $move,
            static fn(array $row): array => [
                (string)$row['required_package'],
                (string)$row['required_object_path'],
                (int)$row['dependency_id'],
            ]
        );
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
        $directions = ['DESC', 'ASC', 'ASC', 'ASC', 'ASC'];
        if ($summaryAvailable) {
            $columns = ['s.missing_count', 'g.name', 'f.package_name', 'f.original_name', 'f.id'];
            $select = 'SELECT f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,'
                . 'g.id game_id,g.name game_name,s.missing_count missing_object_rows '
                . 'FROM ue_dependency_package_summaries s JOIN ue_files f ON f.id=s.file_id '
                . 'JOIN ue_games g ON g.id=s.game_id '
                . 'WHERE s.required_package=? AND s.missing_count>0';
        } else {
            $columns = ['x.missing_object_rows', 'x.game_name', 'x.owner_package_name', 'x.owner_original_name', 'x.file_id'];
            $select = 'SELECT x.* FROM ('
                . 'SELECT f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,'
                . 'g.id game_id,g.name game_name,COUNT(d.id) missing_object_rows '
                . 'FROM ue_dependencies d JOIN ue_files f ON f.id=d.file_id '
                . 'JOIN ue_games g ON g.id=f.game_id '
                . 'WHERE d.status="missing" AND d.required_package=? '
                . 'GROUP BY f.id,f.package_name,f.original_name,g.id,g.name'
                . ') x WHERE 1=1';
        }

        return self::fetch(
            $db,
            $select,
            [$packageName],
            $columns,
            $directions,
            $limit,
            $cursor,
            $move,
            static fn(array $row): array => [
                (int)$row['missing_object_rows'],
                (string)$row['game_name'],
                (string)$row['owner_package_name'],
                (string)$row['owner_original_name'],
                (int)$row['file_id'],
            ]
        );
    }

    /**
     * @param list<mixed> $baseArgs
     * @param list<string> $columns
     * @param list<string> $directions
     * @param list<mixed>|null $cursor
     * @param callable(array<string,mixed>):list<mixed> $cursorValues
     * @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array}
     */
    private static function fetch(
        PDO $db,
        string $select,
        array $baseArgs,
        array $columns,
        array $directions,
        int $limit,
        ?array $cursor,
        string $move,
        callable $cursorValues
    ): array {
        $limit = max(1, min(500, $limit));
        $move = in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
        $reverse = $move === 'prev' || $move === 'last';
        $args = $baseArgs;
        if ($cursor !== null && ($move === 'next' || $move === 'prev')) {
            $comparison = CatalogKeysetPaginator::comparison($columns, $directions, $cursor, $move === 'next');
            $select .= ' AND ' . $comparison['sql'];
            array_push($args, ...$comparison['args']);
        }
        $select .= ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
            . ' LIMIT ' . ($limit + 1);

        $statement = $db->prepare($select);
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasExtra = count($rows) > $limit;
        if ($hasExtra) {
            array_pop($rows);
        }
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        $first = $rows !== [] ? $cursorValues($rows[0]) : null;
        $last = $rows !== [] ? $cursorValues($rows[count($rows) - 1]) : null;
        $hasPrevious = match ($move) {
            'next', 'last' => $rows !== [],
            'prev' => $hasExtra,
            default => false,
        };
        $hasNext = match ($move) {
            'first', 'next' => $hasExtra,
            'prev' => $rows !== [],
            default => false,
        };

        return [
            'rows' => $rows,
            'has_previous' => $hasPrevious,
            'has_next' => $hasNext,
            'first_cursor' => $first,
            'last_cursor' => $last,
        ];
    }
}
