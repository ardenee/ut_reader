<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

use PDO;
use PDOException;

final class CatalogSearchUnavailableException extends \RuntimeException
{
}

/**
 * Read-only catalog search use case.
 *
 * Search stages retain the matched catalog field/value so result pages can
 * explain why a package was returned instead of exposing only its file ID.
 */
final class CatalogSearchService
{
    private const MAX_MATCHES_PER_FILE = 12;

    /**
     * @return list<array<string, mixed>>
     */
    public static function findFiles(PDO $db, string $query, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $like = '%' . $query . '%';
        $candidateMatches = [];

        self::collectMatches(
            $db,
            'hash_md5',
            'SELECT f.id, f.md5 match_value FROM ue_files f WHERE f.md5=? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$query],
            'MD5',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'hash_sha1',
            'SELECT f.id, f.sha1 match_value FROM ue_files f WHERE f.sha1=? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$query],
            'SHA1',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'guid',
            'SELECT f.id, f.package_guid match_value FROM ue_files f WHERE f.package_guid LIKE ? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like],
            'GUID',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'package_name',
            'SELECT f.id, f.package_name match_value FROM ue_files f WHERE f.package_name LIKE ? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like],
            'Package',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'file_name',
            'SELECT f.id, f.original_name match_value FROM ue_files f WHERE f.original_name LIKE ? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like],
            'File',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'import_path',
            'SELECT i.file_id id, i.full_path match_value FROM ue_imports i WHERE i.full_path LIKE ? ORDER BY i.file_id, i.import_index LIMIT ' . $limit,
            [$like],
            'Import path',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'import_object',
            'SELECT i.file_id id, i.object_name match_value FROM ue_imports i WHERE i.object_name LIKE ? ORDER BY i.file_id, i.import_index LIMIT ' . $limit,
            [$like],
            'Import object',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'export_path',
            'SELECT e.file_id id, e.full_path match_value FROM ue_exports e WHERE e.full_path LIKE ? ORDER BY e.file_id, e.export_index LIMIT ' . $limit,
            [$like],
            'Export path',
            $candidateMatches
        );
        self::collectMatches(
            $db,
            'export_object',
            'SELECT e.file_id id, e.object_name match_value FROM ue_exports e WHERE e.object_name LIKE ? ORDER BY e.file_id, e.export_index LIMIT ' . $limit,
            [$like],
            'Export object',
            $candidateMatches
        );

        if ($candidateMatches === []) {
            return [];
        }

        $ids = array_keys($candidateMatches);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::queryRows(
            $db,
            'final_file_lookup',
            'SELECT f.* FROM ue_files f WHERE f.id IN (' . $placeholders . ') ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            $ids
        );

        foreach ($rows as &$row) {
            $row['matched_fields'] = $candidateMatches[(int)$row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<mixed> $args
     * @param array<int, list<array{field:string,value:string}>> $candidateMatches
     */
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

            $candidateMatches[$fileId][] = [
                'field' => $field,
                'value' => $value,
            ];
        }
    }

    /**
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
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
        error_log(
            '[UnrealDB search] stage=' . $stage
            . ' elapsed_ms=' . $elapsedMs
            . ' sqlstate=' . (string)$exception->getCode()
            . ' message=' . $exception->getMessage()
        );
    }
}
