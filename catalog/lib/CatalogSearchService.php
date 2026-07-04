<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/../src/Application/Search/CatalogSearchService.php';

class_alias('UnrealDb\\Catalog\\Application\\Search\\CatalogSearchService', 'CatalogSearchService');
class_alias('UnrealDb\\Catalog\\Application\\Search\\CatalogSearchUnavailableException', 'CatalogSearchUnavailableException');
