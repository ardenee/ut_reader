<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;

/**
 * Supplies one authoritative SQL read source during the compressed-metadata
 * transition. Format-2 files come from compact projections; every other file
 * continues to come from the legacy dependency/import tables.
 */
final class CatalogDependencyReadSource
{
    /** @var array<int,bool> */
    private static array $availability = [];

    public static function compactAvailable(PDO $db): bool
    {
        $key = spl_object_id($db);
        if (array_key_exists($key, self::$availability)) {
            return self::$availability[$key];
        }

        $tables = ['ue_file_metadata', 'ue_dependency_links', 'ue_terms'];
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($tables), '?')) . ')'
        );
        $statement->execute($tables);
        if ((int)$statement->fetchColumn() !== count($tables)) {
            return self::$availability[$key] = false;
        }

        $columns = [
            'required_object_term_id',
            'import_class_package_term_id',
            'import_class_name_term_id',
            'resolution_source_term_id',
            'resolution_confidence_term_id',
        ];
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_dependency_links" '
            . 'AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute($columns);
        return self::$availability[$key] = ((int)$statement->fetchColumn() === count($columns));
    }

    /**
     * Return a parenthesised SQL source exposing this stable row shape:
     * id, file_id, import_index, required_package, required_object_path,
     * resolved_file_id, status, class_package, class_name, import_full_path,
     * metadata_source.
     */
    public static function sql(PDO $db): string
    {
        if (!self::compactAvailable($db)) {
            return '(' . self::legacySelect(false) . ')';
        }

        $compact =
            'SELECT '
            . 'CAST((l.file_id * 4294967296) + l.import_index AS UNSIGNED) id,'
            . 'l.file_id,l.import_index,'
            . 'CONVERT(package_term.value_prefix USING utf8mb4) required_package,'
            . 'CONVERT(object_term.value_prefix USING utf8mb4) required_object_path,'
            . 'l.resolved_file_id,'
            . 'CASE l.status '
            . 'WHEN 1 THEN "resolved" WHEN 2 THEN "package_only" '
            . 'WHEN 3 THEN "common" ELSE "missing" END status,'
            . 'COALESCE(CONVERT(class_package_term.value_prefix USING utf8mb4),"") class_package,'
            . 'COALESCE(CONVERT(class_name_term.value_prefix USING utf8mb4),"") class_name,'
            . 'CONVERT(object_term.value_prefix USING utf8mb4) import_full_path,'
            . '"compact" metadata_source '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
            . 'JOIN ue_terms package_term ON package_term.id=l.required_package_term_id '
            . 'JOIN ue_terms object_term ON object_term.id=l.required_object_term_id '
            . 'LEFT JOIN ue_terms class_package_term ON class_package_term.id=l.import_class_package_term_id '
            . 'LEFT JOIN ue_terms class_name_term ON class_name_term.id=l.import_class_name_term_id';

        return '(' . $compact . ' UNION ALL ' . self::legacySelect(true) . ')';
    }

    private static function legacySelect(bool $excludeFormatTwo): string
    {
        $sql =
            'SELECT d.id,d.file_id,i.import_index,d.required_package,d.required_object_path,'
            . 'd.resolved_file_id,d.status,'
            . 'COALESCE(i.class_package,"") class_package,'
            . 'COALESCE(i.class_name,"") class_name,'
            . 'COALESCE(i.full_path,d.required_object_path) import_full_path,'
            . '"legacy" metadata_source '
            . 'FROM ue_dependencies d '
            . 'LEFT JOIN ue_imports i ON i.id=d.import_id';

        if ($excludeFormatTwo) {
            $sql .= ' LEFT JOIN ue_file_metadata m_legacy '
                . 'ON m_legacy.file_id=d.file_id AND m_legacy.format_version=2 '
                . 'WHERE m_legacy.file_id IS NULL';
        }

        return $sql;
    }
}
