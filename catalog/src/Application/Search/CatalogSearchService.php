<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

use PDO;
use PDOException;

require_once __DIR__ . '/../../../lib/CatalogPackageAliases.php';

final class CatalogSearchUnavailableException extends \RuntimeException
{
}

/**
 * Read-only catalogue identity and filename search.
 *
 * Import/export object matches are added by CatalogCompactSearchService from
 * the compact metadata tables. This core layer deliberately avoids the
 * retired search projection and the legacy parser-detail tables.
 */
final class CatalogSearchService
{
    private const MAX_QUERY_LENGTH = 255;
    private const MIN_BROAD_QUERY_LENGTH = 3;
    private const MAX_MATCHES_PER_FILE = 12;

    /** @return list<array<string,mixed>> */
    public static function findFiles(PDO $db, string $query, int $limit = 200, ?int $gameId = null): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > self::MAX_QUERY_LENGTH) {
            return [];
        }

        $limit = max(1, min($limit, 500));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;
        $candidateMatches = [];

        $fileScopeSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND f.game_id=?');
        $fileScopeArgs = $gameId === null ? [] : [$gameId];

        foreach (self::identityQueries($query) as [$stage, $column, $value, $label]) {
            self::collectStage(
                $db,
                $stage,
                'SELECT f.id,f.' . $column . ' match_value FROM ue_files f '
                    . 'WHERE f.' . $column . '=?' . $fileScopeSql . ' '
                    . 'ORDER BY f.package_name,f.original_name,f.id',
                array_merge([$value], $fileScopeArgs),
                $label,
                $limit,
                $candidateMatches
            );
        }
        if ($candidateMatches !== []) {
            return self::hydrate($db, $candidateMatches, $limit);
        }

        if (mb_strlen($query, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH) {
            return [];
        }

        $prefix = $query . '%';
        $contains = '%' . $query . '%';

        foreach ([
            ['package_name_prefix', 'package_name', $prefix, 'Package'],
            ['file_name_prefix', 'original_name', $prefix, 'File'],
            ['stored_name_prefix', 'stored_name', $prefix, 'Stored file'],
        ] as [$stage, $column, $value, $label]) {
            self::collectStage(
                $db,
                $stage,
                'SELECT f.id,f.' . $column . ' match_value FROM ue_files f '
                    . 'WHERE f.' . $column . ' LIKE ?' . $fileScopeSql . ' '
                    . 'ORDER BY f.' . $column . ',f.id',
                array_merge([$value], $fileScopeArgs),
                $label,
                $limit,
                $candidateMatches
            );
        }

        self::collectAlias($db, $gameId, 'package_name', $prefix, 'Package alias', $limit, $candidateMatches);
        self::collectAlias($db, $gameId, 'original_name', $prefix, 'Alias file', $limit, $candidateMatches);

        if (count($candidateMatches) < $limit) {
            foreach ([
                ['package_name_contains', 'package_name', $contains, 'Package'],
                ['file_name_contains', 'original_name', $contains, 'File'],
                ['stored_name_contains', 'stored_name', $contains, 'Stored file'],
            ] as [$stage, $column, $value, $label]) {
                self::collectStage(
                    $db,
                    $stage,
                    'SELECT f.id,f.' . $column . ' match_value FROM ue_files f '
                        . 'WHERE f.' . $column . ' LIKE ?' . $fileScopeSql . ' '
                        . 'ORDER BY f.' . $column . ',f.id',
                    array_merge([$value], $fileScopeArgs),
                    $label,
                    $limit,
                    $candidateMatches
                );
            }
            self::collectAlias($db, $gameId, 'package_name', $contains, 'Package alias', $limit, $candidateMatches);
            self::collectAlias($db, $gameId, 'original_name', $contains, 'Alias file', $limit, $candidateMatches);
        }

        return self::hydrate($db, $candidateMatches, $limit);
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectAlias(
        PDO $db,
        ?int $gameId,
        string $column,
        string $value,
        string $label,
        int $limit,
        array &$candidateMatches
    ): void {
        if (count($candidateMatches) >= $limit) {
            return;
        }

        $sql = 'SELECT a.file_id id,a.' . $column . ' match_value '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'WHERE f.scan_status="verified" AND a.' . $column . ' LIKE ?';
        $args = [$value];
        if ($gameId !== null) {
            $sql .= ' AND a.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY a.' . $column . ',a.id';

        self::collectStage(
            $db,
            'alias_' . $column,
            $sql,
            $args,
            $label,
            $limit,
            $candidateMatches
        );
    }

    /**
     * @param list<mixed> $args
     * @param array<int,list<array{field:string,value:string}>> $candidateMatches
     */
    private static function collectStage(
        PDO $db,
        string $stage,
        string $sql,
        array $args,
        string $label,
        int $limit,
        array &$candidateMatches
    ): void {
        if (count($candidateMatches) >= $limit) {
            return;
        }

        $rowLimit = max(1, min(5000, ($limit - count($candidateMatches)) * 12));
        try {
            $statement = $db->prepare($sql . ' LIMIT ' . $rowLimit);
            $statement->execute($args);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Catalogue search stage ' . $stage . ' failed: ' . $error->getMessage(),
                0,
                $error
            );
        }

        foreach ($rows as $row) {
            self::addMatch(
                $candidateMatches,
                (int)($row['id'] ?? 0),
                $label,
                (string)($row['match_value'] ?? '')
            );
            if (count($candidateMatches) >= $limit) {
                break;
            }
        }
    }

    /** @return list<array{string,string,string,string}> */
    private static function identityQueries(string $query): array
    {
        if (preg_match('/^[A-Fa-f0-9]{40}$/', $query) === 1) {
            return [['hash_sha1', 'sha1', strtolower($query), 'SHA1']];
        }
        if (preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{8}){3}$/', $query) === 1) {
            return [['guid_exact', 'package_guid', strtoupper($query), 'GUID']];
        }
        if (preg_match('/^[A-Fa-f0-9]{32}$/', $query) === 1) {
            return [
                ['hash_md5', 'md5', strtolower($query), 'MD5'],
                ['guid_compact', 'package_guid', strtoupper(implode('-', str_split($query, 8))), 'GUID'],
            ];
        }
        return [];
    }

    /**
     * @param array<int,list<array{field:string,value:string}>> $candidateMatches
     * @return list<array<string,mixed>>
     */
    private static function hydrate(PDO $db, array $candidateMatches, int $limit): array
    {
        if ($candidateMatches === []) {
            return [];
        }

        $ids = array_slice(array_keys($candidateMatches), 0, $limit);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $statement = $db->prepare(
                'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
                . 'f.file_size,f.package_guid,f.md5,f.sha1,g.name game_name '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'WHERE f.scan_status="verified" AND f.id IN (' . $placeholders . ') '
                . 'ORDER BY g.name,f.package_name,f.original_name,f.id LIMIT ' . $limit
            );
            $statement->execute($ids);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Final catalogue search lookup failed: ' . $error->getMessage(),
                0,
                $error
            );
        }

        foreach ($rows as &$row) {
            $row['matched_fields'] = $candidateMatches[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function addMatch(
        array &$candidateMatches,
        int $fileId,
        string $field,
        string $value
    ): void {
        $value = trim($value);
        if ($fileId < 1 || $value === '') {
            return;
        }

        $current = $candidateMatches[$fileId] ?? [];
        foreach ($current as $match) {
            if ($match['field'] === $field && $match['value'] === $value) {
                return;
            }
        }
        if (count($current) >= self::MAX_MATCHES_PER_FILE) {
            return;
        }
        $current[] = ['field' => $field, 'value' => $value];
        $candidateMatches[$fileId] = $current;
    }
}
