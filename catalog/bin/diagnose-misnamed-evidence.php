<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

$config = catalog_config();
$db = catalog_db($config);
$fileIds = array_values(array_filter(array_map('intval', array_slice($argv, 1)), static fn(int $id): bool => $id > 0));
if ($fileIds === []) {
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-misnamed-evidence.php <file-id> [file-id ...]\n");
    exit(2);
}
foreach ($fileIds as $fileId) {
    $file = catalog_one($db, 'SELECT id,game_id,original_name,package_name,scan_status FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        echo json_encode(['file_id'=>$fileId,'error'=>'not found'], JSON_PRETTY_PRINT) . PHP_EOL;
        continue;
    }
    $exports = catalog_all($db,
        'SELECT e.export_index,e.object_term_id,CONVERT(o.value_prefix USING utf8mb4) object_name,'
        . 'e.local_path_term_id,CONVERT(p.value_prefix USING utf8mb4) local_path,HEX(e.path_hash) path_hash '
        . 'FROM ue_export_lookup e LEFT JOIN ue_terms o ON o.id=e.object_term_id '
        . 'LEFT JOIN ue_terms p ON p.id=e.local_path_term_id WHERE e.file_id=? ORDER BY e.export_index', [$fileId]);
    $missing = catalog_all($db,
        'SELECT l.import_index,l.required_package_term_id,CONVERT(rp.value_prefix USING utf8mb4) required_package,'
        . 'l.import_object_term_id,CONVERT(io.value_prefix USING utf8mb4) import_object,'
        . 'l.required_object_term_id,CONVERT(ro.value_prefix USING utf8mb4) required_object,'
        . 'HEX(l.required_path_hash) required_path_hash '
        . 'FROM ue_dependency_links l '
        . 'LEFT JOIN ue_terms rp ON rp.id=l.required_package_term_id '
        . 'LEFT JOIN ue_terms io ON io.id=l.import_object_term_id '
        . 'LEFT JOIN ue_terms ro ON ro.id=l.required_object_term_id '
        . 'WHERE l.file_id=? AND l.status=0 AND l.resolved_file_id IS NULL ORDER BY l.import_index', [$fileId]);
    echo json_encode(['file'=>$file,'exports'=>$exports,'missing_dependencies'=>$missing],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}
