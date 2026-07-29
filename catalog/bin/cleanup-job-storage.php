<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobStorageCleanup;

$minimumAge = 300;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--min-age-seconds=([0-9]+)$/', (string)$argument, $match) === 1) {
        $minimumAge = (int)$match[1];
    }
}

try {
    $application = catalog_bootstrap();
    $result = (new CatalogJobStorageCleanup($application->db, $application->config))->prune($minimumAge);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'minimum_age_seconds' => max(60, min($minimumAge, 30 * 86400)),
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
