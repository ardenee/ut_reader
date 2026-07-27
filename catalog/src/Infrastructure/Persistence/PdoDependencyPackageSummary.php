<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

/** Maintains compact package-level projections of authoritative dependency rows. */
final class PdoDependencyPackageSummary
{
    /** @var array<int,bool> */
    private static array $availability = [];

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
        if (!$this->available()) {
            return ['file_id' => $fileId, 'summary_rows' => 0, 'available' => false];
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $delete = $this->db->prepare('DELETE FROM ue_dependency_package_summaries WHERE file_id=?');
            $delete->execute([$fileId]);

            $insert = $this->db->prepare(
                'INSERT INTO ue_dependency_package_summaries('
                . 'game_id,file_id,required_package,dependency_count,resolved_count,missing_count,'
                . 'package_only_count,common_count,summary_status,provider_file_id'
                . ') '
                . 'SELECT f.game_id,d.file_id,d.required_package,COUNT(*) dependency_count,'
                . 'SUM(d.status="resolved") resolved_count,SUM(d.status="missing") missing_count,'
                . 'SUM(d.status="package_only") package_only_count,SUM(d.status="common") common_count,'
                . 'CASE '
                . 'WHEN SUM(d.status="missing")>0 THEN "missing" '
                . 'WHEN SUM(d.status="common")=COUNT(*) THEN "common" '
                . 'WHEN SUM(d.status="resolved")=COUNT(*) THEN "resolved" '
                . 'WHEN SUM(d.status IN ("resolved","package_only"))=COUNT(*) THEN "package_only" '
                . 'ELSE "mixed" END summary_status,'
                . 'CASE WHEN COUNT(DISTINCT d.resolved_file_id)=1 THEN MAX(d.resolved_file_id) ELSE NULL END provider_file_id '
                . 'FROM ue_dependencies d JOIN ue_files f ON f.id=d.file_id '
                . 'WHERE d.file_id=? AND f.scan_status="verified" '
                . 'AND d.required_package IS NOT NULL AND d.required_package<>"" '
                . 'GROUP BY f.game_id,d.file_id,d.required_package'
            );
            $insert->execute([$fileId]);
            $summaryRows = $insert->rowCount();

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return ['file_id' => $fileId, 'summary_rows' => $summaryRows, 'available' => true];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
