<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use PDOException;
use UnrealDb\Catalog\Application\PackageAlias\PackageAliasRepository;

/** PDO implementation of logical package alias persistence. */
final class PdoPackageAliasRepository implements PackageAliasRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly PDO $db)
    {
    }

    /** Retains the existing self-upgrade behaviour during the migration period. */
    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ue_file_package_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  game_id INT UNSIGNED NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  package_guid VARCHAR(80) NULL,
  md5 CHAR(32) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_file_alias_file_package (file_id, package_name),
  KEY idx_ue_file_alias_game_package (game_id, package_name),
  KEY idx_ue_file_alias_file (file_id),
  KEY idx_ue_file_alias_game_guid_md5 (game_id, package_guid, md5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

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

        return true;
    }
}
