<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php';

if (!class_exists('CatalogAffectedDependencyRefreshService', false)) {
    class_alias(
        \UnrealDb\Catalog\Application\Dependency\CatalogAffectedDependencyRefreshService::class,
        'CatalogAffectedDependencyRefreshService'
    );
}
