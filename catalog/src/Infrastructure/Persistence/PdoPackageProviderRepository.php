<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ProviderKeyPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ProviderKeyBuilder;

/**
 * Compatibility facade for package-provider publication.
 *
 * Provider identity is authoritative in ue_uedb5_provider_keys. Historical
 * callers retain this API while all writes target V5 only.
 */
final class PdoPackageProviderRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function syncFile(int $fileId): void
    {
        $this->publishFileIfRegistered($fileId);
    }

    public function syncAlias(int $aliasId): void
    {
        if ($aliasId < 1) {
            return;
        }
        $statement = $this->db->prepare(
            'SELECT file_id FROM ue_file_package_aliases WHERE id=? LIMIT 1'
        );
        $statement->execute([$aliasId]);
        $fileId = (int)($statement->fetchColumn() ?: 0);
        if ($fileId > 0) {
            $this->publishFileIfRegistered($fileId);
        }
    }

    public function reconcileFile(int $fileId): void
    {
        $this->publishFileIfRegistered($fileId);
    }

    /** @return array{primary:int,aliases:int,total:int} */
    public function reconcileGame(int $gameId): array
    {
        if ($gameId < 1) {
            return ['primary'=>0,'aliases'=>0,'total'=>0];
        }
        $statement = $this->db->prepare(
            'SELECT f.id FROM ue_files f '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" ORDER BY f.id'
        );
        $statement->execute([$gameId]);
        $publisher = new PdoUedb5ProviderKeyPublisher($this->db);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileId) {
            $publisher->publish((int)$fileId);
        }

        $counts = $this->db->prepare(
            'SELECT '
            . 'COALESCE(SUM(source_kind=?),0) primary_count,'
            . 'COALESCE(SUM(source_kind=?),0) alias_count '
            . 'FROM ue_uedb5_provider_keys WHERE game_id=?'
        );
        $counts->execute([
            Uedb5ProviderKeyBuilder::SOURCE_PRIMARY,
            Uedb5ProviderKeyBuilder::SOURCE_ALIAS,
            $gameId,
        ]);
        $row = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
        $primary = (int)($row['primary_count'] ?? 0);
        $aliases = (int)($row['alias_count'] ?? 0);
        return ['primary'=>$primary,'aliases'=>$aliases,'total'=>$primary + $aliases];
    }

    public function removeFile(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }
        $this->db->prepare('DELETE FROM ue_uedb5_provider_keys WHERE file_id=?')->execute([$fileId]);
    }

    public function removeAlias(int $aliasId): void
    {
        if ($aliasId < 1) {
            return;
        }
        $this->db->prepare(
            'DELETE FROM ue_uedb5_provider_keys WHERE source_kind=? AND source_id=?'
        )->execute([Uedb5ProviderKeyBuilder::SOURCE_ALIAS, $aliasId]);
    }

    private function publishFileIfRegistered(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }
        $statement = $this->db->prepare(
            'SELECT 1 FROM ue_uedb5_files v '
            . 'JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
            . 'WHERE v.file_id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId]);
        if ($statement->fetchColumn() === false) {
            $this->removeFile($fileId);
            return;
        }
        (new PdoUedb5ProviderKeyPublisher($this->db))->publish($fileId);
    }
}
