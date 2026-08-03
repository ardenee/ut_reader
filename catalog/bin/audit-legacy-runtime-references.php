#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    $result = LegacyMetadataRuntimeAudit::scan(dirname(__DIR__));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($result['references'] === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Legacy runtime reference audit failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
