<?php
declare(strict_types=1);

/**
 * Physical packages can legitimately appear under more than one logical package
 * filename in retail installs. Keep one verified file row for the GUID/hash
 * identity and record every package-root alias that imports may reference.
 */
function catalog_package_aliases_ensure(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->exec(<<<'SQL'
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

    $done = true;
}

function catalog_package_alias_exists(PDO $db, int $fileId, int $gameId, string $packageName): bool
{
    catalog_package_aliases_ensure($db);

    return (bool)catalog_one(
        $db,
        'SELECT id FROM ue_file_package_aliases WHERE file_id=? AND game_id=? AND package_name=? LIMIT 1',
        [$fileId, $gameId, $packageName]
    );
}

function catalog_package_alias_add(PDO $db, int $fileId, int $gameId, string $packageName, string $originalName, string $packageGuid, string $md5, int $fileSize): bool
{
    catalog_package_aliases_ensure($db);

    if (catalog_package_alias_exists($db, $fileId, $gameId, $packageName)) {
        return false;
    }

    $stmt = $db->prepare('INSERT INTO ue_file_package_aliases(file_id,game_id,package_name,original_name,package_guid,md5,file_size) VALUES(?,?,?,?,?,?,?)');
    try {
        $stmt->execute([$fileId, $gameId, $packageName, $originalName, $packageGuid !== '' ? $packageGuid : null, $md5, $fileSize]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            return false;
        }
        throw $exception;
    }

    return true;
}
