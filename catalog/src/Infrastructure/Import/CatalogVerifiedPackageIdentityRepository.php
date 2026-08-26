<?php
/**
 * PDO-backed identity/duplicate boundary for verified package import.
 *
 * Maintenance target validation, canonical duplicate lookup, source-path
 * association and package-alias persistence are one persistence responsibility.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;
use UnrealDb\Catalog\Application\Import\Contract\VerifiedPackageIdentityPort;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourcePathStore;

final class CatalogVerifiedPackageIdentityRepository implements VerifiedPackageIdentityPort
{
    private readonly PdoCatalogSourcePathStore $sourcePaths;

    public function __construct(private readonly PDO $db)
    {
        require_once __DIR__ . '/../../../lib/CatalogPackageAliases.php';
        $this->sourcePaths = new PdoCatalogSourcePathStore($db);
    }

    public function ensureSourcePathSchema(): void
    {
        $this->sourcePaths->ensureSchema();
    }

    public function ensureAliasSchema(): void
    {
        \catalog_package_aliases_ensure($this->db);
    }

    public function validateMaintenanceTarget(int $gameId, int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }
        $target = \catalog_one(
            $this->db,
            'SELECT id,game_id,scan_status FROM ue_files WHERE id=?',
            [$fileId]
        );
        if (!$target
            || (int)$target['game_id'] !== $gameId
            || (string)$target['scan_status'] !== 'verified') {
            throw new RuntimeException(
                'Maintenance refresh target #' . $fileId
                . ' is no longer a verified package in the selected game.'
            );
        }
    }

    /** @return array<string,mixed>|null */
    public function findVerifiedDuplicate(
        int $gameId,
        CatalogVerifiedPackageInspection $inspection,
        int $maintenanceReplaceFileId = 0
    ): ?array {
        // ue_files.uq_ue_files_game_md5 is the authoritative physical identity
        // rule. An identical MD5 within one game is the same physical package
        // regardless of whether an older row has a blank/different parsed GUID.
        // Keeping this lookup aligned with the unique constraint also makes the
        // optimistic SELECT -> INSERT race recoverable as a normal duplicate.
        $sql = 'SELECT id, original_name, package_name, package_guid, file_size, md5 '
            . 'FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=?';
        $args = [$gameId, $inspection->md5];
        if ($maintenanceReplaceFileId > 0) {
            $sql .= ' AND id<>?';
            $args[] = $maintenanceReplaceFileId;
        }
        $sql .= ' LIMIT 1';
        return \catalog_one($this->db, $sql, $args) ?: null;
    }

    public function recordSourcePathIfMissing(int $fileId, string $sourceRelativePath): void
    {
        $this->sourcePaths->recordIfMissing($fileId, $sourceRelativePath);
    }

    public function addAlias(
        int $fileId,
        int $gameId,
        CatalogVerifiedPackageInspection $inspection
    ): bool {
        return \catalog_package_alias_add(
            $this->db,
            $fileId,
            $gameId,
            $inspection->packageName,
            $inspection->originalName,
            $inspection->packageGuid,
            $inspection->md5,
            $inspection->fileSize
        );
    }
}
