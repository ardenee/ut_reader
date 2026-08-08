<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and runs the trusted HTTP source scanner.
 * Why: Manifest parsing, trusted HTTP IO, matching heuristics, deep GUID inspection and source-location persistence
 *      now live behind Infrastructure services.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Source\CatalogHttpSourceScanService;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceAdminService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('HTTP source scan')) {
        exit;
    }

    $sourceAdmin = new CatalogSourceAdminService($db);
    $scanner = new CatalogHttpSourceScanService($db, $config);
    $result = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('http_source_scan');
        $maxDeepBytes = max(
            1024 * 1024,
            min(256 * 1024 * 1024, (int)($_POST['max_deep_mb'] ?? 128) * 1024 * 1024)
        );
        $result = $scanner->run(
            (int)($_POST['source_id'] ?? 0),
            (string)($_POST['manifest_name'] ?? 'files.txt'),
            isset($_POST['check_remote_size']),
            isset($_POST['deep_scan']),
            $maxDeepBytes
        );
    }

    $sources = $sourceAdmin->activeHttpSources();
    catalog_head('HTTP source scan');
    catalog_page_header('HTTP source scanner', 'Scans a trusted HTTPS mirror manifest using the selected game profile extension list. Matched source-relative paths are preserved for UE4 package identity and later Full Sync reimports.', ['Sources' => 'sources.php', 'Local Source Scan' => 'source-scan.php', 'Unverified Files' => 'unverified-files.php', 'Games' => 'games.php']);

    if ($result !== null) {
        echo '<div class="card"><h2>Scan result</h2><table>';
        foreach (['source' => 'Source', 'manifest_url' => 'Manifest URL', 'path_count' => 'Package-like paths', 'matched' => 'Matched by name/size/package', 'matched_guid' => 'Matched by deep GUID', 'unknown' => 'Unknown', 'ambiguous' => 'Ambiguous', 'deep_failed' => 'Deep-scan failures', 'invalid_paths' => 'Rejected manifest paths'] as $key => $label) {
            $value = $key === 'source' ? (string)$result['source']['name'] : (string)$result[$key];
            echo '<tr><th>' . catalog_h($label) . '</th><td>' . catalog_h($value) . '</td></tr>';
        }
        echo '</table>';
        if ($result['samples']) {
            echo '<h3>Examples</h3><ul>';
            foreach ($result['samples'] as $sample) {
                echo '<li class="mono">' . catalog_h($sample) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    echo '<section class="card"><h2>Run scan</h2>';
    if (!$sources) {
        echo '<p class="muted">No active HTTP mirror or redirect-server sources are configured.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('http_source_scan')) . '">';
        echo '<p><label>Source <select name="source_id" required>';
        foreach ($sources as $source) {
            echo '<option value="' . (int)$source['id'] . '">' . catalog_h($source['game_name'] . ' — ' . $source['name']) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Manifest relative path <input name="manifest_name" value="files.txt" required></label></p>';
        echo '<p><label><input type="checkbox" name="check_remote_size" checked> Compare remote Content-Length when available</label></p>';
        echo '<p><label><input type="checkbox" name="deep_scan"> Deep-scan unknown packages for GUIDs (maximum 100 files)</label></p>';
        echo '<p><label>Maximum bytes per deep scan <input type="number" name="max_deep_mb" min="1" max="256" value="128"> MB</label></p>';
        echo '<button type="submit">Run secure source scan</button></form>';
    }
    echo '</section>';
    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB][' . catalog_request_id() . '] HTTP source scan: ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        catalog_head('HTTP source scan');
    }
    echo '<div class="msg err"><strong>HTTP source scan failed.</strong> ' . catalog_h(catalog_public_error_message()) . '</div>';
    catalog_foot();
}
