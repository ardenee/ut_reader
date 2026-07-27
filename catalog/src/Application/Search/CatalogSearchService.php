<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

use PDO;
use PDOException;

require_once __DIR__ . '/../../../lib/CatalogPackageAliases.php';

final class CatalogSearchUnavailableException extends \RuntimeException
{
}

/** Read-only catalogue search. Unverified staging rows are never candidates. */
final class CatalogSearchService
{
    private const MAX_MATCHES_PER_FILE = 12;
    private const MAX_QUERY_LENGTH = 255;
    private const MIN_BROAD_QUERY_LENGTH = 3;

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

        $fileGameSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND f.game_id=?');
        $fileGameArgs = $gameId === null ? [] : [$gameId];

        $identityQueries = self::identityQueries($query);
        if ($identityQueries !== []) {
            foreach ($identityQueries as [$stage, $column, $value, $label]) {
                self::collectStage(
                    $db,
                    $stage,
                    'SELECT f.id,f.' . $column . ' match_value FROM ue_files f WHERE f.' . $column . '=?'
                        . $fileGameSql . ' ORDER BY f.package_name,f.original_name',
                    array_merge([$value], $fileGameArgs),
                    $label,
                    $limit,
                    $candidateMatches
                );
            }
            return self::hydrate($db, $candidateMatches, $limit);
        }

