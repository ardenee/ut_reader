<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Streams stored original PAK archives when public download policy permits.
 * Why: Original PAK downloads must use the same central per-IP limit and speed control as all other local downloads.
 * Role: Web download endpoint; storage lookup and policy enforcement remain delegated to shared services.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/BaseGameProtection.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

catalog_start_session();

function pak_download_name(string $name): string
{
    $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
    $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '_', $name) ?? '';
    $name = rtrim(trim($name), ' .');
    if ($name === '' || strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'pak') {
        return 'archive.pak';
    }
    return $name;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);

    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        throw new RuntimeException('PAK archive management is not installed.');
    }

    $pakId = (int)($_GET['id'] ?? 0);
    $pak = catalog_one(
        $db,
        'SELECT p.*,g.name game_name FROM ue_pak_archives p JOIN ue_games g ON g.id=p.game_id WHERE p.id=? AND p.status="ready"',
        [$pakId]
    );
    if (!$pak) {
        throw new RuntimeException('PAK archive not found.');
    }

    $protected = catalog_one(
        $db,
        'SELECT f.* FROM ue_pak_entries e '
        . 'JOIN ue_files f ON f.id=e.file_id '
        . 'JOIN ue_base_game_files b ON b.game_id=f.game_id AND b.package_guid=f.package_guid '
        . 'WHERE e.pak_id=? LIMIT 1',
        [$pakId]
    );
    if ($protected) {
        catalog_head('PAK download blocked');
        echo '<div class="card"><h1>PAK download blocked</h1><p>This archive contains one or more protected base-game packages, so the original self-contained PAK cannot be redistributed.</p><p><a class="button" href="pak-info.php?id=' . $pakId . '">Back to PAK information</a></p></div>';
        catalog_foot();
        exit;
    }

    $mode = external_public_download_mode($db);
    if (!in_array($mode, ['local_direct', 'external_mirror_preferred'], true)) {
        catalog_head('PAK download unavailable');
        echo '<div class="card"><h1>PAK download unavailable</h1><p>Original PAK archives are stored locally and cannot be served while public download mode is <span class="mono">' . catalog_h($mode) . '</span>.</p><p><a class="button" href="pak-info.php?id=' . $pakId . '">Back to PAK information</a></p></div>';
        catalog_foot();
        exit;
    }

    $store = new CatalogPakArchiveStore($config);
    $path = $store->resolve($pak);
    $size = filesize($path);
    if ($size === false || (int)$size !== (int)$pak['file_size']) {
        throw new RuntimeException('Stored PAK size does not match the catalog record.');
    }

    // Use the same persisted per-IP action limit and transfer-rate ceiling as
    // download.php and generated-package-download.php.
    catalog_public_download_limit($db);
    $speedBytes = catalog_public_download_speed_bytes($db);

    $name = pak_download_name((string)$pak['original_name']);
    $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'archive.pak';

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Encoding');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (int)$size);
    header('Content-Disposition: attachment; filename="' . addcslashes($fallback, "\\\"")
        . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: private, no-store, no-transform');
    if ($speedBytes > 0) {
        header('X-UnrealDB-Rate-Limit: ' . $speedBytes . ' bytes/second');
    }

    catalog_public_stream_file($path, $speedBytes);
} catch (Throwable $error) {
    error_log('[UnrealDB PAK download][' . catalog_request_id() . '] ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('PAK download error');
    }
    echo '<div class="card"><h1>PAK download unavailable</h1><p>' . catalog_h(catalog_public_error_message()) . '</p></div>';
    catalog_foot();
}
