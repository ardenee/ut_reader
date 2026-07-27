<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/** Maintains the compact package-provider lookup from normal application writes. */
final class PdoPackageProviderRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function syncFile(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }

        $this->db->prepare(
            'DELETE FROM ue_package_providers WHERE source_kind="primary" AND source_id=?'
        )->execute([$fileId]);

        $this->db->prepare(
            'INSERT INTO ue_package_providers('
            . 'source_kind,source_id,game_id,package_name,file_id,provider_created_at'
            . ') '
            . 'SELECT "primary",f.id,f.game_id,f.package_name,f.id,f.uploaded_at '
            . 'FROM ue_files f '
            . 'WHERE f.id=? AND f.game_id IS NOT NULL AND f.scan_status="verified" '
            . 'ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),package_name=VALUES(package_name),'
            . 'file_id=VALUES(file_id),provider_created_at=VALUES(provider_created_at)'
        )->execute([$fileId]);
    }

    public function syncAlias(int $aliasId): void
    {
        if ($aliasId < 1) {
            return;
        }

        $this->removeAlias($aliasId);

        $this->db->prepare(
            'INSERT INTO ue_package_providers('
            . 'source_kind,source_id,game_id,package_name,file_id,provider_created_at'
            . ') '
            . 'SELECT "alias",a.id,a.game_id,a.package_name,a.file_id,a.created_at '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'WHERE a.id=? AND f.scan_status="verified" '
            . 'ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),package_name=VALUES(package_name),'
            . 'file_id=VALUES(file_id),provider_created_at=VALUES(provider_created_at)'
        )->execute([$aliasId]);
    }

    /** Rebuild all primary and alias provider rows owned by one file. */
    public function reconcileFile(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }

        $this->removeFile($fileId);
        $this->syncFile($fileId);
        $this->db->prepare(
            'INSERT INTO ue_package_providers('
            . 'source_kind,source_id,game_id,package_name,file_id,provider_created_at'
            . ') '
            . 'SELECT "alias",a.id,a.game_id,a.package_name,a.file_id,a.created_at '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'WHERE a.file_id=? AND f.scan_status="verified" '
            . 'ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),package_name=VALUES(package_name),'
            . 'file_id=VALUES(file_id),provider_created_at=VALUES(provider_created_at)'
        )->execute([$fileId]);
    }

    public function removeFile(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }
        $this->db->prepare('DELETE FROM ue_package_providers WHERE file_id=?')->execute([$fileId]);
    }

    public function removeAlias(int $aliasId): void
    {
        if ($aliasId < 1) {
            return;
        }

        $this->db->prepare(
            'DELETE FROM ue_package_providers WHERE source_kind="alias" AND source_id=?'
        )->execute([$aliasId]);
    }
}
