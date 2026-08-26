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
        // rule. Retiring a GUID duplicate intentionally leaves that historical
        // row in ue_files with scan_status=duplicate, so the retired row still
        // owns its exact game+MD5 key. Future archives containing those same bytes
        // must therefore recognise both active and retired physical identities;
        // otherwise the optimistic SELECT misses the row and the INSERT can only
        // fail on uq_ue_files_game_md5 repeatedly.
        $sql = 'SELECT id, original_name, package_name, package_guid, file_size, md5, scan_status '
            . 'FROM ue_files WHERE game_id=? AND md5=? AND scan_status IN ("verified","duplicate")';
        $args = [$gameId, $inspection->md5];
        if ($maintenanceReplaceFileId > 0) {
            $sql .= ' AND id<>?';
            $args[] = $maintenanceReplaceFileId;
        }
        $sql .= ' ORDER BY (scan_status="verified") DESC,id ASC LIMIT 1';
        $physical = \catalog_one($this->db, $sql, $args) ?: null;
        if ($physical === null || (string)($physical['scan_status'] ?? '') === 'verified') {
            return $physical;
        }

        // A retired exact-MD5 row represents historical source evidence, not an
        // identity that should be reactivated. Duplicate retirement is based on a
        // valid same-game package GUID, so follow that GUID back to the current
        // verified canonical row and attach future source locations there. If an
        // old database has lost its canonical row, returning the retired physical
        // identity is still safer than retrying an INSERT that the unique key can
        // never permit.
        $packageGuid = trim((string)($physical['package_guid'] ?? ''));
        if ($packageGuid !== '') {
            $canonical = \catalog_one(
                $this->db,
                'SELECT id, original_name, package_name, package_guid, file_size, md5, scan_status '
                . 'FROM ue_files WHERE game_id=? AND package_guid=? AND scan_status="verified" '
                . ($maintenanceReplaceFileId > 0 ? 'AND id<>? ' : '')
                . 'ORDER BY id ASC LIMIT 1',
                $maintenanceReplaceFileId > 0
                    ? [$gameId, $packageGuid, $maintenanceReplaceFileId]
                    : [$gameId, $packageGuid]
            );
            if (is_array($canonical)) {
                $canonical['matched_retired_duplicate_id'] = (int)$physical['id'];
                $canonical['matched_retired_duplicate_md5'] = (string)($physical['md5'] ?? '');
                return $canonical;
            }
        }

        $physical['matched_retired_duplicate_id'] = (int)$physical['id'];
        $physical['matched_retired_duplicate_md5'] = (string)($physical['md5'] ?? '');
        return $physical;
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
