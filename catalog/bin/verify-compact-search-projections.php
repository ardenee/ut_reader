#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $export = $db->query(
        'SELECT '
        . 'COUNT(*) checked_exports,'
        . 'SUM(CASE WHEN l.local_path_term_id IS NULL THEN 1 ELSE 0 END) missing_export_path_terms,'
        . 'SUM(CASE WHEN l.local_path_term_id IS NOT NULL AND ('
        . 't.value_hash<>UNHEX(MD5(e.local_path)) OR t.value_length<>OCTET_LENGTH(e.local_path)'
        . ') THEN 1 ELSE 0 END) export_path_mismatches '
        . 'FROM ue_export_lookup l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'JOIN ue_exports e ON e.file_id=l.file_id AND e.export_index=l.export_index '
        . 'LEFT JOIN ue_terms t ON t.id=l.local_path_term_id'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $imports = $db->query(
        'SELECT '
        . 'COUNT(*) checked_imports,'
        . 'SUM(CASE WHEN l.import_object_term_id IS NULL THEN 1 ELSE 0 END) missing_import_object_terms,'
        . 'SUM(CASE WHEN l.import_object_term_id IS NOT NULL AND ('
        . 't.value_hash<>UNHEX(MD5(i.object_name)) OR t.value_length<>OCTET_LENGTH(i.object_name)'
        . ') THEN 1 ELSE 0 END) import_object_mismatches '
        . 'FROM ue_dependency_links l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
        . 'LEFT JOIN ue_terms t ON t.id=l.import_object_term_id'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $result = [
        'checked_exports' => (int)($export['checked_exports'] ?? 0),
        'missing_export_path_terms' => (int)($export['missing_export_path_terms'] ?? 0),
        'export_path_mismatches' => (int)($export['export_path_mismatches'] ?? 0),
        'checked_imports' => (int)($imports['checked_imports'] ?? 0),
        'missing_import_object_terms' => (int)($imports['missing_import_object_terms'] ?? 0),
        'import_object_mismatches' => (int)($imports['import_object_mismatches'] ?? 0),
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $failures = $result['missing_export_path_terms']
        + $result['export_path_mismatches']
        + $result['missing_import_object_terms']
        + $result['import_object_mismatches'];
    exit($failures === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact search projection verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
