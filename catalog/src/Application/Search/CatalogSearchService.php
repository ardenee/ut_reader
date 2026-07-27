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
    private const MAX_DOCUMENT_ROWS = 5000;

    private static ?bool $searchDocumentsAvailable = null;

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
        self::collectAliasPrefix($db, $gameId, $prefix, 'package_name', 'Package alias', $limit, $candidateMatches);
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
        self::collectAliasPrefix($db, $gameId, $prefix, 'original_name', 'Alias file', $limit, $candidateMatches);

        if (count($candidateMatches) < $limit) {
            if (self::searchDocumentsAvailable($db)) {
                self::collectDocumentFulltext($db, $gameId, $query, $limit, $candidateMatches);
                if (count($candidateMatches) < $limit) {
                    self::collectDocumentContains($db, $gameId, $query, $like, $limit, $candidateMatches);
                }
            } else {
                self::collectLegacyBroad($db, $gameId, $like, $limit, $candidateMatches);
            }
        }

        if (count($candidateMatches) < $limit) {
            self::collectAliasExportPath($db, $gameId, $query, $limit, $candidateMatches);
        }

        return self::hydrate($db, $candidateMatches, $limit);
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectAliasPrefix(
        PDO $db,
        ?int $gameId,
        string $prefix,
        string $column,
        string $label,
        int $limit,
        array &$candidateMatches
    ): void {
        $stage = $column === 'package_name' ? 'package_alias_prefix' : 'alias_file_name_prefix';
        $sql = 'SELECT a.file_id id,a.' . $column . ' match_value FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'WHERE f.scan_status="verified" AND a.' . $column . ' LIKE ?';
        $args = [$prefix];
        if ($gameId !== null) {
            $sql .= ' AND a.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY a.' . $column . ',a.id';
        self::collectStage($db, $stage, $sql, $args, $label, $limit, $candidateMatches);
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectDocumentFulltext(
        PDO $db,
        ?int $gameId,
        string $query,
        int $limit,
        array &$candidateMatches
    ): void {
        $booleanQuery = self::booleanQuery($query);
        if ($booleanQuery === '') {
            return;
        }

        $rowLimit = self::documentRowLimit($limit, $candidateMatches);
        $sql = 'SELECT d.file_id id,d.document_type,d.primary_value,d.secondary_value,'
            . 'MATCH(d.primary_value,d.secondary_value) AGAINST (? IN BOOLEAN MODE) score '
            . 'FROM ue_search_documents d WHERE ';
        $args = [$booleanQuery];
        if ($gameId !== null) {
            $sql .= 'd.game_id=? AND ';
            $args[] = $gameId;
        }
        $sql .= 'MATCH(d.primary_value,d.secondary_value) AGAINST (? IN BOOLEAN MODE) '
            . 'ORDER BY score DESC,FIELD(d.document_type,"file","alias","export","import"),d.file_id,d.id '
            . 'LIMIT ' . $rowLimit;
        $args[] = $booleanQuery;

        try {
            $rows = self::queryRows($db, 'search_documents_fulltext', $sql, $args);
        } catch (CatalogSearchUnavailableException $error) {
            error_log('[UnrealDB search] FULLTEXT stage unavailable; continuing with document LIKE fallback: ' . $error->getMessage());
            return;
        }
        self::collectDocumentRows($rows, $query, false, $candidateMatches);
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectDocumentContains(
        PDO $db,
        ?int $gameId,
        string $query,
        string $like,
        int $limit,
        array &$candidateMatches
    ): void {
        $rowLimit = self::documentRowLimit($limit, $candidateMatches);
        $sql = 'SELECT d.file_id id,d.document_type,d.primary_value,d.secondary_value '
            . 'FROM ue_search_documents d WHERE ';
        $args = [];
        if ($gameId !== null) {
            $sql .= 'd.game_id=? AND ';
            $args[] = $gameId;
        }
        $sql .= '(d.primary_value LIKE ? OR d.secondary_value LIKE ?) '
            . 'ORDER BY FIELD(d.document_type,"file","alias","export","import"),d.primary_value,d.file_id,d.id '
            . 'LIMIT ' . $rowLimit;
        $args[] = $like;
        $args[] = $like;

        $rows = self::queryRows($db, 'search_documents_contains', $sql, $args);
        self::collectDocumentRows($rows, $query, true, $candidateMatches);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,list<array{field:string,value:string}>> $candidateMatches
     */
    private static function collectDocumentRows(
        array $rows,
        string $query,
        bool $requireExactContains,
        array &$candidateMatches
    ): void {
        $tokens = self::queryTokens($query);
        foreach ($rows as $row) {
            $fileId = (int)($row['id'] ?? 0);
            if ($fileId < 1) {
                continue;
            }

            [$primaryLabel, $secondaryLabel] = self::documentLabels((string)($row['document_type'] ?? ''));
            $primary = trim((string)($row['primary_value'] ?? ''));
            $secondary = trim((string)($row['secondary_value'] ?? ''));

            $primaryMatch = $primary !== '' && ($requireExactContains
                ? self::contains($primary, $query)
                : self::containsAnyToken($primary, $tokens));
            $secondaryMatch = $secondary !== '' && ($requireExactContains
                ? self::contains($secondary, $query)
                : self::containsAnyToken($secondary, $tokens));

            if (!$primaryMatch && !$secondaryMatch && !$requireExactContains) {
                $primaryMatch = $primary !== '';
            }
            if ($primaryMatch) {
                self::addMatch($candidateMatches, $fileId, $primaryLabel, $primary);
            }
            if ($secondaryMatch) {
                self::addMatch($candidateMatches, $fileId, $secondaryLabel, $secondary);
            }
        }
    }

    /** @return array{string,string} */
    private static function documentLabels(string $type): array
    {
        return match ($type) {
            'file' => ['Package', 'File'],
            'alias' => ['Package alias', 'Alias file'],
            'import' => ['Import object', 'Import path'],
            'export' => ['Export object', 'Export path'],
            default => ['Search value', 'Search path'],
        };
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectAliasExportPath(
        PDO $db,
        ?int $gameId,
        string $query,
        int $limit,
        array &$candidateMatches
    ): void {
        $separator = strpos($query, '.');
        if ($separator === false) {
            return;
        }
        $packageName = trim(substr($query, 0, $separator));
        $localQuery = trim(substr($query, $separator + 1));
        if ($packageName === '' || $localQuery === '') {
            return;
        }

        $sql = 'SELECT a.file_id id,CONCAT(a.package_name,".",e.local_path) match_value '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'JOIN ue_exports e ON e.file_id=f.id '
            . 'WHERE f.scan_status="verified" AND a.package_name=? AND e.local_path LIKE ?';
        $args = [$packageName, '%' . $localQuery . '%'];
        if ($gameId !== null) {
            $sql .= ' AND a.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY a.package_name,e.export_index';
        self::collectStage(
            $db,
            'alias_export_path_targeted',
            $sql,
            $args,
            'Alias export path',
            $limit,
            $candidateMatches
        );
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectLegacyBroad(
        PDO $db,
        ?int $gameId,
        string $like,
        int $limit,
        array &$candidateMatches
    ): void {
        $fileSql = $gameId === null ? '' : ' AND f.game_id=?';
        $fileArgs = $gameId === null ? [] : [$gameId];
        foreach ([
            ['package_name_contains', 'ue_files f', 'f.id', 'f.package_name', 'Package', 'f.package_name'],
            ['file_name_contains', 'ue_files f', 'f.id', 'f.original_name', 'File', 'f.original_name'],
            ['import_object_legacy', 'ue_imports x JOIN ue_files f ON f.id=x.file_id', 'x.file_id', 'x.object_name', 'Import object', 'x.import_index'],
            ['export_object_legacy', 'ue_exports x JOIN ue_files f ON f.id=x.file_id', 'x.file_id', 'x.object_name', 'Export object', 'x.export_index'],
            ['import_path_legacy', 'ue_imports x JOIN ue_files f ON f.id=x.file_id', 'x.file_id', 'x.full_path', 'Import path', 'x.import_index'],
            ['export_path_legacy', 'ue_exports x JOIN ue_files f ON f.id=x.file_id', 'x.file_id', 'x.full_path', 'Export path', 'x.export_index'],
        ] as [$stage, $from, $id, $column, $label, $order]) {
            self::collectStage(
                $db,
                $stage,
                'SELECT ' . $id . ' id,' . $column . ' match_value FROM ' . $from
                    . ' WHERE f.scan_status="verified" AND ' . $column . ' LIKE ?'
                    . $fileSql . ' ORDER BY ' . $id . ',' . $order,
                array_merge([$like], $fileArgs),
                $label,
                $limit,
                $candidateMatches
            );
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

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches
     *  @return list<array<string,mixed>>
     */
    private static function hydrate(PDO $db, array $candidateMatches, int $limit): array
    {
        if ($candidateMatches === []) {
            return [];
        }
        $ids = array_slice(array_keys($candidateMatches), 0, $limit);
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
            $sql . ' LIMIT ' . max(1, $remaining * 2),
            $args,
            $field,
            $candidateMatches
        );
    }

    /** @param list<mixed> $args @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function collectMatches(PDO $db, string $stage, string $sql, array $args, string $field, array &$candidateMatches): void
    {
        foreach (self::queryRows($db, $stage, $sql, $args) as $row) {
            self::addMatch(
                $candidateMatches,
                (int)($row['id'] ?? 0),
                $field,
                trim((string)($row['match_value'] ?? ''))
            );
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private static function addMatch(array &$candidateMatches, int $fileId, string $field, string $value): void
    {
        if ($fileId < 1 || $value === '') {
            return;
        }
        $candidateMatches[$fileId] ??= [];
        if (count($candidateMatches[$fileId]) >= self::MAX_MATCHES_PER_FILE) {
            return;
        }
        foreach ($candidateMatches[$fileId] as $match) {
            if ($match['field'] === $field && $match['value'] === $value) {
                return;
            }
        }
        $candidateMatches[$fileId][] = ['field' => $field, 'value' => $value];
    }

    private static function searchDocumentsAvailable(PDO $db): bool
    {
        if (self::$searchDocumentsAvailable !== null) {
            return self::$searchDocumentsAvailable;
        }
        try {
            $db->query('SELECT 1 FROM ue_search_documents LIMIT 0');
            self::$searchDocumentsAvailable = true;
        } catch (PDOException) {
            self::$searchDocumentsAvailable = false;
        }
        return self::$searchDocumentsAvailable;
    }

    private static function documentRowLimit(int $limit, array $candidateMatches): int
    {
        $remaining = max(1, $limit - count($candidateMatches));
        return min(self::MAX_DOCUMENT_ROWS, max(100, $remaining * self::MAX_MATCHES_PER_FILE));
    }

    private static function booleanQuery(string $query): string
    {
        $terms = [];
        foreach (self::queryTokens($query) as $token) {
            if (mb_strlen($token, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH) {
                continue;
            }
            $terms[] = '+' . $token . '*';
            if (count($terms) >= 8) {
                break;
            }
        }
        return implode(' ', $terms);
    }

    /** @return list<string> */
    private static function queryTokens(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query, 'UTF-8'));
        if (!is_array($tokens)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            $tokens,
            static fn(string $token): bool => $token !== ''
        )));
    }

    /** @param list<string> $tokens */
    private static function containsAnyToken(string $value, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (mb_stripos($value, $token, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function contains(string $value, string $query): bool
    {
        return mb_stripos($value, $query, 0, 'UTF-8') !== false;
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
