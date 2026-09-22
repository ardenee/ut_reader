<?php
/**
 * Lightweight Uses / Used By relationships for file-examine.php.
 *
 * Uses only the compact SQL dependency projection. It deliberately does not
 * hydrate per-import dependency details from .uedb3; the examiner needs file
 * relationships here, not the full dependency-detail payload used by file-info.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogDependencySchema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_dependency_schema_ensure($db);
    $fileId = max(0, (int)($_GET['id'] ?? 0));

    $file = catalog_one($db, 'SELECT id FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1', [$fileId]);
    if (!$file) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Verified file not found.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $requires = catalog_all(
        $db,
        'SELECT DISTINCT dst.id,dst.original_name file,dst.package_name package,dst.file_size size,dst.package_guid guid,dst.md5'
        . ' FROM ue_dependency_links l'
        . ' JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=3'
        . ' JOIN ue_files dst ON dst.id=l.resolved_file_id AND dst.scan_status="verified"'
        . ' WHERE l.file_id=? AND l.resolved_file_id IS NOT NULL AND l.resolved_file_id<>?'
        . ' ORDER BY dst.original_name,dst.id LIMIT 5000',
        [$fileId, $fileId]
    );
    $requiredBy = catalog_all(
        $db,
        'SELECT DISTINCT src.id,src.original_name file,src.package_name package,src.file_size size,src.package_guid guid,src.md5'
        . ' FROM ue_dependency_links l'
        . ' JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=3'
        . ' JOIN ue_files src ON src.id=l.file_id AND src.scan_status="verified"'
        . ' WHERE l.resolved_file_id=? AND l.file_id<>?'
        . ' ORDER BY src.original_name,src.id LIMIT 5000',
        [$fileId, $fileId]
    );

    foreach ($requires as &$row) {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)$row['size'];
        $row['size_text'] = catalog_bytes((int)$row['size']);
    }
    unset($row);
    foreach ($requiredBy as &$row) {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)$row['size'];
        $row['size_text'] = catalog_bytes((int)$row['size']);
    }
    unset($row);

    echo json_encode([
        'ok' => true,
        'requires' => $requires,
        'required_by' => $requiredBy,
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
