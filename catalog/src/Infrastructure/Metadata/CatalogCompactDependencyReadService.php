<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads dependency views across compact format-2 metadata and legacy fallback rows.
 * Why: Schema/version checks, blocked-container hydration and reverse/used-by query composition form one metadata read boundary rather than a procedural helper collection.
 * Role: Infrastructure metadata read service preserving the existing compact dependency page/query contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class CatalogCompactDependencyReadService
{
    /** @var array<int,bool> */
    private static array $availabilityCache = [];

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
    }

    public function available(): bool
    {
        $key = spl_object_id($this->db);
        if (array_key_exists($key, self::$availabilityCache)) {
            return self::$availabilityCache[$key];
        }

        $tables = ['ue_file_metadata', 'ue_dependency_links', 'ue_terms'];
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($tables), '?')) . ')'
        );
        $statement->execute($tables);
        if ((int)$statement->fetchColumn() !== count($tables)) {
            return self::$availabilityCache[$key] = false;
        }

        $columns = ['resolution_source_term_id', 'resolution_confidence_term_id'];
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_dependency_links" '
            . 'AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute($columns);
        return self::$availabilityCache[$key] = ((int)$statement->fetchColumn() === count($columns));
    }

    public function metadataVersion(int $fileId): int
    {
        if ($fileId < 1 || !$this->available()) {
            return 0;
        }
        $statement = $this->db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
        $statement->execute([$fileId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    public static function statusLabel(int $status): string
    {
        return match ($status) {
            1 => 'resolved',
            2 => 'package_only',
            3 => 'common',
            default => 'missing',
        };
    }

    /**
     * Read one converted file's dependencies from the blocked metadata container and
     * merge the current compact resolution projection. Returns null for an
     * unconverted file so callers can use the legacy SQL fallback.
     *
     * @return list<array<string,mixed>>|null
     */
    public function compactRows(int $fileId): ?array
    {
        if ($this->metadataVersion($fileId) < 2) {
            return null;
        }

        $storagePath = trim((string)($this->config['storage_path'] ?? ''));
        if ($storagePath === '') {
            throw new RuntimeException('catalog storage_path is not configured.');
        }

        $reader = new BlockedCompressedMetadataReader($this->db, $storagePath);
        $blockedByImport = [];
        $start = 0;
        $pageSize = 1000;
        do {
            $page = $reader->page($fileId, 'dependencies', $start, $pageSize);
            foreach ($page as $row) {
                $blockedByImport[(int)$row['import_index']] = $row;
            }
            $start += count($page);
        } while (count($page) === $pageSize);

        $statement = $this->db->prepare(
            'SELECT l.file_id,l.import_index,l.resolved_file_id,l.resolved_export_index,l.status,'
            . ' package_term.value_prefix required_package_prefix,'
            . ' source_term.value_prefix resolution_source_label,'
            . ' confidence_term.value_prefix resolution_confidence_label,'
            . ' rf.id resolved_id,rf.package_name resolved_package,rf.original_name resolved_file,'
            . ' rf.package_guid resolved_guid,rf.md5 resolved_md5,rf.file_size resolved_size'
            . ' FROM ue_dependency_links l'
            . ' JOIN ue_terms package_term ON package_term.id=l.required_package_term_id'
            . ' LEFT JOIN ue_terms source_term ON source_term.id=l.resolution_source_term_id'
            . ' LEFT JOIN ue_terms confidence_term ON confidence_term.id=l.resolution_confidence_term_id'
            . ' LEFT JOIN ue_files rf ON rf.id=l.resolved_file_id'
            . ' WHERE l.file_id=? ORDER BY l.import_index'
        );
        $statement->execute([$fileId]);
        $links = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($links) !== count($blockedByImport)) {
            throw new RuntimeException(
                'Compact dependency row count mismatch for file #' . $fileId
                . ': container=' . count($blockedByImport) . ', projection=' . count($links) . '.'
            );
        }

        $rows = [];
        foreach ($links as $link) {
            $importIndex = (int)$link['import_index'];
            $blocked = $blockedByImport[$importIndex] ?? null;
            if (!is_array($blocked)) {
                throw new RuntimeException(
                    'Compact dependency projection references missing import index '
                    . $importIndex . ' for file #' . $fileId . '.'
                );
            }
            if ($link['resolution_source_label'] === null || $link['resolution_confidence_label'] === null) {
                throw new RuntimeException(
                    'Compact dependency resolution labels are incomplete for file #'
                    . $fileId . ', import #' . $importIndex . '.'
                );
            }

            $rows[] = [
                'id' => $importIndex + 1,
                'file_id' => $fileId,
                'import_id' => null,
                'import_index' => $importIndex,
                'required_package' => (string)$blocked['required_package'],
                'required_object_path' => (string)$blocked['required_object_path'],
                'resolved_file_id' => $link['resolved_file_id'] !== null ? (int)$link['resolved_file_id'] : null,
                'resolved_export_id' => null,
                'resolved_export_index' => $link['resolved_export_index'] !== null
                    ? (int)$link['resolved_export_index']
                    : null,
                'status' => self::statusLabel((int)$link['status']),
                'resolution_source' => (string)$link['resolution_source_label'],
                'resolution_confidence' => (string)$link['resolution_confidence_label'],
                'resolved_id' => $link['resolved_id'] !== null ? (int)$link['resolved_id'] : null,
                'resolved_package' => (string)($link['resolved_package'] ?? ''),
                'resolved_file' => (string)($link['resolved_file'] ?? ''),
                'resolved_guid' => (string)($link['resolved_guid'] ?? ''),
                'resolved_md5' => (string)($link['resolved_md5'] ?? ''),
                'resolved_size' => $link['resolved_size'] !== null ? (int)$link['resolved_size'] : 0,
                '_metadata_source' => 'compact',
            ];
        }

        $weights = ['missing' => 0, 'package_only' => 1, 'resolved' => 2, 'common' => 3];
        usort($rows, static function (array $left, array $right) use ($weights): int {
            $leftStatus = (string)($left['status'] ?? 'missing');
            $rightStatus = (string)($right['status'] ?? 'missing');
            $comparison = ($weights[$leftStatus] ?? 9) <=> ($weights[$rightStatus] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            foreach (['resolution_confidence', 'resolution_source', 'required_package', 'required_object_path'] as $field) {
                $comparison = strnatcasecmp(
                    (string)($left[$field] ?? ''),
                    (string)($right[$field] ?? '')
                );
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return (int)$left['import_index'] <=> (int)$right['import_index'];
        });

        return $rows;
    }

    /**
     * Return dependency rows in the legacy page shape. Converted files use compact
     * metadata; unconverted files use the legacy fallback only while those staging
     * tables still exist.
     *
     * @return list<array<string,mixed>>
     */
    public function rows(int $fileId): array
    {
        $compact = $this->compactRows($fileId);
        if (is_array($compact)) {
            return $compact;
        }
        if (!PdoDependencyReadSource::legacyAvailable($this->db)) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT d.*,i.import_index,re.export_index resolved_export_index,'
            . ' rf.id resolved_id,rf.package_name resolved_package,rf.original_name resolved_file,'
            . ' rf.package_guid resolved_guid,rf.md5 resolved_md5,rf.file_size resolved_size'
            . ' FROM ue_dependencies d'
            . ' LEFT JOIN ue_imports i ON i.id=d.import_id'
            . ' LEFT JOIN ue_exports re ON re.id=d.resolved_export_id'
            . ' LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id'
            . ' WHERE d.file_id=?'
            . ' ORDER BY FIELD(d.status,"missing","package_only","resolved","common"),'
            . ' d.resolution_confidence,d.resolution_source,d.required_package,d.required_object_path,d.id'
        );
        $statement->execute([$fileId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['_metadata_source'] = 'legacy';
        }
        unset($row);
        return $rows;
    }

    /**
     * Return files with resolved links to the target. Converted source files come
     * from ue_dependency_links; unconverted source files retain the legacy query
     * only while the staging tables exist.
     *
     * @return list<array<string,mixed>>
     */
    public function usedByRows(int $targetFileId, int $limit = 200): array
    {
        $limit = max(1, min(5000, $limit));
        $byFile = [];

        if ($this->available()) {
            $statement = $this->db->prepare(
                'SELECT DISTINCT src.id,src.package_name,src.original_name,src.package_guid,src.md5,src.file_size'
                . ' FROM ue_dependency_links l'
                . ' JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2'
                . ' JOIN ue_files src ON src.id=l.file_id'
                . ' WHERE l.resolved_file_id=?'
                . ' ORDER BY src.package_name,src.original_name LIMIT ' . $limit
            );
            $statement->execute([$targetFileId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $byFile[(int)$row['id']] = $row;
            }
        }

        if (PdoDependencyReadSource::legacyAvailable($this->db)) {
            $legacySql =
                'SELECT DISTINCT src.id,src.package_name,src.original_name,src.package_guid,src.md5,src.file_size'
                . ' FROM ue_dependencies d'
                . ' JOIN ue_files src ON src.id=d.file_id';
            if ($this->available()) {
                $legacySql .= ' LEFT JOIN ue_file_metadata m ON m.file_id=src.id AND m.format_version=2';
            }
            $legacySql .= ' WHERE d.resolved_file_id=?';
            if ($this->available()) {
                $legacySql .= ' AND m.file_id IS NULL';
            }
            $legacySql .= ' ORDER BY src.package_name,src.original_name LIMIT ' . $limit;
            $statement = $this->db->prepare($legacySql);
            $statement->execute([$targetFileId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $byFile[(int)$row['id']] = $row;
            }
        }

        $rows = array_values($byFile);
        usort($rows, static function (array $left, array $right): int {
            $comparison = strnatcasecmp(
                (string)$left['package_name'],
                (string)$right['package_name']
            );
            return $comparison !== 0
                ? $comparison
                : strnatcasecmp(
                    (string)$left['original_name'],
                    (string)$right['original_name']
                );
        });
        return array_slice($rows, 0, $limit);
    }

    /** @param list<string> $values @return list<string> */
    public static function uniqueStrings(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Return reverse dependency rows in the shape used by file-dependency-files.php.
     * Compact rows are merged with legacy rows from unconverted source files while
     * the legacy staging tables remain installed.
     *
     * @param list<string> $identityNames
     * @return list<array<string,mixed>>
     */
    public function reverseRows(
        int $gameId,
        int $targetFileId,
        array $identityNames
    ): array {
        $identityNames = self::uniqueStrings($identityNames);
        $rows = [];

        if ($this->available()) {
            $identityPredicates = [];
            $compactParameters = [$gameId, $targetFileId, $targetFileId];
            foreach ($identityNames as $identityName) {
                $identityPredicates[] = '(package_term.value_hash=? AND package_term.value_length=?)';
                $compactParameters[] = md5($identityName, true);
                $compactParameters[] = strlen($identityName);
            }
            $condition = 'l.resolved_file_id=?';
            if ($identityPredicates !== []) {
                $condition .= ' OR ' . implode(' OR ', $identityPredicates);
            }

            $statement = $this->db->prepare(
                'SELECT l.file_id source_file_id,l.import_index,l.resolved_file_id,l.status,'
                . ' package_term.value_prefix required_package_prefix,'
                . ' src.id,src.package_name,src.original_name,src.package_guid,src.md5,src.file_size'
                . ' FROM ue_dependency_links l'
                . ' JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2'
                . ' JOIN ue_files src ON src.id=l.file_id AND src.game_id=? AND src.scan_status="verified"'
                . ' JOIN ue_terms package_term ON package_term.id=l.required_package_term_id'
                . ' WHERE src.id<>? AND (' . $condition . ')'
                . ' ORDER BY src.original_name,l.import_index'
            );
            $statement->execute($compactParameters);
            $compactRows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $storagePath = trim((string)($this->config['storage_path'] ?? ''));
            if ($storagePath === '' && $compactRows !== []) {
                throw new RuntimeException('catalog storage_path is not configured.');
            }
            $reader = $compactRows !== []
                ? new BlockedCompressedMetadataReader($this->db, $storagePath)
                : null;
            $indexesByFile = [];
            foreach ($compactRows as $row) {
                $indexesByFile[(int)$row['source_file_id']][] = (int)$row['import_index'];
            }
            $detailsByFile = [];
            foreach ($indexesByFile as $sourceFileId => $indexes) {
                $detailsByFile[$sourceFileId] = $reader->dependenciesForImportIndexes(
                    $sourceFileId,
                    array_values(array_unique($indexes))
                );
            }

            foreach ($compactRows as $row) {
                $sourceFileId = (int)$row['source_file_id'];
                $importIndex = (int)$row['import_index'];
                $detail = $detailsByFile[$sourceFileId][$importIndex] ?? null;
                if (!is_array($detail)) {
                    throw new RuntimeException(
                        'Reverse compact dependency detail is missing for file #'
                        . $sourceFileId . ', import #' . $importIndex . '.'
                    );
                }
                $row['dependency_id'] = $importIndex + 1;
                $row['required_package'] = (string)$detail['required_package'];
                $row['required_object_path'] = (string)$detail['required_object_path'];
                $row['status'] = self::statusLabel((int)$row['status']);
                $rows[] = $row;
            }
        }

        if (PdoDependencyReadSource::legacyAvailable($this->db)) {
            $placeholders = implode(',', array_fill(0, count($identityNames), '?'));
            $legacySql =
                'SELECT d.id dependency_id,d.file_id source_file_id,d.required_package,d.required_object_path,'
                . ' d.status,d.resolved_file_id,src.id,src.package_name,src.original_name,src.package_guid,src.md5,src.file_size'
                . ' FROM ue_dependencies d'
                . ' JOIN ue_files src ON src.id=d.file_id AND src.game_id=? AND src.scan_status="verified"';
            if ($this->available()) {
                $legacySql .= ' LEFT JOIN ue_file_metadata m ON m.file_id=src.id AND m.format_version=2';
            }
            $legacySql .= ' WHERE src.id<>? AND (d.resolved_file_id=?';
            $legacyParameters = [$gameId, $targetFileId, $targetFileId];
            if ($identityNames !== []) {
                $legacySql .= ' OR d.required_package IN (' . $placeholders . ')';
                array_push($legacyParameters, ...$identityNames);
            }
            $legacySql .= ')';
            if ($this->available()) {
                $legacySql .= ' AND m.file_id IS NULL';
            }
            $legacySql .= ' ORDER BY src.original_name,d.id';
            $statement = $this->db->prepare($legacySql);
            $statement->execute($legacyParameters);
            array_push($rows, ...($statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
        }

        usort($rows, static function (array $left, array $right): int {
            $comparison = strnatcasecmp(
                (string)$left['original_name'],
                (string)$right['original_name']
            );
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = (int)$left['source_file_id'] <=> (int)$right['source_file_id'];
            return $comparison !== 0
                ? $comparison
                : (int)($left['import_index'] ?? $left['dependency_id'] ?? 0)
                    <=> (int)($right['import_index'] ?? $right['dependency_id'] ?? 0);
        });
        return $rows;
    }
}
