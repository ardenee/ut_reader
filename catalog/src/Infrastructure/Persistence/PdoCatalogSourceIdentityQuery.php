<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads verified catalogue file identities for local source scans.
 * Why: ID, MD5 and GUID matching SQL should not live inside the source-scan orchestration loop.
 * Role: Read-only PDO persistence collaborator for the established source matching rules.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoCatalogSourceIdentityQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findVerifiedById(int $fileId): ?array
    {
        if ($fileId < 1) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT id,md5,sha1,package_guid FROM ue_files '
            . 'WHERE id=? AND scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findVerifiedByMd5(int $gameId, string $md5): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,md5,sha1,package_guid FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1'
        );
        $statement->execute([$gameId, $md5]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function findVerifiedByGuid(int $gameId, string $guid): array
    {
        if ($guid === '') {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT id,md5,sha1,package_guid FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id'
        );
        $statement->execute([$gameId, $guid]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values($rows) : [];
    }
}
