<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Application/Catalog/CatalogGameFileListService.php';

if (!class_exists('CatalogGameFileListService', false)) {
    class_alias(
        \UnrealDb\Catalog\Application\Catalog\CatalogGameFileListService::class,
        'CatalogGameFileListService'
    );
}
