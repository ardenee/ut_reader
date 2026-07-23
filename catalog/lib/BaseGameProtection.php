<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function base_game_ensure(PDO $db): void
{
    /** @var array<int,bool> $ensured */
    static $ensured = [];
    $connectionId = spl_object_id($db);
    if (isset($ensured[$connectionId])) {
        return;
    }

    // MySQL implicitly commits active transactions around DDL. Never execute the
    // compatibility CREATE TABLE path from dependency/request transactions.
    if ($db->inTransaction()) {
        $exists = catalog_one(
            $db,
            'SELECT 1 AS present FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="ue_base_game_files" LIMIT 1'
        );
        if (!$exists) {
            throw new RuntimeException('Base-game protection table is missing. Run the database migrations before processing transfers.');
        }
        $ensured[$connectionId] = true;
        return;
    }

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ue_base_game_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  package_guid VARCHAR(80) NOT NULL,
  package_name VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  source_file_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_base_game_files_game_guid (game_id, package_guid),
  KEY idx_ue_base_game_files_game (game_id),
  KEY idx_ue_base_game_files_guid (package_guid),
  KEY idx_ue_base_game_files_source_file (source_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $ensured[$connectionId] = true;
}

function base_game_normalize_guid(string $guid): string
{
    $guid = strtoupper(trim($guid));
    $guid = preg_replace('/[^A-F0-9-]+/', '', $guid) ?? '';
    return $guid;
}

function base_game_guid_is_usable(string $guid): bool
{
    $guid = base_game_normalize_guid($guid);
    return $guid !== '' && $guid !== '00000000-00000000-00000000-00000000';
}

function base_game_lookup(PDO $db, int $gameId, string $packageGuid): ?array
{
    base_game_ensure($db);
    $guid = base_game_normalize_guid($packageGuid);
    if (!base_game_guid_is_usable($guid)) {
        return null;
    }
    return catalog_one($db, 'SELECT b.*, g.name AS game_name FROM ue_base_game_files b JOIN ue_games g ON g.id=b.game_id WHERE b.game_id=? AND b.package_guid=? LIMIT 1', [$gameId, $guid]);
}

function base_game_file_is_protected(PDO $db, array $file): bool
{
    return base_game_lookup($db, (int)($file['game_id'] ?? 0), (string)($file['package_guid'] ?? '')) !== null;
}

function base_game_file_protection(PDO $db, int $fileId): ?array
{
    base_game_ensure($db);
    $file = catalog_one($db, 'SELECT f.*, g.name AS game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$fileId]);
    if (!$file) {
        return null;
    }
    $base = base_game_lookup($db, (int)$file['game_id'], (string)$file['package_guid']);
    if (!$base) {
        return null;
    }
    return ['file' => $file, 'base' => $base];
}

function base_game_block_message(?array $file = null): string
{
    $name = $file ? catalog_clean_unreal_filename((string)($file['original_name'] ?? $file['package_name'] ?? 'this package')) : 'this package';
    return $name . ' is an official base-game package. UnrealDB keeps its exports indexed so custom maps/mods can resolve dependencies, but the original game files are not available for download, federation transfer, or bundle packaging. If you own the original game, install or copy the file from your game installation.';
}

function base_game_block_html(?array $file = null): string
{
    return '<div class="card"><h1>Download blocked</h1><p>' . catalog_h(base_game_block_message($file)) . '</p></div>';
}

function base_game_seed_from_current_files(PDO $db, int $gameId, ?int $userId = null): array
{
    base_game_ensure($db);
    $game = catalog_one($db, 'SELECT id, name FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    $rows = catalog_all(
        $db,
        'SELECT id, game_id, package_guid, package_name, original_name
         FROM ue_files
         WHERE game_id=? AND scan_status="verified" AND package_guid IS NOT NULL AND package_guid<>"" AND package_guid<>"00000000-00000000-00000000-00000000"
         ORDER BY package_name, original_name, id',
        [$gameId]
    );

    $inserted = 0;
    $updated = 0;
    $stmt = $db->prepare('INSERT INTO ue_base_game_files(game_id, package_guid, package_name, original_name, source_file_id, notes) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE package_name=VALUES(package_name), original_name=VALUES(original_name), source_file_id=VALUES(source_file_id), updated_at=CURRENT_TIMESTAMP');
    foreach ($rows as $row) {
        $guid = base_game_normalize_guid((string)$row['package_guid']);
        if (!base_game_guid_is_usable($guid)) {
            continue;
        }
        $stmt->execute([
            $gameId,
            $guid,
            (string)$row['package_name'],
            catalog_clean_unreal_filename((string)$row['original_name']),
            (int)$row['id'],
            'Seeded from verified catalog files for ' . (string)$game['name'] . '.',
        ]);
        $stmt->rowCount() === 1 ? $inserted++ : $updated++;
    }

    return ['scanned' => count($rows), 'inserted' => $inserted, 'updated' => $updated];
}
