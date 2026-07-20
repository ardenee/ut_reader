-- UnrealDB original filename repair from ue_files.source_relative_path
-- MySQL 8.0+ / phpMyAdmin
--
-- Restores ue_files.original_name from the final filename component of
-- source_relative_path. Filename punctuation and casing are preserved. Only
-- duplicate-copy indicators and redirect archive suffixes are removed.
--
-- Examples:
--   .ut2004_files/Maps/DM-{UEM}-OldGlory.ut2
--       -> DM-{UEM}-OldGlory.ut2
--   Maps/DM-{UEM}-OldGlory (2).ut2
--       -> DM-{UEM}-OldGlory.ut2
--   Sounds/[FF$]Soundspack1.uax (2).uz2
--       -> [FF$]Soundspack1.uax
--
-- ue_files stores the decompressed package, so a final .uz/.uz2/.uz3 suffix is
-- removed. A permanent backup table is created before any row is updated.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ue_original_name_backup_20260720 (
    file_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    source_relative_path VARCHAR(1024) NULL,
    backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS tmp_ue_original_name_repair;
CREATE TEMPORARY TABLE tmp_ue_original_name_repair (
    file_id BIGINT UNSIGNED NOT NULL,
    old_original_name VARCHAR(255) NOT NULL,
    source_relative_path VARCHAR(1024) NOT NULL,
    candidate VARCHAR(1024) NOT NULL,
    PRIMARY KEY (file_id)
) ENGINE=InnoDB;

INSERT INTO tmp_ue_original_name_repair
    (file_id, old_original_name, source_relative_path, candidate)
SELECT
    id,
    original_name,
    source_relative_path,
    TRIM(SUBSTRING_INDEX(REPLACE(source_relative_path, '\\', '/'), '/', -1))
FROM ue_files
WHERE source_relative_path IS NOT NULL
  AND TRIM(source_relative_path) <> '';

-- ue_files represents the decompressed package rather than its redirect archive.
UPDATE tmp_ue_original_name_repair
SET candidate = CASE
    WHEN LOWER(RIGHT(candidate, 4)) IN ('.uz2', '.uz3')
        THEN LEFT(candidate, CHAR_LENGTH(candidate) - 4)
    WHEN LOWER(RIGHT(candidate, 3)) = '.uz'
        THEN LEFT(candidate, CHAR_LENGTH(candidate) - 3)
    ELSE candidate
END;

-- Name.ext (2) -> Name.ext. This also handles a duplicate marker exposed after
-- removing .uz/.uz2/.uz3, for example Name.uax (2).uz2.
UPDATE tmp_ue_original_name_repair
SET candidate = REGEXP_REPLACE(candidate, '[[:space:]]+[(][0-9]+[)]$', '');

-- Name (2).ext -> Name.ext. Work only on the stem so all other punctuation is
-- preserved exactly as it appears in source_relative_path.
UPDATE tmp_ue_original_name_repair
SET candidate = CONCAT(
    REGEXP_REPLACE(
        LEFT(candidate, CHAR_LENGTH(candidate) - CHAR_LENGTH(SUBSTRING_INDEX(candidate, '.', -1)) - 1),
        '[[:space:]]+[(][0-9]+[)]$',
        ''
    ),
    '.',
    SUBSTRING_INDEX(candidate, '.', -1)
)
WHERE candidate LIKE '%.%';

-- Also remove common filesystem copy indicators from the end of the stem.
UPDATE tmp_ue_original_name_repair
SET candidate = CONCAT(
    REGEXP_REPLACE(
        REGEXP_REPLACE(
            LEFT(candidate, CHAR_LENGTH(candidate) - CHAR_LENGTH(SUBSTRING_INDEX(candidate, '.', -1)) - 1),
            '[[:space:]]+-[[:space:]]+copy([[:space:]]*[(][0-9]+[)])?$',
            '', 1, 0, 'i'
        ),
        '[[:space:]]+copy([[:space:]]*[(][0-9]+[)])?$',
        '', 1, 0, 'i'
    ),
    '.',
    SUBSTRING_INDEX(candidate, '.', -1)
)
WHERE candidate LIKE '%.%';

UPDATE tmp_ue_original_name_repair
SET candidate = TRIM(TRAILING '.' FROM TRIM(candidate));

-- Preview the exact changes. phpMyAdmin will display this result set before the
-- update result. Review it, especially any unusual source paths.
SELECT
    r.file_id,
    f.game_id,
    r.old_original_name,
    r.candidate AS new_original_name,
    r.source_relative_path
FROM tmp_ue_original_name_repair r
JOIN ue_files f ON f.id = r.file_id
WHERE r.candidate <> ''
  AND CHAR_LENGTH(r.candidate) <= 255
  AND BINARY r.old_original_name <> BINARY r.candidate
ORDER BY f.game_id, r.candidate, r.file_id;

START TRANSACTION;

INSERT IGNORE INTO ue_original_name_backup_20260720
    (file_id, original_name, source_relative_path)
SELECT
    file_id,
    old_original_name,
    source_relative_path
FROM tmp_ue_original_name_repair
WHERE candidate <> ''
  AND CHAR_LENGTH(candidate) <= 255
  AND BINARY old_original_name <> BINARY candidate;

UPDATE ue_files f
JOIN tmp_ue_original_name_repair r ON r.file_id = f.id
SET f.original_name = r.candidate
WHERE r.candidate <> ''
  AND CHAR_LENGTH(r.candidate) <= 255
  AND BINARY f.original_name <> BINARY r.candidate;

SELECT ROW_COUNT() AS updated_ue_files_rows;

COMMIT;

-- Source basenames too long for the current VARCHAR(255) column are not changed.
SELECT
    file_id,
    old_original_name,
    candidate,
    source_relative_path
FROM tmp_ue_original_name_repair
WHERE candidate <> ''
  AND CHAR_LENGTH(candidate) > 255
ORDER BY file_id;

-- This should return no rows after a successful repair.
SELECT
    f.id AS file_id,
    f.game_id,
    f.original_name,
    r.candidate AS expected_original_name,
    f.source_relative_path
FROM ue_files f
JOIN tmp_ue_original_name_repair r ON r.file_id = f.id
WHERE r.candidate <> ''
  AND CHAR_LENGTH(r.candidate) <= 255
  AND BINARY f.original_name <> BINARY r.candidate
ORDER BY f.id;

-- Rollback helper. Run separately only when required:
-- UPDATE ue_files f
-- JOIN ue_original_name_backup_20260720 b ON b.file_id = f.id
-- SET f.original_name = b.original_name;
