-- UnrealDB legacy package-name repair
-- MySQL 8.0+ / phpMyAdmin
--
-- For UE1, UE2 and UE3 catalog rows, ue_files.package_name is the preserved
-- original filename without its final extension.
--
-- Examples:
--   DM-{aNtiBot}-Defance.ut2 -> DM-{aNtiBot}-Defance
--   [FF$]Soundspack1.uax     -> [FF$]Soundspack1
--
-- UE4 and UE5 are deliberately excluded because their package_name values are
-- mounted long package paths such as /Game/... or /Engine/..., not filename
-- stems.
--
-- The script also rebuilds ue_exports.full_path for each changed file because
-- legacy export paths are stored as:
--     <ue_files.package_name>.<ue_exports.local_path>
--
-- Run the original-name repair first if it has not already been applied.
-- Take a database backup before running this script.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ue_package_name_backup_20260720 (
    file_id BIGINT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NULL,
    engine_key VARCHAR(32) NULL,
    original_name VARCHAR(255) NOT NULL,
    old_package_name VARCHAR(255) NOT NULL,
    new_package_name VARCHAR(255) NOT NULL,
    backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS tmp_ue_package_name_repair;
CREATE TEMPORARY TABLE tmp_ue_package_name_repair (
    file_id BIGINT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NULL,
    engine_key VARCHAR(32) NULL,
    original_name VARCHAR(255) NOT NULL,
    old_package_name VARCHAR(255) NOT NULL,
    candidate VARCHAR(255) NOT NULL,
    PRIMARY KEY (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_ue_package_name_repair
    (file_id, game_id, engine_key, original_name, old_package_name, candidate)
SELECT
    f.id,
    f.game_id,
    UPPER(COALESCE(p.engine_key, f.detected_engine_key, '')) AS engine_key,
    f.original_name,
    f.package_name,
    TRIM(
        CASE
            WHEN LOCATE('.', f.original_name) > 0 THEN
                LEFT(
                    f.original_name,
                    CHAR_LENGTH(f.original_name)
                    - CHAR_LENGTH(SUBSTRING_INDEX(f.original_name, '.', -1))
                    - 1
                )
            ELSE f.original_name
        END
    ) AS candidate
FROM ue_files f
LEFT JOIN ue_games g
    ON g.id = f.game_id
LEFT JOIN ue_game_profiles p
    ON p.id = g.profile_id
WHERE UPPER(COALESCE(p.engine_key, f.detected_engine_key, ''))
      IN ('UE1', 'UE2', 'UE3');

-- Preview every package-name change before the transaction runs.
SELECT
    r.file_id,
    g.name AS game,
    r.engine_key,
    r.old_package_name,
    r.candidate AS new_package_name,
    r.original_name
FROM tmp_ue_package_name_repair r
LEFT JOIN ue_games g
    ON g.id = r.game_id
WHERE r.candidate <> ''
  AND BINARY r.old_package_name <> BINARY r.candidate
ORDER BY g.name, r.candidate, r.file_id;

START TRANSACTION;

INSERT IGNORE INTO ue_package_name_backup_20260720
    (file_id, game_id, engine_key, original_name, old_package_name, new_package_name)
SELECT
    file_id,
    game_id,
    engine_key,
    original_name,
    old_package_name,
    candidate
FROM tmp_ue_package_name_repair
WHERE candidate <> ''
  AND BINARY old_package_name <> BINARY candidate;

UPDATE ue_files f
JOIN tmp_ue_package_name_repair r
    ON r.file_id = f.id
SET f.package_name = r.candidate
WHERE r.candidate <> ''
  AND BINARY f.package_name <> BINARY r.candidate;

SET @updated_package_rows = ROW_COUNT();

-- Keep legacy export identities consistent with the corrected package name.
UPDATE ue_exports e
JOIN tmp_ue_package_name_repair r
    ON r.file_id = e.file_id
SET e.full_path =
    CASE
        WHEN COALESCE(e.local_path, '') = '' THEN r.candidate
        ELSE CONCAT(r.candidate, '.', e.local_path)
    END
WHERE r.candidate <> ''
  AND BINARY r.old_package_name <> BINARY r.candidate;

SET @updated_export_rows = ROW_COUNT();

-- ue_base_game_files stores a metadata snapshot of the linked source row.
-- Update it only when that optional table exists.
SET @has_base_game_table = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ue_base_game_files'
);

SET @base_game_sql = IF(
    @has_base_game_table > 0,
    'UPDATE ue_base_game_files b
     JOIN ue_files f ON f.id = b.source_file_id
     JOIN tmp_ue_package_name_repair r ON r.file_id = f.id
     SET b.package_name = f.package_name,
         b.original_name = f.original_name
     WHERE r.candidate <> ''''
       AND BINARY r.old_package_name <> BINARY r.candidate',
    'SELECT 0'
);

PREPARE base_game_stmt FROM @base_game_sql;
EXECUTE base_game_stmt;
SET @updated_base_game_rows = ROW_COUNT();
DEALLOCATE PREPARE base_game_stmt;

COMMIT;

SELECT
    @updated_package_rows AS updated_ue_files_rows,
    @updated_export_rows AS updated_ue_exports_rows,
    @updated_base_game_rows AS updated_base_game_rows;

-- This should return no rows after a successful repair.
SELECT
    f.id AS file_id,
    g.name AS game,
    f.package_name,
    r.candidate AS expected_package_name,
    f.original_name
FROM ue_files f
JOIN tmp_ue_package_name_repair r
    ON r.file_id = f.id
LEFT JOIN ue_games g
    ON g.id = f.game_id
WHERE r.candidate <> ''
  AND BINARY f.package_name <> BINARY r.candidate
ORDER BY f.id;

-- This should also return no rows. It verifies that export paths use the
-- corrected legacy package-name prefix.
SELECT
    e.id AS export_id,
    e.file_id,
    f.package_name,
    e.local_path,
    e.full_path,
    CASE
        WHEN COALESCE(e.local_path, '') = '' THEN f.package_name
        ELSE CONCAT(f.package_name, '.', e.local_path)
    END AS expected_full_path
FROM ue_exports e
JOIN ue_files f
    ON f.id = e.file_id
JOIN tmp_ue_package_name_repair r
    ON r.file_id = e.file_id
WHERE r.candidate <> ''
  AND BINARY r.old_package_name <> BINARY r.candidate
  AND BINARY e.full_path <> BINARY (
      CASE
          WHEN COALESCE(e.local_path, '') = '' THEN f.package_name
          ELSE CONCAT(f.package_name, '.', e.local_path)
      END
  )
ORDER BY e.file_id, e.export_index;

-- Rollback helper. Run separately only when required:
--
-- UPDATE ue_files f
-- JOIN ue_package_name_backup_20260720 b ON b.file_id = f.id
-- SET f.package_name = b.old_package_name;
--
-- UPDATE ue_exports e
-- JOIN ue_package_name_backup_20260720 b ON b.file_id = e.file_id
-- SET e.full_path =
--     CASE
--         WHEN COALESCE(e.local_path, '') = '' THEN b.old_package_name
--         ELSE CONCAT(b.old_package_name, '.', e.local_path)
--     END;
