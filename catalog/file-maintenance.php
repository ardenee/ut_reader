<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogFileMaintenance.php';

catalog_start_session();
header('Content-Type: application/json; charset=utf-8');
if (!catalog_support_is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true, 'csrf' => catalog_csrf('catalog-maintenance')]);
