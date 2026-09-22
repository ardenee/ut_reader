<?php
/** Deferred physical package-header inspection for file-examine.php. */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;

header('Content-Type: application/json; charset=utf-8');

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = max(0, (int)($_GET['id'] ?? 0));
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1', [$fileId]);
    if (!$file) {
        throw new RuntimeException('Verified file not found.');
    }

    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $storedPath = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    if ($storageRoot && $storedPath && !str_starts_with($storedPath, $storageRoot)) {
        $storedPath = null;
    }

    $inspection = CatalogPackageHeaderInspector::inspect($storedPath ?: null, $file);
    echo json_encode(['ok' => true, 'inspection' => $inspection], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
