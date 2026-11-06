<?php
/**
 * Bounded, read-only catalogue *incoming import identity* existence check.
 *
 * An exact imported package key plus object-name key (classic), or FPackageId
 * plus PublicExportHash (Zen) establishes an indexed demand for that source
 * identity, not the exact runtime-selected provider or successful VerifyImport.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoFileExamineExportUsageQuery
{
    public function __construct(private readonly PDO $db) {}

    /**
     * @param list<int> $indices Original zero-based export indices on this page.
     * @return array<int,bool|null> true = cross-file import references same
     *   package/object identity, false = no indexed reference, null = unknown.
     */
    public function forExportPage(int $fileId, array $indices): array
    {
        $indices = array_values(array_unique(array_filter(
            array_map('intval', $indices), static fn(int $i): bool => $i >= 0
        )));
        if ($fileId < 1 || $indices === []) return [];
        if (count($indices) > 1000) return [];
        $placeholders = implode(',', array_fill(0, count($indices), '?'));
        $out = array_fill_keys($indices, null);
        try {
            $q = $this->db->prepare(
                'SELECT o.object_index,p.package_key_kind,o.public_export_hash '
                . 'FROM ue_uedb5_compact_object_candidates o '
                . 'JOIN ue_uedb5_provider_keys p ON p.file_id=o.file_id '
                . 'WHERE o.file_id=? AND o.object_kind=1 AND o.object_index IN ('
                . $placeholders . ')'
            );
            $q->execute(array_merge([$fileId], $indices));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $i=(int)$row['object_index'];
                // A Zen export without PublicExportHash cannot be declared
                // unused simply because no public-hash import key can match.
                if ((int)$row['package_key_kind'] !== 2
                    || $row['public_export_hash'] !== null) {
                    $out[$i] = false;
                }
            }
            $sql = 'SELECT /*+ MAX_EXECUTION_TIME(2500) */ o.object_index '
                . 'FROM ue_uedb5_compact_object_candidates o '
                . 'JOIN ue_uedb5_compact_name_dictionary n ON n.name_id=o.name_id '
                . 'JOIN ue_uedb5_provider_keys p ON p.file_id=o.file_id '
                . 'JOIN ue_uedb5_dependency_edges e FORCE INDEX (idx_ue_uedb5_dep_required) '
                . 'ON e.required_package_key_kind=p.package_key_kind '
                . 'AND e.required_package_key=p.package_key '
                . 'AND e.source_kind=1 AND e.file_id<>o.file_id '
                . 'AND ((p.package_key_kind=2 AND e.required_object_key_kind=2 '
                . 'AND e.required_object_key=o.public_export_hash) '
                . 'OR (p.package_key_kind<>2 AND e.required_object_key_kind=1 '
                . 'AND e.required_object_key=n.name_key_hash)) '
                . 'JOIN ue_files importer ON importer.id=e.file_id '
                . 'AND importer.game_id=p.game_id AND importer.scan_status="verified" '
                . 'WHERE o.file_id=? AND o.object_kind=1 '
                . 'AND o.object_index IN (' . $placeholders . ') '
                . 'GROUP BY o.object_index';
            $q = $this->db->prepare($sql);
            $q->execute(array_merge([$fileId], $indices));
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $index) {
                $out[(int)$index] = true;
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB export coverage] file ' . $fileId
                . ' indexed reverse-name probe unavailable: ' . $error->getMessage());
            // An unmeasured row must never be labelled unused.
            return array_fill_keys($indices, null);
        }
        return $out;
    }
}
