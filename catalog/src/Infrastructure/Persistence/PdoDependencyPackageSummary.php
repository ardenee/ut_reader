<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Maintains package-level dependency summaries from authoritative dependency rows.
 * Why: Bulk reconciliation must refresh many affected files without opening one transaction per file.
 * Role: Infrastructure persistence projection used by dependency and catalogue reconciliation jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

/** Maintains compact package-level projections of authoritative dependency rows. */
final class PdoDependencyPackageSummary
{
    private const BULK_FILE_BATCH = 250;
    private const TEXT_COLLATION = 'utf8mb4_unicode_ci';

    /** @var array<int,bool> */
    private static array $availability = [];
    /** @var array<int,bool> */
    private static array $examplePathAvailability = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function available(): bool
    {
        $connectionId = spl_object_id($this->db);
        if (array_key_exists($connectionId, self::$availability)) {
            return self::$availability[$connectionId];
        }

        try {
            $statement = $this->db->query('SELECT 1 FROM ue_dependency_package_summaries LIMIT 0');
            self::$availability[$connectionId] = $statement !== false;
        } catch (Throwable) {
            self::$availability[$connectionId] = false;
        }

        return self::$availability[$connectionId];
    }

    /** @return array{file_id:int,summary_rows:int,available:bool} */
    public function rebuildFile(int $fileId): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Dependency package summary requires a positive file ID.');
        }
        $result = $this->rebuildFiles([$fileId]);
        return [
            'file_id' => $fileId,
            'summary_rows' => (int)$result['summary_rows'],
            'available' => (bool)$result['available'],
        ];
    }

    /**
     * Rebuild package summaries for many files in bounded transactions.
     *
     * The summary projection reads the compact lookup tables directly. The
     * generic dependency read source reconstructs human-readable source,
     * confidence and class labels that this aggregate never consumes; avoiding
     * those joins/conversions materially reduces work for large publications.
     *
     * @param list<int> $fileIds
     * @return array{files:int,summary_rows:int,available:bool}
     */
    public function rebuildFiles(array $fileIds): array
    {
        $fileIds = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($fileIds === []) {
            return ['files' => 0, 'summary_rows' => 0, 'available' => $this->available()];
        }
        if (!$this->available()) {
            return ['files' => count($fileIds), 'summary_rows' => 0, 'available' => false];
        }

        $summaryRows = 0;
        foreach (array_chunk($fileIds, self::BULK_FILE_BATCH) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $ownsTransaction = !$this->db->inTransaction();
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            try {
                $delete = $this->db->prepare(
                    'DELETE FROM ue_dependency_package_summaries WHERE file_id IN (' . $placeholders . ')'
                );
                $delete->execute($chunk);

                $exampleColumn = $this->hasExamplePathColumn();
                $insertColumns = 'game_id,file_id,required_package,'
                    . ($exampleColumn ? 'example_required_object_path,' : '')
                    . 'dependency_count,resolved_count,missing_count,package_only_count,common_count,summary_status,provider_file_id';
                $collation = self::TEXT_COLLATION;
                $packageExpr = '(CONVERT(package_term.value_prefix USING utf8mb4) COLLATE ' . $collation . ')';
                $objectExpr = '(CONVERT(object_term.value_prefix USING utf8mb4) COLLATE ' . $collation . ')';
                $selectColumns = 'f.game_id,l.file_id,' . $packageExpr . ' required_package,'
                    . ($exampleColumn ? 'MIN(NULLIF(' . $objectExpr . ',"")) example_required_object_path,' : '')
                    . 'COUNT(*) dependency_count,'
                    . 'SUM(l.status=1) resolved_count,'
                    . 'SUM(l.status=0) missing_count,'
                    . 'SUM(l.status=2) package_only_count,'
                    . 'SUM(l.status=3) common_count,'
                    . 'CASE '
                    . 'WHEN SUM(l.status=0)>0 THEN "missing" '
                    . 'WHEN SUM(l.status=3)=COUNT(*) THEN "common" '
                    . 'WHEN SUM(l.status=1)=COUNT(*) THEN "resolved" '
                    . 'WHEN SUM(l.status IN (1,2))=COUNT(*) THEN "package_only" '
                    . 'ELSE "mixed" END summary_status,'
                    . 'CASE WHEN COUNT(DISTINCT provider.id)=1 THEN MAX(provider.id) ELSE NULL END provider_file_id ';

                $insert = $this->db->prepare(
                    'INSERT INTO ue_dependency_package_summaries(' . $insertColumns . ') '
                    . 'SELECT ' . $selectColumns
                    . 'FROM ue_dependency_links l '
                    . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
                    . 'JOIN ue_files f ON f.id=l.file_id '
                    . 'JOIN ue_terms package_term ON package_term.id=l.required_package_term_id '
                    . 'JOIN ue_terms object_term ON object_term.id=l.required_object_term_id '
                    . 'LEFT JOIN ue_files provider ON provider.id=l.resolved_file_id '
                    . 'WHERE l.file_id IN (' . $placeholders . ') AND f.scan_status="verified" '
                    . 'AND package_term.value_length>0 '
                    . 'GROUP BY f.game_id,l.file_id,l.required_package_term_id,' . $packageExpr
                );
                $insert->execute($chunk);
                $summaryRows += $insert->rowCount();

                if ($ownsTransaction) {
                    $this->db->commit();
                }
            } catch (Throwable $error) {
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }

        return ['files' => count($fileIds), 'summary_rows' => $summaryRows, 'available' => true];
    }

    private function hasExamplePathColumn(): bool
    {
        $connectionId = spl_object_id($this->db);
        if (array_key_exists($connectionId, self::$examplePathAvailability)) {
            return self::$examplePathAvailability[$connectionId];
        }

        try {
            $statement = $this->db->query(
                'SELECT 1 FROM information_schema.columns '
                . 'WHERE table_schema=DATABASE() '
                . 'AND table_name="ue_dependency_package_summaries" '
                . 'AND column_name="example_required_object_path" LIMIT 1'
            );
            self::$examplePathAvailability[$connectionId] = $statement !== false && $statement->fetchColumn() !== false;
        } catch (Throwable) {
            self::$examplePathAvailability[$connectionId] = false;
        }

        return self::$examplePathAvailability[$connectionId];
    }
}
