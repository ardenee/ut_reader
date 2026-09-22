#!/usr/bin/env php
<?php
/**
 * Query catalog-wide v3 package object coverage without mutating catalog state.
 *
 * Example:
 *   php catalog/bin/analyze-v3-package-superset.php --game-id=2 --package=Foo
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageSupersetAnalyzer;

$options = getopt('', ['game-id:', 'package:', 'max-paths::', 'max-providers::']);
$gameId = (int)($options['game-id'] ?? 0);
$packageName = trim((string)($options['package'] ?? ''));
$maxPaths = max(1, min(5000, (int)($options['max-paths'] ?? 1000)));
$maxProviders = max(1, min(1000, (int)($options['max-providers'] ?? 250)));

if ($gameId < 1 || $packageName === '') {
    fwrite(STDERR, "Usage: php catalog/bin/analyze-v3-package-superset.php --game-id=ID --package=NAME [--max-paths=1000] [--max-providers=250]\n");
    exit(2);
}

$config = catalog_config();
$db = catalog_db($config);
$result = PdoPackageSupersetAnalyzer::analyze($db, $gameId, $packageName);

$allPaths = array_values((array)($result['required_object_paths'] ?? []));
$allProviders = array_values((array)($result['providers'] ?? []));
$result['required_object_paths_total'] = count($allPaths);
$result['providers_total'] = count($allProviders);
$result['required_object_paths_truncated'] = count($allPaths) > $maxPaths;
$result['providers_truncated'] = count($allProviders) > $maxProviders;
$result['required_object_paths'] = array_slice($allPaths, 0, $maxPaths);
$result['providers'] = array_slice($allProviders, 0, $maxProviders);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
