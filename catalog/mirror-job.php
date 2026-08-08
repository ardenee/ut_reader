<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and fulfils one external mirror job.
 * Why: Mirror lifecycle validation, persistence and storage-path checks belong to the shared mirror admin service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Fulfil Mirror Job')) {
        exit;
    }

    $service = new CatalogExternalMirrorAdminService($db, $config);
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $job = $service->job($id);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('mirror_job');
        $service->fulfill(
            (int)$job['id'],
            (string)($_POST['external_url'] ?? ''),
            (int)($_POST['expiry_days'] ?? 0),
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
        );

        $_SESSION['mirror_job_flash'] = 'Mirror job fulfilled. Active external link created.';
        header('Location: mirror-job.php?id=' . (int)$job['id']);
        exit;
    }

    catalog_head('Fulfil Mirror Job');
    catalog_flash($_SESSION['mirror_job_flash'] ?? null);
    unset($_SESSION['mirror_job_flash']);

    $path = '';
    try {
        $path = $service->storagePath($job);
    } catch (Throwable $e) {
        $path = 'missing: ' . $e->getMessage();
    }

    catalog_page_header('Fulfil Mirror Job #' . (string)(int)$job['id'], 'Upload this file to your chosen shared hosting provider, then paste the public URL below. This creates an active cached mirror link for public downloads.', ['Mirror Queue' => 'mirror-queue.php', 'Mirror Links' => 'mirror-links.php?file_id=' . (int)$job['file_id'], 'Test Public Download' => 'download.php?id=' . (int)$job['file_id']]);

    echo '<div class="card"><h2>File details</h2><table>';
    echo '<tr><th>Status</th><td>' . catalog_h($job['status']) . '</td></tr>';
    echo '<tr><th>Provider</th><td>' . catalog_h(($job['provider_name'] ?? '') . ' / ' . ($job['provider_key'] ?? '')) . '</td></tr>';
    echo '<tr><th>Package</th><td class="mono">' . catalog_h($job['package_name']) . '</td></tr>';
    echo '<tr><th>Original filename</th><td>' . catalog_h($job['original_name']) . '</td></tr>';
    echo '<tr><th>Size</th><td>' . catalog_h(catalog_bytes((int)$job['file_size'])) . '</td></tr>';
    echo '<tr><th>MD5</th><td class="mono">' . catalog_h($job['md5']) . '</td></tr>';
    echo '<tr><th>SHA1</th><td class="mono">' . catalog_h($job['sha1']) . '</td></tr>';
    echo '<tr><th>GUID</th><td class="mono">' . catalog_h($job['package_guid']) . '</td></tr>';
    echo '<tr><th>Admin storage path</th><td class="mono path">' . catalog_h($path) . '</td></tr>';
    echo '</table></div>';

    if (in_array((string)$job['status'], ['queued','waiting_admin','failed','uploading'], true)) {
        $defaultDays = $service->defaultExpiryDays($job);
        echo '<div class="card"><h2>Complete mirror job</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_job')) . '"><input type="hidden" name="id" value="' . (int)$job['id'] . '">';
        echo '<p><label>External shared-provider URL<br><input name="external_url" required style="min-width:760px" placeholder="https://..."></label></p>';
        echo '<p><label>Expiry/stale days<br><input name="expiry_days" value="' . $defaultDays . '" style="width:90px"></label> <span class="muted">Default comes from provider/settings. After this, the link is treated as stale/expired.</span></p>';
        echo '<p><button>Save external link and fulfil job</button></p></form></div>';
    } else {
        echo '<div class="card"><h2>Job not editable</h2><p class="muted">This job is already closed or cannot be fulfilled in its current status.</p></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Mirror job error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