        if (mb_strlen($query, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH) {
            return [];
        }

        $like = '%' . $query . '%';
        $prefix = $query . '%';

        self::collectStage(
            $db,
            'guid_prefix',
            'SELECT f.id,f.package_guid match_value FROM ue_files f WHERE f.package_guid LIKE ?'
                . $fileGameSql . ' ORDER BY f.package_guid',
            array_merge([$prefix], $fileGameArgs),
            'GUID',
            $limit,
            $candidateMatches
        );
        self::collectStage(
            $db,
            'package_name_prefix',
            'SELECT f.id,f.package_name match_value FROM ue_files f WHERE f.package_name LIKE ?'
                . $fileGameSql . ' ORDER BY f.package_name,f.original_name',
            array_merge([$prefix], $fileGameArgs),
            'Package',
            $limit,
            $candidateMatches
        );

        if ($gameId !== null) {
            self::collectStage(
                $db,
                'package_alias_prefix',
                'SELECT a.file_id id,a.package_name match_value FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                    . 'WHERE a.game_id=? AND f.scan_status="verified" AND a.package_name LIKE ? '
                    . 'ORDER BY a.package_name,a.original_name',
                [$gameId, $prefix],
                'Package alias',
                $limit,
                $candidateMatches
            );
        } else {
            self::collectStage(
                $db,
                'package_alias_prefix',
                'SELECT a.file_id id,a.package_name match_value FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id WHERE f.scan_status="verified" AND a.package_name LIKE ? '
                    . 'ORDER BY a.package_name,a.original_name',
                [$prefix],
                'Package alias',
                $limit,
                $candidateMatches
            );
        }

        self::collectStage(
            $db,
            'file_name_prefix',
            'SELECT f.id,f.original_name match_value FROM ue_files f WHERE f.original_name LIKE ?'
                . $fileGameSql . ' ORDER BY f.original_name',
            array_merge([$prefix], $fileGameArgs),
            'File',
            $limit,
            $candidateMatches
        );

        if ($gameId !== null) {
            self::collectStage(
                $db,
                'alias_file_name_prefix',
                'SELECT a.file_id id,a.original_name match_value FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                    . 'WHERE a.game_id=? AND f.scan_status="verified" AND a.original_name LIKE ? '
                    . 'ORDER BY a.original_name',
                [$gameId, $prefix],
                'Alias file',
                $limit,
                $candidateMatches
            );
        } else {
            self::collectStage(
                $db,
                'alias_file_name_prefix',
                'SELECT a.file_id id,a.original_name match_value FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id WHERE f.scan_status="verified" AND a.original_name LIKE ? '
                    . 'ORDER BY a.original_name',
                [$prefix],
                'Alias file',
                $limit,
                $candidateMatches
            );
        }

        self::collectStage(
            $db,
            'package_name_contains',
            'SELECT f.id,f.package_name match_value FROM ue_files f WHERE f.package_name LIKE ?'
                . $fileGameSql . ' ORDER BY f.package_name,f.original_name',
            array_merge([$like], $fileGameArgs),
            'Package',
            $limit,
            $candidateMatches
        );
        self::collectStage(
            $db,
            'file_name_contains',
            'SELECT f.id,f.original_name match_value FROM ue_files f WHERE f.original_name LIKE ?'
                . $fileGameSql . ' ORDER BY f.original_name',
            array_merge([$like], $fileGameArgs),
            'File',
            $limit,
            $candidateMatches
        );

        if ($gameId !== null) {
            self::collectStage(
                $db,
                'import_object',
                'SELECT STRAIGHT_JOIN i.file_id id,i.object_name match_value FROM ue_files f '
                    . 'JOIN ue_imports i ON i.file_id=f.id '
                    . 'WHERE f.game_id=? AND f.scan_status="verified" AND i.object_name LIKE ? '
                    . 'ORDER BY i.file_id,i.import_index',
                [$gameId, $like],
                'Import object',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'export_object',
                'SELECT STRAIGHT_JOIN e.file_id id,e.object_name match_value FROM ue_files f '
                    . 'JOIN ue_exports e ON e.file_id=f.id '
                    . 'WHERE f.game_id=? AND f.scan_status="verified" AND e.object_name LIKE ? '
                    . 'ORDER BY e.file_id,e.export_index',
                [$gameId, $like],
                'Export object',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'import_path',
                'SELECT STRAIGHT_JOIN i.file_id id,i.full_path match_value FROM ue_files f '
                    . 'JOIN ue_imports i ON i.file_id=f.id '
                    . 'WHERE f.game_id=? AND f.scan_status="verified" AND i.full_path LIKE ? '
                    . 'ORDER BY i.file_id,i.import_index',
                [$gameId, $like],
                'Import path',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'export_path',
                'SELECT STRAIGHT_JOIN e.file_id id,e.full_path match_value FROM ue_files f '
                    . 'JOIN ue_exports e ON e.file_id=f.id '
                    . 'WHERE f.game_id=? AND f.scan_status="verified" AND e.full_path LIKE ? '
                    . 'ORDER BY e.file_id,e.export_index',
                [$gameId, $like],
                'Export path',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'alias_export_path',
                'SELECT f.id,CONCAT(a.package_name,".",e.local_path) match_value '
                    . 'FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                    . 'JOIN ue_exports e ON e.file_id=f.id '
                    . 'WHERE a.game_id=? AND f.scan_status="verified" '
                    . 'AND CONCAT(a.package_name,".",e.local_path) LIKE ? '
                    . 'ORDER BY a.package_name,e.export_index',
                [$gameId, $like],
                'Alias export path',
                $limit,
                $candidateMatches
            );
        } else {
            self::collectStage(
                $db,
                'import_object',
                'SELECT i.file_id id,i.object_name match_value FROM ue_imports i '
                    . 'JOIN ue_files f ON f.id=i.file_id '
                    . 'WHERE f.scan_status="verified" AND i.object_name LIKE ? '
                    . 'ORDER BY i.file_id,i.import_index',
                [$like],
                'Import object',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'export_object',
                'SELECT e.file_id id,e.object_name match_value FROM ue_exports e '
                    . 'JOIN ue_files f ON f.id=e.file_id '
                    . 'WHERE f.scan_status="verified" AND e.object_name LIKE ? '
                    . 'ORDER BY e.file_id,e.export_index',
                [$like],
                'Export object',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'import_path',
                'SELECT i.file_id id,i.full_path match_value FROM ue_imports i '
                    . 'JOIN ue_files f ON f.id=i.file_id '
                    . 'WHERE f.scan_status="verified" AND i.full_path LIKE ? '
                    . 'ORDER BY i.file_id,i.import_index',
                [$like],
                'Import path',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'export_path',
                'SELECT e.file_id id,e.full_path match_value FROM ue_exports e '
                    . 'JOIN ue_files f ON f.id=e.file_id '
                    . 'WHERE f.scan_status="verified" AND e.full_path LIKE ? '
                    . 'ORDER BY e.file_id,e.export_index',
                [$like],
                'Export path',
                $limit,
                $candidateMatches
            );
            self::collectStage(
                $db,
                'alias_export_path',
                'SELECT f.id,CONCAT(a.package_name,".",e.local_path) match_value '
                    . 'FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id '
                    . 'JOIN ue_exports e ON e.file_id=f.id '
                    . 'WHERE f.scan_status="verified" AND CONCAT(a.package_name,".",e.local_path) LIKE ? '
                    . 'ORDER BY a.package_name,e.export_index',
                [$like],
                'Alias export path',
                $limit,
                $candidateMatches
            );
        }

        return self::hydrate($db, $candidateMatches, $limit);
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

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches
     *  @return list<array<string,mixed>>
     */
    private static function hydrate(PDO $db, array $candidateMatches, int $limit): array
    {
        if ($candidateMatches === []) {
            return [];
        }
        $ids = array_keys($candidateMatches);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::queryRows(
            $db,
            'final_file_lookup',
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
                . 'f.file_size,f.package_guid,f.md5,f.sha1,g.name game_name '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'WHERE f.scan_status="verified" AND f.id IN (' . $placeholders . ') '
                . 'ORDER BY g.name,f.package_name,f.original_name LIMIT ' . $limit,
            $ids
        );
        foreach ($rows as &$row) {
            $row['matched_fields'] = $candidateMatches[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    /** @param list<mixed> $args @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectStage(
        PDO $db,
        string $stage,
        string $sql,
        array $args,
        string $field,
        int $limit,
        array &$candidateMatches
    ): void {
        $remaining = $limit - count($candidateMatches);
        if ($remaining <= 0) {
            return;
        }
        self::collectMatches(
            $db,
            $stage,
            $sql . ' LIMIT ' . max(1, $remaining),
            $args,
            $field,
            $candidateMatches
        );
    }

    /** @param list<mixed> $args @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectMatches(PDO $db, string $stage, string $sql, array $args, string $field, array &$candidateMatches): void
    {
        foreach (self::queryRows($db, $stage, $sql, $args) as $row) {
            $fileId = (int)$row['id'];
            $value = trim((string)($row['match_value'] ?? ''));
            if ($fileId < 1 || $value === '') {
                continue;
            }
            $candidateMatches[$fileId] ??= [];
            if (count($candidateMatches[$fileId]) >= self::MAX_MATCHES_PER_FILE) {
                continue;
            }
            foreach ($candidateMatches[$fileId] as $match) {
                if ($match['field'] === $field && $match['value'] === $value) {
                    continue 2;
                }
            }
            $candidateMatches[$fileId][] = ['field' => $field, 'value' => $value];
        }
    }

    /** @param list<mixed> $args @return list<array<string,mixed>> */
    private static function queryRows(PDO $db, string $stage, string $sql, array $args): array
    {
        $startedAt = hrtime(true);
        try {
            $rows = \catalog_all($db, $sql, $args);
        } catch (PDOException $exception) {
            self::logFailure($stage, $startedAt, $exception);
            throw new CatalogSearchUnavailableException('The search service is temporarily unavailable.', 0, $exception);
        }
        $elapsedMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
        if ($elapsedMs >= 1000) {
            error_log('[UnrealDB search] stage=' . $stage . ' elapsed_ms=' . $elapsedMs . ' result_rows=' . count($rows));
        }
        return $rows;
    }

    private static function logFailure(string $stage, int $startedAt, PDOException $exception): void
    {
        $elapsedMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
        error_log('[UnrealDB search] stage=' . $stage . ' elapsed_ms=' . $elapsedMs . ' sqlstate=' . (string)$exception->getCode() . ' message=' . $exception->getMessage());
    }
}
