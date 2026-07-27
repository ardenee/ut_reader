<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use PDOException;
use UnrealDb\Catalog\Application\PackageAlias\PackageAliasRepository;
use UnrealDb\Catalog\Application\Search\CatalogSearchIndexQueue;

/** PDO implementation of logical package alias persistence. */
final class PdoPackageAliasRepository implements PackageAliasRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $db)
    {
    }

    /** The package-alias table is owned by the migration system. */
    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        // Normal imports, dependency rebuilds and searches must never execute
        // schema DDL. Migration 202607180002 creates and verifies this table.
        $this->schemaReady = true;
    }

    public function exists(int $fileId, int $gameId, string $packageName): bool
    {
        $this->ensureSchema();
        $statement = $this->db->prepare(
            'SELECT id FROM ue_file_package_aliases '
            . 'WHERE file_id=? AND game_id=? AND package_name=? LIMIT 1'
        );
        $statement->execute([$fileId, $gameId, $packageName]);

        return $statement->fetchColumn() !== false;
    }

    public function add(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        string $packageGuid,
        string $md5,
        int $fileSize
    ): bool {
        $this->ensureSchema();
        if ($this->exists($fileId, $gameId, $packageName)) {
            return false;
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_file_package_aliases('
            . 'file_id,game_id,package_name,original_name,package_guid,md5,file_size'
            . ') VALUES(?,?,?,?,?,?,?)'
        );

        try {
            $statement->execute([
                $fileId,
                $gameId,
                $packageName,
                $originalName,
                $packageGuid !== '' ? $packageGuid : null,
                $md5,
                $fileSize,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }

        $aliasId = (int)$this->db->lastInsertId();
        if ($aliasId > 0) {
            try {
                (new PdoPackageProviderRepository($this->db))->syncAlias($aliasId);
            } catch (PDOException $exception) {
                // The alias remains authoritative. Dependency resolution has an
                // exact-table fallback and maintenance can reconcile the cache.
                error_log('[UnrealDB package provider] alias_id=' . $aliasId . ' sync failed: ' . $exception->getMessage());
            }
        }

        CatalogSearchIndexQueue::enqueueFile($this->db, $fileId);
        return true;
    }
}
