#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Dependency\CatalogDependencyReadSource;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $source = CatalogDependencyReadSource::sql($db);

    $statement = $db->query(
        'SELECT '
        . 'COUNT(*) checked_rows,'
        . 'SUM(CASE WHEN d.metadata_source<>"compact" THEN 1 ELSE 0 END) source_mismatches,'
        . 'SUM(CASE WHEN d.required_package<>legacy.required_package THEN 1 ELSE 0 END) package_mismatches,'
        . 'SUM(CASE WHEN d.required_object_path<>legacy.required_object_path THEN 1 ELSE 0 END) object_mismatches,'
        . 'SUM(CASE WHEN d.status<>legacy.status THEN 1 ELSE 0 END) status_mismatches,'
        . 'SUM(CASE WHEN NOT (d.resolved_file_id <=> legacy.resolved_file_id) THEN 1 ELSE 0 END) resolved_file_mismatches,'
        . 'SUM(CASE WHEN d.class_package<>COALESCE(i.class_package,"") THEN 1 ELSE 0 END) class_package_mismatches,'
        . 'SUM(CASE WHEN d.class_name<>COALESCE(i.class_name,"") THEN 1 ELSE 0 END) class_name_mismatches,'
        . 'SUM(CASE WHEN d.import_full_path<>COALESCE(i.full_path,legacy.required_object_path) THEN 1 ELSE 0 END) import_path_mismatches '
        . 'FROM ' . $source . ' d '
        . 'JOIN ue_file_metadata m ON m.file_id=d.file_id AND m.format_version=2 '
        . 'JOIN ue_imports i ON i.file_id=d.file_id AND i.import_index=d.import_index '
        . 'JOIN ue_dependencies legacy ON legacy.file_id=d.file_id AND legacy.import_id=i.id'
    );
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Mixed dependency parity query returned no result.');
    }

    $result = [];
    foreach ($row as $key => $value) {
        $result[$key] = (int)$value;
    }
    $failures = array_sum(array_filter(
        $result,
        static fn(int $value, string $key): bool => $key !== 'checked_rows' && $value !== 0,
        ARRAY_FILTER_USE_BOTH
    ));

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failures === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Mixed dependency read-source verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
