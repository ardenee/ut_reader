<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Application/Dashboard/CatalogDashboardStats.php';

if (!class_exists('CatalogDashboardStats', false)) {
    class_alias(
        \UnrealDb\Catalog\Application\Dashboard\CatalogDashboardStats::class,
        'CatalogDashboardStats'
    );
}
