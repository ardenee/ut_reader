<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads dependency views exclusively from current format-2 metadata and compact projections.
 * Why: Verified dependency pages must have one authoritative representation and must not merge or fall back to retired
 *      ue_dependencies/ue_imports/ue_exports tables.
 * Role: Infrastructure current-metadata dependency read service.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

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
        $statement = $this->db->prepare(
            'SELECT m.format_version FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
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

    /** @return list<array<string,mixed>> */
    public function compactRows(int $fileId): array
    {
        $this->requireCurrentFile($fileId);
        $storagePath = $this->storagePath();
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
            $comparison = ($weights[(string)($left['status'] ?? 'missing')] ?? 9)
                <=> ($weights[(string)($right['status'] ?? 'missing')] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            foreach (['resolution_confidence', 'resolution_source', 'required_package', 'required_object_path'] as $field) {
                $comparison = strnatcasecmp((string)($left[$field] ?? ''), (string)($right[$field] ?? ''));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return (int)$left['import_index'] <=> (int)$right['import_index'];
        });
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function rows(int $fileId): array
    {
        return $this->compactRows($fileId);
    }

    /** @return list<array<string,mixed>> */
    public function usedByRows(int $targetFileId, int $limit = 200): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Current compact dependency projections are unavailable.');
        }
        $limit = max(1, min(5000, $limit));
        $statement = $this->db->prepare(
            'SELECT DISTINCT src.id,src.package_name,src.original_name,src.package_guid,src.md5,src.file_size'
            . ' FROM ue_dependency_links l'
            . ' JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2'
            . ' JOIN ue_files src ON src.id=l.file_id AND src.scan_status="verified"'
            . ' WHERE l.resolved_file_id=?'
            . ' ORDER BY src.package_name,src.original_name LIMIT ' . $limit
        );
        $statement->execute([$targetFileId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
            $key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $identityNames
     * @return list<array<string,mixed>>
     */
    public function reverseRows(int $gameId, int $targetFileId, array $identityNames): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Current compact dependency projections are unavailable.');
        }
        $identityNames = self::uniqueStrings($identityNames);
        $identityPredicates = [];
        $parameters = [$gameId, $targetFileId, $targetFileId];
        foreach ($identityNames as $identityName) {
            $identityPredicates[] = '(package_term.value_hash=? AND package_term.value_length=?)';
            $parameters[] = md5($identityName, true);
            $parameters[] = strlen($identityName);
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
        $statement->execute($parameters);
        $compactRows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reader = $compactRows !== []
            ? new BlockedCompressedMetadataReader($this->db, $this->storagePath())
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

        $rows = [];
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

        usort($rows, static function (array $left, array $right): int {
            $comparison = strnatcasecmp((string)$left['original_name'], (string)$right['original_name']);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = (int)$left['source_file_id'] <=> (int)$right['source_file_id'];
            return $comparison !== 0
                ? $comparison
                : (int)($left['import_index'] ?? 0) <=> (int)($right['import_index'] ?? 0);
        });
        return $rows;
    }

    private function requireCurrentFile(int $fileId): void
    {
        if ($this->metadataVersion($fileId) !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException(
                'Verified file #' . $fileId . ' is missing current format-2 dependency metadata; runtime legacy reads are disabled.'
            );
        }
    }

    private function storagePath(): string
    {
        $storagePath = trim((string)($this->config['storage_path'] ?? ''));
        if ($storagePath === '') {
            throw new RuntimeException('Catalog storage_path is not configured.');
        }
        return $storagePath;
    }
}
