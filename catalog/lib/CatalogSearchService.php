<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Application/Search/CatalogSearchService.php';

if (!class_exists('CatalogSearchService', false)) {
    class_alias(
        \UnrealDb\Catalog\Application\Search\CatalogSearchService::class,
        'CatalogSearchService'
    );
}

if (!class_exists('CatalogSearchUnavailableException', false)) {
    class_alias(
        \UnrealDb\Catalog\Application\Search\CatalogSearchUnavailableException::class,
        'CatalogSearchUnavailableException'
    );
}
