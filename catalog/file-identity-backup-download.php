<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Streams a generated file-identity recovery manifest to an authenticated administrator.
 * Why: Recovery manifests live outside the public web root/storage URL contract and should not be exposed directly.
 * Role: Admin-only download endpoint for lightweight filename/location backups.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\FileIdentityBackupStore;

catalog_start_session();

try {
    $config = catalog_config();
    catalog_db($config);
    if (!catalog_require_admin_page('File Identity Backup')) {
        exit;
    }

    $filename = trim((string)($_GET['file'] ?? ''));
    $store = new FileIdentityBackupStore($config);
    $path = $store->resolve($filename);
    $size = (int)(@filesize($path) ?: 0);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', basename($filename)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    if ($size > 0) {
        header('Content-Length: ' . $size);
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open file identity backup.');
    }
    try {
        fpassthru($handle);
    } finally {
        fclose($handle);
    }
    exit;
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('File identity backup error');
    }
    echo '<div class="card"><h1>File identity backup error</h1><p>' . catalog_h($error->getMessage())
        . '</p><p><a class="button" href="game-backups.php">Back to game backups</a></p></div>';
    catalog_foot();
}
