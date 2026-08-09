<?php
/**
 * Purpose: Introduces the dedicated compressed metadata staging record used by Unverified Files.
 * Why: Unverified package inventory must not depend on the retired ue_names/ue_imports/ue_exports/ue_dependencies tables.
 * Role: Migration-owned persistence boundary for temporary, pre-game-selection package metadata.
 */
declare(strict_types=1);

return [
    'version' => '202608090001',
    'description' => 'Add compressed unverified metadata staging independent of legacy package tables.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        $schema->requireTable('ue_files');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS ue_unverified_metadata ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'format_version TINYINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'codec VARCHAR(32) NOT NULL DEFAULT "gzip-json",'
            . 'name_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'import_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'export_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'uncompressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'compressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'payload LONGBLOB NOT NULL,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (file_id),'
            . 'CONSTRAINT fk_ue_unverified_metadata_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Upgrade safety: installations that still have indexed unverified rows in
        // the former row-per-table staging format are converted in place. The old
        // tables remain untouched by this migration and are retired separately.
        foreach (['ue_names', 'ue_imports', 'ue_exports'] as $legacyTable) {
            if (!$schema->tableExists($legacyTable)) {
                return;
            }
        }

        $files = $db->query(
            'SELECT id,package_name,name_count,import_count,export_count '
            . 'FROM ue_files WHERE scan_status="unverified" ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($files === []) {
            return;
        }

        $load = static function (PDO $db, string $sql, int $fileId): array {
            $statement = $db->prepare($sql);
            $statement->execute([$fileId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };
        $upsert = $db->prepare(
            'INSERT INTO ue_unverified_metadata('
            . 'file_id,format_version,codec,name_count,import_count,export_count,'
            . 'uncompressed_size,compressed_size,payload) VALUES(?,1,"gzip-json",?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE format_version=VALUES(format_version),codec=VALUES(codec),'
            . 'name_count=VALUES(name_count),import_count=VALUES(import_count),export_count=VALUES(export_count),'
            . 'uncompressed_size=VALUES(uncompressed_size),compressed_size=VALUES(compressed_size),'
            . 'payload=VALUES(payload)'
        );

        foreach ($files as $file) {
            $fileId = (int)$file['id'];
            $names = $load($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', $fileId);
            $imports = $load($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', $fileId);
            $exports = $load($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', $fileId);
            $snapshot = [
                'file_id' => $fileId,
                'package_name' => (string)$file['package_name'],
                'names' => $names,
                'imports' => $imports,
                'exports' => $exports,
                'paths' => [
                    'imports' => array_map(
                        static fn(array $row): array => [
                            'full' => (string)($row['full_path'] ?? ''),
                            'root' => (string)($row['root_package'] ?? ''),
                            'relative' => (string)($row['relative_object_path'] ?? ''),
                        ],
                        $imports
                    ),
                    'exports' => array_map(
                        static fn(array $row): array => [
                            'local' => (string)($row['local_path'] ?? ''),
                            'full' => (string)($row['full_path'] ?? ''),
                        ],
                        $exports
                    ),
                ],
            ];
            $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $compressed = gzencode($json, 6, ZLIB_ENCODING_GZIP);
            if (!is_string($compressed)) {
                throw new RuntimeException('Could not compress unverified metadata for file #' . $fileId . '.');
            }
            $upsert->bindValue(1, $fileId, PDO::PARAM_INT);
            $upsert->bindValue(2, count($names), PDO::PARAM_INT);
            $upsert->bindValue(3, count($imports), PDO::PARAM_INT);
            $upsert->bindValue(4, count($exports), PDO::PARAM_INT);
            $upsert->bindValue(5, strlen($json), PDO::PARAM_INT);
            $upsert->bindValue(6, strlen($compressed), PDO::PARAM_INT);
            $upsert->bindValue(7, $compressed, PDO::PARAM_LOB);
            $upsert->execute();
        }
    },
];
