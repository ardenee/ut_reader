<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Application/Dependency/CatalogDependencyResolver.php';

if (!class_exists('CatalogDependencyResolver', false)) {
    class_alias(
        \UnrealDb\Catalog\Application\Dependency\CatalogDependencyResolver::class,
        'CatalogDependencyResolver'
    );
}
