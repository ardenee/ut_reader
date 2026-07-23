<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function base_game_ensure(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS ue_base_game_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            game_id INT UNSIGNED NOT NULL,
            package_guid VARCHAR(80) NOT NULL,
            package_name VARCHAR(255) NULL,
            original_name VARCHAR(255) NULL,
            source_file_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ue_base_game_game_guid (game_id, package_guid),
            KEY idx_ue_base_game_guid (package_guid),
            KEY idx_ue_base_game_source_file (source_file_id),
            CONSTRAINT fk_ue_base_game_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE,
            CONSTRAINT fk_ue_base_game_file FOREIGN KEY (source_file_id) REFERENCES ue_files(id) ON DELETE SET NULL,
            CONSTRAINT fk_ue_base_game_user FOREIGN KEY (created_by) REFERENCES ue_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function base_game_file_is_protected(PDO $db, array $file): bool
{
    $gameId = (int)($file['game_id'] ?? 0);
    $guid = strtoupper(trim((string)($file['package_guid'] ?? '')));
    if ($gameId <= 0 || $guid === '') {
        return false;
    }
    return catalog_one(
        $db,
        'SELECT id FROM ue_base_game_files WHERE game_id=? AND package_guid=? LIMIT 1',
        [$gameId, $guid]
    ) !== null;
}

function base_game_file_by_id(PDO $db, int $fileId): ?array
{
    if ($fileId <= 0) {
        return null;
    }
    return catalog_one(
        $db,
        'SELECT f.*, g.name game_name
         FROM ue_files f
         JOIN ue_games g ON g.id=f.game_id
         WHERE f.id=?',
        [$fileId]
    );
}

function base_game_block_message(?array $file = null): string
{
    $name = trim((string)($file['original_name'] ?? $file['package_name'] ?? 'This file'));
    if ($name === '') {
        $name = 'This file';
    }
    return $name . ' is an official base-game package. UnrealDB keeps its exports indexed so custom maps/mods can resolve dependencies, but the original game file is excluded from public downloads, ordinary federation inventories/pulls, mirrors, and bundle packaging. Federation may transfer it only through an approved missing-dependency exception. If no such dependency exists, install or copy the file from your own game installation.';
}

function base_game_require_transfer_allowed(PDO $db, array $file, bool $dependencyException = false): void
{
    if (base_game_file_is_protected($db, $file) && !$dependencyException) {
        throw new RuntimeException(base_game_block_message($file));
    }
}
