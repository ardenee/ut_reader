<?php
/**
 * PDO-backed authoritative dependency read source for current format-2 metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class PdoDependencyReadSource
{
    /** @var array<int,bool> */
    private static array $compactAvailability = [];

    private const TEXT_COLLATION = 'utf8mb4_unicode_ci';

    public static function compactAvailable(PDO $db): bool
    {
        $key = spl_object_id($db);
        if (array_key_exists($key, self::$compactAvailability)) {
            return self::$compactAvailability[$key];
        }

        $tables = ['ue_file_metadata', 'ue_dependency_links', 'ue_terms'];
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($tables), '?')) . ')'
        );
        $statement->execute($tables);
        if ((int)$statement->fetchColumn() !== count($tables)) {
            return self::$compactAvailability[$key] = false;
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
        return self::$compactAvailability[$key] = ((int)$statement->fetchColumn() === count($columns));
    }

    public static function sql(PDO $db): string
    {
        if (!self::compactAvailable($db)) {
            throw new RuntimeException(
                'Current compact dependency metadata is unavailable; runtime legacy dependency reads are disabled.'
            );
        }

        $collation = self::TEXT_COLLATION;
        $syntheticId = 'CAST((l.file_id * 4294967296) + l.import_index + 1 AS UNSIGNED)';

        return '('
            . 'SELECT '
            . $syntheticId . ' id,'
            . 'l.file_id,' . $syntheticId . ' import_id,l.import_index,'
            . '(CONVERT(package_term.value_prefix USING utf8mb4) COLLATE ' . $collation . ') required_package,'
            . '(CONVERT(object_term.value_prefix USING utf8mb4) COLLATE ' . $collation . ') required_object_path,'
            . 'l.resolved_file_id,NULL resolved_export_id,l.resolved_export_index,'
            . '(CAST(CASE l.status '
            . 'WHEN 1 THEN "resolved" WHEN 2 THEN "package_only" '
            . 'WHEN 3 THEN "common" ELSE "missing" END AS CHAR CHARACTER SET utf8mb4) '
            . 'COLLATE ' . $collation . ') status,'
            . '(CAST(COALESCE(CONVERT(source_term.value_prefix USING utf8mb4),"unknown") '
            . 'AS CHAR CHARACTER SET utf8mb4) COLLATE ' . $collation . ') resolution_source,'
            . '(CAST(COALESCE(CONVERT(confidence_term.value_prefix USING utf8mb4),"unknown") '
            . 'AS CHAR CHARACTER SET utf8mb4) COLLATE ' . $collation . ') resolution_confidence,'
            . '(CAST(COALESCE(CONVERT(class_package_term.value_prefix USING utf8mb4),"") '
            . 'AS CHAR CHARACTER SET utf8mb4) COLLATE ' . $collation . ') class_package,'
            . '(CAST(COALESCE(CONVERT(class_name_term.value_prefix USING utf8mb4),"") '
            . 'AS CHAR CHARACTER SET utf8mb4) COLLATE ' . $collation . ') class_name,'
            . '(CONVERT(object_term.value_prefix USING utf8mb4) COLLATE ' . $collation . ') import_full_path,'
            . '(CAST("compact" AS CHAR CHARACTER SET utf8mb4) COLLATE ' . $collation . ') metadata_source '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
            . 'JOIN ue_terms package_term ON package_term.id=l.required_package_term_id '
            . 'JOIN ue_terms object_term ON object_term.id=l.required_object_term_id '
            . 'LEFT JOIN ue_terms class_package_term ON class_package_term.id=l.import_class_package_term_id '
            . 'LEFT JOIN ue_terms class_name_term ON class_name_term.id=l.import_class_name_term_id '
            . 'LEFT JOIN ue_terms source_term ON source_term.id=l.resolution_source_term_id '
            . 'LEFT JOIN ue_terms confidence_term ON confidence_term.id=l.resolution_confidence_term_id'
            . ')';
    }
}
