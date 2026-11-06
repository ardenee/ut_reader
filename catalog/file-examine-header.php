<?php
/** Deferred physical package-header inspection for file-examine.php. */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;
use UnrealDb\Catalog\Infrastructure\Persistence\CatalogStoredPackageFileResolver;

header('Content-Type: application/json; charset=utf-8');

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = max(0, (int)($_GET['id'] ?? 0));
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1', [$fileId]);
    if (!$file) {
        throw new RuntimeException('Verified file not found.');
    }

    $storedPath = CatalogStoredPackageFileResolver::resolve($config, $file);
    $inspection = CatalogPackageHeaderInspector::inspect($storedPath, $file);
    echo json_encode(['ok' => true, 'inspection' => $inspection], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
