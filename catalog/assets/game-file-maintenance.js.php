<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, private');

if (!catalog_support_is_admin()) {
    exit;
}
