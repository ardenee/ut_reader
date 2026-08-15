<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the retired metadata vocabulary used by compact-runtime audits.
 * Why: Current catalogue reads use compact metadata/projections exclusively; normal runtime PHP must never reference retired storage.
 * Role: Pure Application maintenance policy. Filesystem traversal is owned by Infrastructure.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

final class LegacyMetadataRuntimeAudit
{
    /** @var list<string> */
    private const METADATA_TABLES = [
        'ue_names',
        'ue_imports',
        'ue_exports',
        'ue_dependencies',
    ];

    /** @var list<string> */
    private const OTHER_RETIRED_TABLES = [
        'ue_search_documents',
    ];

    /** @return list<string> */
    public static function retiredMetadataTables(): array
    {
        return self::METADATA_TABLES;
    }

    /** @return list<string> */
    public static function retiredTables(): array
    {
        return array_merge(self::METADATA_TABLES, self::OTHER_RETIRED_TABLES);
    }

    /** @return list<string> */
    public static function retiredMetadataReferences(string $source): array
    {
        return self::references($source, self::METADATA_TABLES);
    }

    /** @param list<string> $tables @return list<string> */
    private static function references(string $source, array $tables): array
    {
        $found = [];
        foreach ($tables as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $source) === 1) {
                $found[] = $table;
            }
        }
        return $found;
    }
}
