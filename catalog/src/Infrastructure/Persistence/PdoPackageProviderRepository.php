<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `PdoPackageProviderRepository` for PDO package provider repository.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

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

    /** @return array{primary:int,aliases:int,total:int} */
    public function reconcileGame(int $gameId): array
    {
        if ($gameId < 1) {
            return ['primary' => 0, 'aliases' => 0, 'total' => 0];
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $delete = $this->db->prepare('DELETE FROM ue_package_providers WHERE game_id=?');
            $delete->execute([$gameId]);

            $primary = $this->db->prepare(
                'INSERT INTO ue_package_providers('
                . 'source_kind,source_id,game_id,package_name,file_id,provider_created_at'
                . ') '
                . 'SELECT "primary",f.id,f.game_id,f.package_name,f.id,f.uploaded_at '
                . 'FROM ue_files f '
                . 'WHERE f.game_id=? AND f.scan_status="verified"'
            );
            $primary->execute([$gameId]);
            $primaryCount = $primary->rowCount();

            $aliases = $this->db->prepare(
                'INSERT INTO ue_package_providers('
                . 'source_kind,source_id,game_id,package_name,file_id,provider_created_at'
                . ') '
                . 'SELECT "alias",a.id,a.game_id,a.package_name,a.file_id,a.created_at '
                . 'FROM ue_file_package_aliases a '
                . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                . 'WHERE a.game_id=? AND f.scan_status="verified"'
            );
            $aliases->execute([$gameId]);
            $aliasCount = $aliases->rowCount();

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'primary' => max(0, $primaryCount),
                'aliases' => max(0, $aliasCount),
                'total' => max(0, $primaryCount) + max(0, $aliasCount),
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
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
