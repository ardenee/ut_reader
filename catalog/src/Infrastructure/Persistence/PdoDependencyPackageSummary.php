<?php
/**
 * Compatibility facade for package-level dependency summaries.
 *
 * UEDB5 Pass 2 publishes ue_uedb5_dependency_packages atomically with the
 * authoritative dependency payload. Historical callers may still ask to
 * "rebuild" summaries; those calls now verify/count the already-published V5
 * projection and never write a legacy summary table.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

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
            $statement = $this->db->query('SELECT 1 FROM ue_uedb5_dependency_packages LIMIT 0');
            return self::$availability[$connectionId] = $statement !== false;
        } catch (Throwable) {
            return self::$availability[$connectionId] = false;
        }
    }

    /** @return array{file_id:int,summary_rows:int,available:bool} */
    public function rebuildFile(int $fileId): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Dependency package summary requires a positive file ID.');
        }
        if (!$this->available()) {
            return ['file_id'=>$fileId,'summary_rows'=>0,'available'=>false];
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_uedb5_dependency_packages WHERE file_id=?'
        );
        $statement->execute([$fileId]);
        return [
            'file_id'=>$fileId,
            'summary_rows'=>(int)$statement->fetchColumn(),
            'available'=>true,
        ];
    }

    /**
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
            return ['files'=>0,'summary_rows'=>0,'available'=>$this->available()];
        }
        if (!$this->available()) {
            return ['files'=>count($fileIds),'summary_rows'=>0,'available'=>false];
        }

        $rows = 0;
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $statement = $this->db->prepare(
                'SELECT COUNT(*) FROM ue_uedb5_dependency_packages WHERE file_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute($chunk);
            $rows += (int)$statement->fetchColumn();
        }
        return ['files'=>count($fileIds),'summary_rows'=>$rows,'available'=>true];
    }
}
