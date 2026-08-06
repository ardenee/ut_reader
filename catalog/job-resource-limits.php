<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobResourceLimitStore;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Job Resource Limits')) {
        exit;
    }

    $store = new CatalogJobResourceLimitStore($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('job_resource_limits');
        $posted = $_POST['limits'] ?? [];
        if (!is_array($posted)) {
            throw new InvalidArgumentException('Invalid resource-limit submission.');
        }

        $limits = [];
        foreach (JobResourcePolicy::definitions() as $resourceClass => $definition) {
            $raw = $posted[$resourceClass] ?? null;
            if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Enter a whole-number limit for ' . (string)$definition['label'] . '.');
            }
            $limit = (int)$raw;
            if ($limit < 1 || $limit > 100) {
                throw new InvalidArgumentException((string)$definition['label'] . ' must be between 1 and 100.');
            }
            $limits[$resourceClass] = $limit;
        }

        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $result = $store->save($limits, $userId);
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $workerMessage = '';
        try {
            $launch = (new CatalogDetachedWorker($config))->start($queueName, 10000);
            $started = max(0, (int)($launch['started_workers'] ?? 0));
            if ($started > 0) {
                $workerMessage = ' Started ' . $started . ' additional worker process' . ($started === 1 ? '' : 'es') . '.';
            }
        } catch (Throwable $workerError) {
            $workerMessage = ' Limits were saved, but the worker pool could not be expanded automatically: '
                . trim($workerError->getMessage());
            error_log('[UnrealDB job resource limits] Worker expansion failed: ' . $workerError->getMessage());
        }

        $_SESSION['job_resource_limits_flash'] = 'Saved ' . (int)$result['updated_settings']
            . ' resource limits and updated ' . (int)$result['updated_jobs']
            . ' current queued/running job rows.' . $workerMessage;
        header('Location: job-resource-limits.php', true, 303);
        exit;
    }

    $rows = $store->summaries();

    catalog_head('Job Resource Limits');
    catalog_page_header(
        'Job Resource Limits',
        'Control how many jobs from each workload class may run concurrently. Saved changes apply to new jobs and immediately update every current queued or running row in that class.',
        ['Background Jobs' => 'background-jobs.php', 'Performance Readiness' => 'performance-readiness.php']
    );

    if (isset($_SESSION['job_resource_limits_flash'])) {
        catalog_flash((string)$_SESSION['job_resource_limits_flash']);
        unset($_SESSION['job_resource_limits_flash']);
    }

    echo '<style>'
        . '.job-limit-table{table-layout:fixed;min-width:1120px}'
        . '.job-limit-table th:nth-child(1){width:230px}.job-limit-table th:nth-child(2){width:155px}'
        . '.job-limit-table th:nth-child(3){width:105px}.job-limit-table th:nth-child(4),'
        . '.job-limit-table th:nth-child(5),.job-limit-table th:nth-child(6),'
        . '.job-limit-table th:nth-child(7){width:105px}'
        . '.job-limit-value{width:82px}'
        . '.job-limit-blocked{color:#fecdd3;font-weight:700}'
        . '.job-limit-available{color:#a7f3d0;font-weight:700}'
        . '.job-limit-class{white-space:nowrap}'
        . '</style>';

    echo '<div class="card">'
        . '<h2>How the limits work</h2>'
        . '<p>A worker can claim a ready job only while the number of running jobs in the same resource class is below that class limit. Per-file and per-game concurrency keys still prevent two workers from changing the same target.</p>'
        . '<p class="muted">Raising a limit lets idle worker slots claim more work immediately. Lowering a limit does not terminate work already running; it prevents further claims until the active count falls below the new limit.</p>'
        . '</div>';

    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('job_resource_limits')) . '">'
        . '<div class="card"><h2>Workload limits and current queue pressure</h2>'
        . '<div class="table-wrap"><table class="job-limit-table"><thead><tr>'
        . '<th>Workload</th><th>Resource class</th><th>Limit</th><th>Running</th>'
        . '<th>Ready</th><th>Queued</th><th>Class blocked</th><th>Description</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $resourceClass = (string)$row['resource_class'];
        $isLimiting = !empty($row['is_limiting']);
        echo '<tr>'
            . '<td><strong>' . catalog_h((string)$row['label']) . '</strong><br>'
            . '<span class="muted small">Built-in default: ' . (int)$row['default_limit'] . '</span></td>'
            . '<td class="mono job-limit-class">' . catalog_h($resourceClass) . '</td>'
            . '<td><input class="job-limit-value" type="number" min="1" max="100" required '
            . 'name="limits[' . catalog_h($resourceClass) . ']" value="' . (int)$row['limit'] . '"></td>'
            . '<td>' . (int)$row['running'] . '</td>'
            . '<td>' . (int)$row['ready'] . '</td>'
            . '<td>' . (int)$row['queued'] . '</td>'
            . '<td class="' . ($isLimiting ? 'job-limit-blocked' : 'job-limit-available') . '">'
            . ($isLimiting ? (int)$row['class_blocked'] . ' waiting' : (int)$row['available_slots'] . ' slot(s) free')
            . '</td>'
            . '<td>' . catalog_h((string)$row['description']) . '</td>'
            . '</tr>';
    }

    echo '</tbody></table></div>'
        . '<p class="muted small">“Class blocked” is the number of ready rows beyond the class capacity. A concurrency key may additionally serialize jobs that target the same file or game.</p>'
        . '</div>'
        . '<p><button class="primary" type="submit">Save limits and update current jobs</button> '
        . '<a class="button" href="background-jobs.php">Open Background Jobs</a></p>'
        . '</form>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] job resource limits failed: '
        . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Job Resource Limits error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Job resource limits could not be loaded or saved');
    catalog_foot();
}
