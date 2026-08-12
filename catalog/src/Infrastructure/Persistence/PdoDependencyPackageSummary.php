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
                $selectColumns = 'f.game_id,d.file_id,d.required_package,'
                    . ($exampleColumn ? 'MIN(NULLIF(d.required_object_path,"")) example_required_object_path,' : '')
                    . 'COUNT(*) dependency_count,'
                    . 'SUM(d.status="resolved") resolved_count,SUM(d.status="missing") missing_count,'
                    . 'SUM(d.status="package_only") package_only_count,SUM(d.status="common") common_count,'
                    . 'CASE '
                    . 'WHEN SUM(d.status="missing")>0 THEN "missing" '
                    . 'WHEN SUM(d.status="common")=COUNT(*) THEN "common" '
                    . 'WHEN SUM(d.status="resolved")=COUNT(*) THEN "resolved" '
                    . 'WHEN SUM(d.status IN ("resolved","package_only"))=COUNT(*) THEN "package_only" '
                    . 'ELSE "mixed" END summary_status,'
                    // resolved_file_id is a compact projection hint and can briefly point at a provider
                    // that has been removed while another maintenance pass is rebuilding owners. Only
                    // publish an FK-backed provider that still exists in ue_files.
                    . 'CASE WHEN COUNT(DISTINCT provider.id)=1 THEN MAX(provider.id) ELSE NULL END provider_file_id ';
                $dependencySource = PdoDependencyReadSource::sql($this->db);

                $insert = $this->db->prepare(
                    'INSERT INTO ue_dependency_package_summaries(' . $insertColumns . ') '
                    . 'SELECT ' . $selectColumns
                    . 'FROM ' . $dependencySource . ' d '
                    . 'JOIN ue_files f ON f.id=d.file_id '
                    . 'LEFT JOIN ue_files provider ON provider.id=d.resolved_file_id '
                    . 'WHERE d.file_id IN (' . $placeholders . ') AND f.scan_status="verified" '
                    . 'AND d.required_package IS NOT NULL AND d.required_package<>"" '
                    . 'GROUP BY f.game_id,d.file_id,d.required_package'
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
