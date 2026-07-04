<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/Readers/CatalogReaderResolver.php';

if (!class_exists('CatalogReaderResolver', false)) {
    class_alias(
        \UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver::class,
        'CatalogReaderResolver'
    );
}
