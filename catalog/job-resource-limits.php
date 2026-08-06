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

    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $store = new CatalogJobResourceLimitStore($db, $queueName);
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

        // Saving resource limits must remain a short database operation. Worker
        // process lifecycle belongs to Background Jobs; attempting a detached
        // launch here can leave the settings request waiting after the database
        // transaction has already committed.
        $_SESSION['job_resource_limits_flash'] = 'Saved ' . (int)$result['updated_settings']
            . ' changed resource limit' . ((int)$result['updated_settings'] === 1 ? '' : 's')
            . ' and updated ' . (int)$result['updated_jobs'] . ' current queued job rows.';
        header('Location: job-resource-limits.php', true, 303);
        exit;
    }

    $rows = $store->summaries();
    $workerStatus = (new CatalogDetachedWorker($config))->status($queueName);
    $activeWorkers = max(0, (int)($workerStatus['active_count'] ?? 0));
    $desiredWorkers = max(1, (int)($workerStatus['desired_count'] ?? CatalogDetachedWorker::DEFAULT_WORKERS));
    $maximumWorkers = max(1, (int)($workerStatus['max_workers'] ?? CatalogDetachedWorker::MAX_WORKERS));

    catalog_head('Job Resource Limits');
    catalog_page_header(
        'Job Resource Limits',
        'Control how many jobs from each workload class may run concurrently. Saved changes apply to new jobs and immediately update every current queued row in that class.',
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
        . '<p><strong>Current detached worker pool:</strong> ' . $activeWorkers . ' active / ' . $desiredWorkers
        . ' configured, maximum ' . $maximumWorkers . '.</p>'
        . '<p class="muted">Effective concurrency is limited by both the workload limit and the number of worker processes. Saving updates queued rows immediately. Already-running jobs are allowed to finish; reducing a limit prevents further claims until the active count falls below it. Use Background Jobs to change or restart worker processes.</p>'
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
        . '<p><button class="primary" type="submit">Save limits and update queued jobs</button> '
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
