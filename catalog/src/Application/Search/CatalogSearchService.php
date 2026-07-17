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

    /** @return list<array<string,mixed>> */
    public static function findFiles(PDO $db, string $query, int $limit = 200, ?int $gameId = null): array
    {
        \catalog_package_aliases_ensure($db);

        $limit = max(1, min($limit, 500));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;
        $like = '%' . $query . '%';
        $candidateMatches = [];

        $fileGameSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND f.game_id=?');
        $fileGameArgs = $gameId === null ? [] : [$gameId];
        $aliasGameSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND a.game_id=?');
        $aliasGameArgs = $gameId === null ? [] : [$gameId];
        $importGameSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND f.game_id=?');
        $importGameArgs = $gameId === null ? [] : [$gameId];
        $exportGameSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND f.game_id=?');
        $exportGameArgs = $gameId === null ? [] : [$gameId];

        self::collectMatches($db, 'hash_md5', 'SELECT f.id,f.md5 match_value FROM ue_files f WHERE f.md5=?' . $fileGameSql . ' ORDER BY f.package_name,f.original_name LIMIT ' . $limit, array_merge([$query], $fileGameArgs), 'MD5', $candidateMatches);
        self::collectMatches($db, 'hash_sha1', 'SELECT f.id,f.sha1 match_value FROM ue_files f WHERE f.sha1=?' . $fileGameSql . ' ORDER BY f.package_name,f.original_name LIMIT ' . $limit, array_merge([$query], $fileGameArgs), 'SHA1', $candidateMatches);
        self::collectMatches($db, 'guid', 'SELECT f.id,f.package_guid match_value FROM ue_files f WHERE f.package_guid LIKE ?' . $fileGameSql . ' ORDER BY f.package_name,f.original_name LIMIT ' . $limit, array_merge([$like], $fileGameArgs), 'GUID', $candidateMatches);
        self::collectMatches($db, 'package_name', 'SELECT f.id,f.package_name match_value FROM ue_files f WHERE f.package_name LIKE ?' . $fileGameSql . ' ORDER BY f.package_name,f.original_name LIMIT ' . $limit, array_merge([$like], $fileGameArgs), 'Package', $candidateMatches);
        self::collectMatches($db, 'package_alias', 'SELECT a.file_id id,a.package_name match_value FROM ue_file_package_aliases a JOIN ue_files f ON f.id=a.file_id WHERE a.package_name LIKE ?' . $aliasGameSql . ' ORDER BY a.package_name,a.original_name LIMIT ' . $limit, array_merge([$like], $aliasGameArgs), 'Package alias', $candidateMatches);
        self::collectMatches($db, 'file_name', 'SELECT f.id,f.original_name match_value FROM ue_files f WHERE f.original_name LIKE ?' . $fileGameSql . ' ORDER BY f.package_name,f.original_name LIMIT ' . $limit, array_merge([$like], $fileGameArgs), 'File', $candidateMatches);
        self::collectMatches($db, 'alias_file_name', 'SELECT a.file_id id,a.original_name match_value FROM ue_file_package_aliases a JOIN ue_files f ON f.id=a.file_id WHERE a.original_name LIKE ?' . $aliasGameSql . ' ORDER BY a.package_name,a.original_name LIMIT ' . $limit, array_merge([$like], $aliasGameArgs), 'Alias file', $candidateMatches);
        self::collectMatches($db, 'import_path', 'SELECT i.file_id id,i.full_path match_value FROM ue_imports i JOIN ue_files f ON f.id=i.file_id WHERE i.full_path LIKE ?' . $importGameSql . ' ORDER BY i.file_id,i.import_index LIMIT ' . $limit, array_merge([$like], $importGameArgs), 'Import path', $candidateMatches);
        self::collectMatches($db, 'import_object', 'SELECT i.file_id id,i.object_name match_value FROM ue_imports i JOIN ue_files f ON f.id=i.file_id WHERE i.object_name LIKE ?' . $importGameSql . ' ORDER BY i.file_id,i.import_index LIMIT ' . $limit, array_merge([$like], $importGameArgs), 'Import object', $candidateMatches);
        self::collectMatches($db, 'export_path', 'SELECT e.file_id id,e.full_path match_value FROM ue_exports e JOIN ue_files f ON f.id=e.file_id WHERE e.full_path LIKE ?' . $exportGameSql . ' ORDER BY e.file_id,e.export_index LIMIT ' . $limit, array_merge([$like], $exportGameArgs), 'Export path', $candidateMatches);
        self::collectMatches($db, 'alias_export_path', 'SELECT f.id,CONCAT(a.package_name,".",e.local_path) match_value FROM ue_file_package_aliases a JOIN ue_files f ON f.id=a.file_id JOIN ue_exports e ON e.file_id=f.id WHERE CONCAT(a.package_name,".",e.local_path) LIKE ?' . $aliasGameSql . ' ORDER BY a.package_name,e.export_index LIMIT ' . $limit, array_merge([$like], $aliasGameArgs), 'Alias export path', $candidateMatches);
        self::collectMatches($db, 'export_object', 'SELECT e.file_id id,e.object_name match_value FROM ue_exports e JOIN ue_files f ON f.id=e.file_id WHERE e.object_name LIKE ?' . $exportGameSql . ' ORDER BY e.file_id,e.export_index LIMIT ' . $limit, array_merge([$like], $exportGameArgs), 'Export object', $candidateMatches);

        if ($candidateMatches === []) {
            return [];
        }

        $ids = array_keys($candidateMatches);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::queryRows(
            $db,
            'final_file_lookup',
            'SELECT f.*,g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.scan_status="verified" AND f.id IN (' . $placeholders . ') ORDER BY g.name,f.package_name,f.original_name LIMIT ' . $limit,
            $ids
        );
        foreach ($rows as &$row) {
            $row['matched_fields'] = $candidateMatches[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    /** @param list<mixed> $args @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectMatches(PDO $db, string $stage, string $sql, array $args, string $field, array &$candidateMatches): void
    {
        foreach (self::queryRows($db, $stage, $sql, $args) as $row) {
            $fileId = (int)$row['id'];
            $value = trim((string)($row['match_value'] ?? ''));
            if ($fileId < 1 || $value === '') continue;
            $candidateMatches[$fileId] ??= [];
            if (count($candidateMatches[$fileId]) >= self::MAX_MATCHES_PER_FILE) continue;
            foreach ($candidateMatches[$fileId] as $match) {
                if ($match['field'] === $field && $match['value'] === $value) continue 2;
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
        if ($elapsedMs >= 1000) error_log('[UnrealDB search] stage=' . $stage . ' elapsed_ms=' . $elapsedMs . ' result_rows=' . count($rows));
        return $rows;
    }

    private static function logFailure(string $stage, int $startedAt, PDOException $exception): void
    {
        $elapsedMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
        error_log('[UnrealDB search] stage=' . $stage . ' elapsed_ms=' . $elapsedMs . ' sqlstate=' . (string)$exception->getCode() . ' message=' . $exception->getMessage());
    }
}
