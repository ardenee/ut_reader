#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies compact metadata runtime query behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $file = catalog_one(
        $db,
        'SELECT f.id,f.game_id,f.package_name,f.name_count,f.import_count,f.export_count '
        . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified" AND f.name_count>0 AND f.import_count>0 AND f.export_count>0 '
        . 'ORDER BY (f.name_count+f.import_count+f.export_count),f.id LIMIT 1'
    );
    if (!$file) {
        throw new RuntimeException('No non-empty format-2 verification file is available.');
    }

    $fileId = (int)$file['id'];
    $snapshot = catalog_metadata_compat_snapshot($db, $config, $fileId);
    if ((string)$snapshot['source'] !== 'compact') {
        throw new RuntimeException('The compatibility reader did not select compact metadata.');
    }

    $names = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$fileId]);
    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$fileId]);
    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$fileId]);
    $dependencies = catalog_all($db, 'SELECT d.* FROM ue_dependencies d WHERE d.file_id=? ORDER BY d.id', [$fileId]);

    $expected = [
        'names' => (int)$file['name_count'],
        'imports' => (int)$file['import_count'],
        'exports' => (int)$file['export_count'],
        'dependencies' => (int)$file['import_count'],
    ];
    $actual = [
        'names' => count($names),
        'imports' => count($imports),
        'exports' => count($exports),
        'dependencies' => count($dependencies),
    ];
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Runtime compatibility count mismatch: expected=' . json_encode($expected)
            . ', actual=' . json_encode($actual)
        );
    }

    $dirty = catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_exports WHERE file_id=? '
        . 'AND full_path<>CASE WHEN local_path<>"" THEN CONCAT(?, ".", local_path) ELSE ? END',
        [$fileId, (string)$file['package_name'], (string)$file['package_name']]
    );
    if (!is_array($dirty) || !array_key_exists('c', $dirty)) {
        throw new RuntimeException('Package-normalize compact Export count was not returned.');
    }

    $upk = catalog_one(
        $db,
        'SELECT f.id,f.game_id,f.export_count FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified" AND LOWER(f.extension)="upk" '
        . 'ORDER BY f.export_count,f.id LIMIT 1'
    );
    $upkResult = ['available' => false];
    if ($upk) {
        $upkId = (int)$upk['id'];
        $upkSnapshot = catalog_metadata_compat_snapshot($db, $config, $upkId);
        $upkExports = (array)$upkSnapshot['exports'];
        $upkCount = catalog_one($db, 'SELECT COUNT(*) c FROM ue_exports e WHERE e.file_id=?', [$upkId]);
        $upkPage = catalog_all(
            $db,
            'SELECT e.* FROM ue_exports e WHERE e.file_id=? ORDER BY e.export_index LIMIT 200 OFFSET 0',
            [$upkId]
        );
        $upkClasses = catalog_all(
            $db,
            'SELECT COALESCE(NULLIF(class_name,""),"unknown") class_name,COUNT(*) c '
            . 'FROM ue_exports WHERE file_id=? GROUP BY COALESCE(NULLIF(class_name,""),"unknown") '
            . 'ORDER BY c DESC,class_name LIMIT 500',
            [$upkId]
        );
        $upkPayload = catalog_one(
            $db,
            'SELECT COUNT(*) export_count,COALESCE(SUM(serial_size),0) serial_bytes,'
            . 'COALESCE(MIN(serial_offset),0) first_offset,COALESCE(MAX(serial_offset+serial_size),0) last_end '
            . 'FROM ue_exports WHERE file_id=?',
            [$upkId]
        );
        $upkList = catalog_all(
            $db,
            'SELECT f.*,'
            . '(SELECT COALESCE(SUM(e.serial_size),0) FROM ue_exports e WHERE e.file_id=f.id) serialized_export_bytes '
            . 'FROM ue_files f WHERE f.id=?',
            [$upkId]
        );

        $expectedSerialBytes = array_sum(array_map(
            static fn(array $row): int => max(0, (int)($row['serial_size'] ?? 0)),
            $upkExports
        ));
        if ((int)($upkCount['c'] ?? -1) !== count($upkExports)) {
            throw new RuntimeException('UPK compact Export count mismatch.');
        }
        if (count($upkPage) !== min(200, count($upkExports))) {
            throw new RuntimeException('UPK compact Export page mismatch.');
        }
        if ((int)($upkPayload['export_count'] ?? -1) !== count($upkExports)
            || (int)($upkPayload['serial_bytes'] ?? -1) !== $expectedSerialBytes) {
            throw new RuntimeException('UPK compact payload aggregate mismatch.');
        }
        if ((int)($upkList[0]['serialized_export_bytes'] ?? -1) !== $expectedSerialBytes) {
            throw new RuntimeException('UPK list compact serialized-byte aggregate mismatch.');
        }

        $upkResult = [
            'available' => true,
            'file_id' => $upkId,
            'exports' => count($upkExports),
            'page_rows' => count($upkPage),
            'class_groups' => count($upkClasses),
            'serialized_bytes' => $expectedSerialBytes,
        ];
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'file_id' => $fileId,
        'metadata_source' => (string)$snapshot['source'],
        'counts' => $actual,
        'package_normalize_dirty_exports' => (int)$dirty['c'],
        'upk_contract' => $upkResult,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact metadata runtime query contract failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
