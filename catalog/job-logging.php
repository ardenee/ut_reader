<?php
/**
 * Administrator controls for background-job event and worker diagnostic logging.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobLoggingSettingsStore;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Job Logging')) {
        exit;
    }

    $store = new CatalogJobLoggingSettingsStore($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('job_logging');
        $posted = is_array($_POST['enabled'] ?? null) ? $_POST['enabled'] : [];
        $values = [];
        foreach (CatalogJobLoggingSettingsStore::definitions() as $key => $_definition) {
            $values[$key] = array_key_exists($key, $posted);
        }
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $store->save($values, $userId !== null && $userId > 0 ? $userId : null);
        $_SESSION['job_logging_flash'] = 'Background-job logging settings saved.';
        header('Location: job-logging.php', true, 303);
        exit;
    }

    catalog_head('Job Logging');
    catalog_page_header(
        'Job Logging',
        'Control diagnostic/event noise without disabling durable job progress or actionable System Errors.',
        [
            'Background Jobs' => 'background-jobs.php',
            'System Errors' => 'system-errors.php',
            'Job Resource Limits' => 'job-resource-limits.php',
        ]
    );

    if (isset($_SESSION['job_logging_flash'])) {
        catalog_flash((string)$_SESSION['job_logging_flash']);
        unset($_SESSION['job_logging_flash']);
    }

    if (!$store->isAvailable()) {
        echo CatalogUi::alert(
            'warning',
            'Run catalog/bin/migrate.php migrate before changing these settings.',
            'Job logging settings are not installed'
        );
        catalog_foot();
        exit;
    }

    $values = $store->all();
    echo '<div class="card">'
        . '<h2>Logging policy</h2>'
        . '<p><strong>Job progress remains enabled regardless of these switches.</strong> '
        . 'The current progress snapshot stays on the durable job row for Background Jobs and workflow recovery.</p>'
        . '<p class="muted">Unexpected terminal background-job failures are recorded in System Errors even when routine event logging is disabled. '
        . 'Deterministic bad-file input remains under Upload Issues instead of being reported as an application fault. The defaults are intentionally errors-first: routine progress, successful, duplicate, skipped, cancelled and worker lifecycle chatter are off.</p>'
        . '</div>';

    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('job_logging')) . '">'
        . '<div class="card"><table><thead><tr><th>Write</th><th>Category</th><th>Description</th></tr></thead><tbody>';
    foreach (CatalogJobLoggingSettingsStore::definitions() as $key => $definition) {
        $checked = !empty($values[$key]) ? ' checked' : '';
        echo '<tr>'
            . '<td><input type="checkbox" name="enabled[' . catalog_h($key) . ']" value="1"' . $checked . '></td>'
            . '<td><strong>' . catalog_h((string)$definition['label']) . '</strong><br><span class="mono muted small">'
            . catalog_h($key) . '</span></td>'
            . '<td>' . catalog_h((string)$definition['description']) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>'
        . '<p><button class="primary" type="submit">Save logging settings</button></p>'
        . '</form>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] job logging settings failed: '
        . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Job Logging error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Job logging settings could not be loaded or saved');
    catalog_foot();
}
