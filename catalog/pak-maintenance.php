<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for PAK maintenance.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('PAK Maintenance')) {
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('PAK maintenance requires POST.');
    }
    catalog_check_csrf('pak-maintenance');
    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        throw new RuntimeException('PAK archive management is not installed.');
    }

    $pakId = (int)($_POST['pak_id'] ?? 0);
    $operation = strtolower(trim((string)($_POST['operation'] ?? '')));
    $pak = catalog_one($db, 'SELECT * FROM ue_pak_archives WHERE id=?', [$pakId]);
    if (!$pak) {
        throw new RuntimeException('PAK archive not found.');
    }

    if ($operation !== 'delete') {
        throw new RuntimeException('Unsupported PAK maintenance operation.');
    }

    (new CatalogPakArchiveStore($config))->delete($db, $pak);
    $_SESSION['pak_maintenance_flash'] = 'Deleted retained PAK archive: ' . (string)$pak['original_name'];
    header('Location: game-paks.php?id=' . (int)$pak['game_id']);
    exit;
} catch (Throwable $error) {
    error_log('[UnrealDB PAK maintenance][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('PAK maintenance error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'PAK maintenance failed.');
    echo '<p><a class="button" href="games.php">Back to games</a></p>';
    catalog_foot();
}
