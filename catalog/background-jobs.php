<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();

    if (!catalog_require_admin_page('Background Jobs')) {
        exit;
    }

    $configuredQueue = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $requestedQueue = trim((string)($_GET['queue'] ?? ''));
    if ($requestedQueue !== ''
        && (strlen($requestedQueue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $requestedQueue) !== 1)) {
        $requestedQueue = '';
    }

    $queueRows = catalog_all(
        $db,
        'SELECT queue_name,COUNT(*) total,'
            . 'SUM(status="queued") queued_total,SUM(status="running") running_total '
            . 'FROM ue_background_jobs GROUP BY queue_name ORDER BY queue_name'
    );
    $queueOptions = [];
    foreach ($queueRows as $row) {
        $name = trim((string)($row['queue_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $queueOptions[$name] = [
            'total' => (int)($row['total'] ?? 0),
            'queued' => (int)($row['queued_total'] ?? 0),
            'running' => (int)($row['running_total'] ?? 0),
        ];
    }
    if (!isset($queueOptions[$configuredQueue])) {
        $queueOptions[$configuredQueue] = ['total' => 0, 'queued' => 0, 'running' => 0];
    }

    $queueName = $requestedQueue;
    if ($queueName === '') {
        $configuredActive = ($queueOptions[$configuredQueue]['queued'] ?? 0) > 0
            || ($queueOptions[$configuredQueue]['running'] ?? 0) > 0;
        if ($configuredActive) {
            $queueName = $configuredQueue;
        } else {
            foreach ($queueOptions as $candidate => $summary) {
                if (($summary['queued'] ?? 0) > 0 || ($summary['running'] ?? 0) > 0) {
                    $queueName = $candidate;
                    break;
                }
            }
        }
    }
    if ($queueName === '') {
        $queueName = $configuredQueue;
    }
    if (!isset($queueOptions[$queueName])) {
        $queueOptions[$queueName] = ['total' => 0, 'queued' => 0, 'running' => 0];
    }
    ksort($queueOptions, SORT_NATURAL | SORT_FLAG_CASE);

    $counts = [
        'queued' => 0,
        'running' => 0,
        'completed' => 0,
        'failed' => 0,
        'dead_letter' => 0,
        'cancelled' => 0,
    ];
    foreach (catalog_all(
        $db,
        'SELECT status,JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")) result_status,COUNT(*) total '
            . 'FROM ue_background_jobs WHERE queue_name=? GROUP BY status,result_status',
        [$queueName]
    ) as $row) {
        $group = CatalogJobDisplayStatus::group(
            (string)($row['status'] ?? ''),
            isset($row['result_status']) ? (string)$row['result_status'] : null
        );
        if (array_key_exists($group, $counts)) {
            $counts[$group] += (int)$row['total'];
        }
    }

    catalog_head('Background Jobs');
    echo '<style>#background-jobs-app .job-status + .muted.small{display:none}.job-queue-picker{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px}.job-queue-picker select{min-width:320px}.job-running-for{white-space:nowrap}</style>';
    catalog_page_header(
        'Background Jobs',
        'View and control jobs in queue ' . $queueName . '. Upload Bucket redirect decompression uses its own queue.',
        [
            'Upload Bucket' => 'upload-bucket.php',
            'Upload Files' => 'profiled-upload.php',
            'PAK Import' => 'pak-import.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    echo '<form method="get" class="job-queue-picker"><label><strong>Job queue</strong> <select name="queue" onchange="this.form.submit()">';
    foreach ($queueOptions as $name => $summary) {
        $label = $name . ' — ' . (int)$summary['total'] . ' job(s)';
        if ((int)$summary['running'] > 0 || (int)$summary['queued'] > 0) {
            $label .= ' (' . (int)$summary['running'] . ' running, ' . (int)$summary['queued'] . ' queued)';
        }
        echo '<option value="' . catalog_h($name) . '"' . ($name === $queueName ? ' selected' : '') . '>'
            . catalog_h($label) . '</option>';
    }
    echo '</select></label><noscript><button type="submit">Open queue</button></noscript></form>';

    echo '<div class="grid">';
    catalog_stat_card('Queued', $counts['queued'], '', $counts['queued'] > 0 ? 'attention' : '');
    catalog_stat_card('Running', $counts['running'], '', $counts['running'] > 0 ? 'attention' : '');
    catalog_stat_card('Completed', $counts['completed'], '', 'good');
    catalog_stat_card('Failed', $counts['failed'] + $counts['dead_letter'], '', ($counts['failed'] + $counts['dead_letter']) > 0 ? 'warning' : '');
    catalog_stat_card('Cancelled', $counts['cancelled']);
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Queue controls</h2>';
    echo '<p class="muted">Start next launches a detached CLI worker for one job. Start queued launches a detached worker that drains this queue and exits. The worker continues after this page or browser is closed. Running jobs are never stopped automatically; use Stop job when a job is clearly stuck.</p>';
    echo '</div></div><div class="ui-section__body">';
    echo '<div id="background-jobs-app" '
        . 'data-queue="' . catalog_h($queueName) . '" '
        . 'data-status-url="api/v1/job-status.php" '
        . 'data-action-url="api/v1/job-action.php" '
        . 'data-pak-rerun-url="api/v1/job-rerun-pak.php" '
        . 'data-run-url="api/v1/job-run.php" '
        . 'data-worker-status-url="api/v1/job-worker-status.php" '
        . 'data-worker-action-url="api/v1/job-worker-action.php" '
        . 'data-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';
    echo '<p class="button-row">'
        . '<button id="jobs-run-next" type="button">Start next</button> '
        . '<button id="jobs-run-all" type="button">Start queued</button> '
        . '<button id="jobs-stop" type="button">Stop worker</button> '
        . '<button id="jobs-recover" type="button">Recover expired jobs</button> '
        . '<button id="jobs-refresh" type="button">Refresh</button>'
        . '</p>';
    echo '<p class="button-row"><label>Remove terminal jobs older than '
        . '<select id="jobs-cleanup-days">'
        . '<option value="1">1 day</option>'
        . '<option value="7">7 days</option>'
        . '<option value="30" selected>30 days</option>'
        . '<option value="90">90 days</option>'
        . '<option value="365">1 year</option>'
        . '</select></label> '
        . '<button id="jobs-cleanup" type="button">Clean old jobs</button></p>';
    echo '<p class="muted small">Cleanup removes completed, failed, dead-letter and cancelled records and their retained staged upload files. Queued and running jobs are never removed.</p>';
    echo '<p id="jobs-worker-message" class="muted" aria-live="polite">Loading worker status...</p>';
    echo '<p id="jobs-message" class="muted" aria-live="polite">Loading queue...</p>';
    echo '<div class="button-row jobs-bulk-controls">'
        . '<label>Status filter <select id="jobs-status-filter">'
        . '<option value="">All statuses</option>'
        . '<option value="queued">Queued</option>'
        . '<option value="running">Running</option>'
        . '<option value="completed">Completed</option>'
        . '<option value="failed">Failed</option>'
        . '<option value="dead_letter">Dead letter</option>'
        . '<option value="cancelled">Cancelled</option>'
        . '</select></label> '
        . '<button id="jobs-select-terminal" type="button">Select terminal shown</button> '
        . '<button id="jobs-clear-selection" type="button">Clear selection</button> '
        . '<button id="jobs-delete-selected" type="button" disabled>Delete selected (0)</button> '
        . '<button id="jobs-delete-matching" type="button">Delete all terminal matching filter</button>'
        . '</div>';
    echo '<p id="jobs-selection-message" class="muted small" aria-live="polite">No jobs selected.</p>';
    echo '<div class="table-wrap"><table><thead><tr>'
        . '<th class="job-select-column"><input id="jobs-select-all" type="checkbox" aria-label="Select all terminal jobs shown"></th>'
        . '<th>ID</th><th>Status</th><th>Type</th><th>File / target</th><th>Progress</th><th data-running-for-column="1">Running for</th><th>Attempts</th><th>Created</th><th>Error</th><th>Actions</th>'
        . '</tr></thead><tbody id="jobs-table-body"><tr><td colspan="11" class="muted">Loading...</td></tr></tbody></table></div>';
    echo '</div></div></section>';

    $jobsScript = __DIR__ . '/assets/background-jobs.js';
    $jobsScriptVersion = is_file($jobsScript) ? (string)filemtime($jobsScript) : '1';
    echo '<script src="assets/background-jobs.js?v=' . catalog_h($jobsScriptVersion) . '"></script>';
    $runningScript = __DIR__ . '/assets/background-jobs-running-controls.js';
    $runningScriptVersion = is_file($runningScript) ? (string)filemtime($runningScript) : '1';
    echo '<script src="assets/background-jobs-running-controls.js?v=' . catalog_h($runningScriptVersion) . '"></script>';
    $staleScript = __DIR__ . '/assets/background-jobs-stale-worker.js';
    $staleScriptVersion = is_file($staleScript) ? (string)filemtime($staleScript) : '1';
    echo '<script src="assets/background-jobs-stale-worker.js?v=' . catalog_h($staleScriptVersion) . '"></script>';
    $pakRerunScript = __DIR__ . '/assets/background-jobs-pak-rerun.js';
    $pakRerunScriptVersion = is_file($pakRerunScript) ? (string)filemtime($pakRerunScript) : '1';
    echo '<script src="assets/background-jobs-pak-rerun.js?v=' . catalog_h($pakRerunScriptVersion) . '"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB background jobs][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Background Jobs Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Background jobs page failed.');
    catalog_foot();
}
