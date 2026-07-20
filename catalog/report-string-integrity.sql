-- UnrealDB string-integrity report
-- MySQL 8.0+ / phpMyAdmin
--
-- This report scans the primary serialized name table only. It deliberately
-- avoids treating valid UTF-8/Unicode characters as corruption and does not
-- modify catalog data.
--
-- Classification:
--   embedded_nul_reimport
--       Definite old-reader contamination. Reimport the file with the corrected
--       reader so names, imports, exports and dependencies are rebuilt together.
--   replacement_character_reimport
--       U+FFFD replacement bytes were stored. Reimport and inspect encoding.
--   trailing_control_review
--       A serialized name ends in a C0/DEL control byte but has no NUL. Preserve
--       it unless source-reader evidence proves the byte is padding.
--   control_only_preserve / mixed_control_preserve
--       Usually deliberate obfuscation or synthetic FNames. Preserve exactly.
--
-- Valid names such as Säule, Grün, tür and ©2003 are not selected.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS ue_string_integrity_report;

CREATE TABLE ue_string_integrity_report
ENGINE=InnoDB
DEFAULT CHARACTER SET utf8mb4
COLLATE=utf8mb4_unicode_ci
AS
SELECT
    n.id AS name_row_id,
    n.file_id,
    g.name AS game,
    f.package_name,
    f.original_name,
    n.name_index,
    n.name_text,
    HEX(n.name_text) AS name_hex,
    CASE
        WHEN LOCATE(
            UNHEX('00'),
            CONVERT(n.name_text USING binary)
        ) > 0
            THEN 'embedded_nul_reimport'

        WHEN LOCATE(
            UNHEX('EFBFBD'),
            CONVERT(n.name_text USING binary)
        ) > 0
            THEN 'replacement_character_reimport'

        WHEN REGEXP_LIKE(
            HEX(n.name_text),
            '^(?:(?:0[0-9A-F]|1[0-9A-F]|7F))+$'
        )
            THEN 'control_only_preserve'

        WHEN REGEXP_LIKE(
            HEX(n.name_text),
            '(?:0[0-9A-F]|1[0-9A-F]|7F)$'
        )
        AND REGEXP_LIKE(
            HEX(n.name_text),
            '^(?:[0-9A-F]{2})*(?:2[0-9A-F]|[3-6][0-9A-F]|7[0-9A-E])'
        )
            THEN 'trailing_control_review'

        ELSE 'mixed_control_preserve'
    END AS classification,
    CASE
        WHEN LOCATE(
            UNHEX('00'),
            CONVERT(n.name_text USING binary)
        ) > 0
            THEN 'Reimport with corrected reader; do not SQL-trim derived rows'

        WHEN LOCATE(
            UNHEX('EFBFBD'),
            CONVERT(n.name_text USING binary)
        ) > 0
            THEN 'Reimport and inspect source encoding'

        WHEN REGEXP_LIKE(
            HEX(n.name_text),
            '(?:0[0-9A-F]|1[0-9A-F]|7F)$'
        )
        AND REGEXP_LIKE(
            HEX(n.name_text),
            '^(?:[0-9A-F]{2})*(?:2[0-9A-F]|[3-6][0-9A-F]|7[0-9A-E])'
        )
            THEN 'Preserve pending source review; do not auto-strip'

        ELSE 'Preserve exact serialized name; likely obfuscated or synthetic'
    END AS recommended_action
FROM ue_names n
JOIN ue_files f
    ON f.id = n.file_id
LEFT JOIN ue_games g
    ON g.id = f.game_id
WHERE
    LOCATE(
        UNHEX('00'),
        CONVERT(n.name_text USING binary)
    ) > 0

    OR LOCATE(
        UNHEX('EFBFBD'),
        CONVERT(n.name_text USING binary)
    ) > 0

    OR REGEXP_LIKE(
        HEX(n.name_text),
        '^(?:[0-9A-F]{2})*(?:0[0-9A-F]|1[0-9A-F]|7F)'
    );

ALTER TABLE ue_string_integrity_report
    ADD PRIMARY KEY (name_row_id),
    ADD KEY idx_string_integrity_file (file_id),
    ADD KEY idx_string_integrity_classification (classification);

DROP TABLE IF EXISTS ue_string_integrity_files;

CREATE TABLE ue_string_integrity_files
ENGINE=InnoDB
DEFAULT CHARACTER SET utf8mb4
COLLATE=utf8mb4_unicode_ci
AS
SELECT
    r.file_id,
    r.game,
    r.package_name,
    r.original_name,
    SUM(r.classification = 'embedded_nul_reimport') AS embedded_nul_names,
    SUM(r.classification = 'replacement_character_reimport') AS replacement_character_names,
    SUM(r.classification = 'trailing_control_review') AS trailing_control_names,
    SUM(r.classification = 'control_only_preserve') AS control_only_names,
    SUM(r.classification = 'mixed_control_preserve') AS mixed_control_names,
    CASE
        WHEN SUM(
            r.classification IN (
                'embedded_nul_reimport',
                'replacement_character_reimport'
            )
        ) > 0
            THEN 'REIMPORT'

        WHEN SUM(r.classification = 'trailing_control_review') > 0
            THEN 'REVIEW_PRESERVE'

        ELSE 'PRESERVE_OBFUSCATED'
    END AS recommended_action
FROM ue_string_integrity_report r
GROUP BY
    r.file_id,
    r.game,
    r.package_name,
    r.original_name;

ALTER TABLE ue_string_integrity_files
    ADD PRIMARY KEY (file_id),
    ADD KEY idx_string_integrity_files_action (recommended_action);

-- File-level summary.
SELECT *
FROM ue_string_integrity_files
ORDER BY
    FIELD(
        recommended_action,
        'REIMPORT',
        'REVIEW_PRESERVE',
        'PRESERVE_OBFUSCATED'
    ),
    embedded_nul_names DESC,
    game,
    package_name;

-- Exact primary name rows. Export this table from phpMyAdmin when required.
SELECT *
FROM ue_string_integrity_report
ORDER BY
    file_id,
    name_index;

-- Reimport candidates only.
SELECT
    file_id,
    game,
    package_name,
    original_name,
    embedded_nul_names,
    replacement_character_names
FROM ue_string_integrity_files
WHERE recommended_action = 'REIMPORT'
ORDER BY
    game,
    package_name,
    file_id;

-- Post-reimport verification. This should return zero after all REIMPORT files
-- have been rebuilt using the corrected reader.
SELECT COUNT(*) AS remaining_embedded_nul_name_rows
FROM ue_names
WHERE LOCATE(
    UNHEX('00'),
    CONVERT(name_text USING binary)
) > 0;

-- Optional cleanup after exporting/reviewing the report:
-- DROP TABLE ue_string_integrity_files;
-- DROP TABLE ue_string_integrity_report;
