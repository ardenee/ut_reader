<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists source locations for files resolved or imported during a local source scan.
 * Why: ue_file_locations upserts and canonical source-relative-path writes are one persistence responsibility, not scan-loop branching.
 * Role: Infrastructure source-scan collaborator over the shared source-path store.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use PDOStatement;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourcePathStore;

final class CatalogSourceLocationRecorder
{
    private readonly PDOStatement $locationUpsert;
    private readonly PdoCatalogSourcePathStore $sourcePaths;

    public function __construct(PDO $db)
    {
        $this->locationUpsert = $db->prepare(
            'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) '
            . 'VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE '
            . 'exists_in_source=VALUES(exists_in_source),last_seen_at=NOW()'
        );
        $this->sourcePaths = new PdoCatalogSourcePathStore($db);
    }

    public function recordMatched(
        int $fileId,
        int $sourceId,
        string $relativePath,
        string $normalizedSourceRelativePath
    ): void {
        $this->recordLocation($fileId, $sourceId, $relativePath);
        $this->sourcePaths->recordIfMissing($fileId, $normalizedSourceRelativePath);
    }

    /**
     * Preserve the historical import-result accounting contract.
     *
     * @param array<int,mixed> $result
     * @return array{imported:int,duplicates:int,locations:int}
     */
    public function recordImportResult(int $sourceId, string $relativePath, array $result): array
    {
        if (($result[0] ?? '') === 'duplicate') {
            $locations = 0;
            if (!empty($result[1])) {
                $this->recordLocation((int)$result[1], $sourceId, $relativePath);
                $locations = 1;
            }
            return ['imported' => 0, 'duplicates' => 1, 'locations' => $locations];
        }

        $this->recordLocation((int)$result[1], $sourceId, $relativePath);
        return ['imported' => 1, 'duplicates' => 0, 'locations' => 1];
    }

    private function recordLocation(int $fileId, int $sourceId, string $relativePath): void
    {
        $this->locationUpsert->execute([$fileId, $sourceId, $relativePath, 1]);
    }
}
