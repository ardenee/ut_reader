<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for encrypt federation secrets.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
require_once dirname(__DIR__) . '/lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $result = fed_migrate_peer_secrets($db);

    echo 'Federation peer secret migration complete.' . PHP_EOL;
    echo 'Migrated plaintext rows: ' . (int)$result['migrated'] . PHP_EOL;
    echo 'Already encrypted rows: ' . (int)$result['encrypted'] . PHP_EOL;
    echo 'Rows without a secret: ' . (int)$result['missing'] . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Federation peer secret migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
