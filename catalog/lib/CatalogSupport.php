<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

\UnrealDb\Catalog\Presentation\Http\LegacySupportHooks::register();
