<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

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
        foreach ($queueOptions as $candidate => $summary) {
            if (($summary['queued'] ?? 0) > 0 || ($summary['running'] ?? 0) > 0) {
                $queueName = $candidate;
                break;
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

    catalog_head('Background Jobs');
    echo '<style>'
        . '.jobs-queue-switcher{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px}'
        . '.jobs-queue-switcher select{min-width:340px}'
        . '.jobs-toolbar,.jobs-filterbar,.jobs-selectionbar,.jobs-pagination{display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
        . '.jobs-toolbar,.jobs-filterbar,.jobs-selectionbar{margin:0 0 14px}'
        . '.jobs-worker-state{margin-left:auto}'
        . '.jobs-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line);padding-bottom:10px}'
        . '.jobs-tabs button[aria-selected="true"]{font-weight:700;box-shadow:inset 0 -2px 0 currentColor}'
        . '.jobs-search{min-width:260px;flex:1}'
        . '.jobs-selection-summary{min-width:160px}'
        . '.jobs-pagination{justify-content:space-between;margin-top:14px}'
        . '.jobs-page-controls{display:flex;gap:8px;align-items:center}'
        . '.jobs-running-for{white-space:nowrap}'
        . '.jobs-actions{white-space:nowrap}'
        . '.jobs-maintenance{margin-top:18px}'
        . '.jobs-maintenance summary{cursor:pointer;font-weight:700}'
        . '.jobs-maintenance-body{padding:14px 0 0}'
        . '.jobs-empty{text-align:center;padding:30px}'
        . '.jobs-row-checkbox{width:18px;height:18px}'
        . '</style>';

    catalog_page_header(
        'Background Jobs',
        'Manage queued work, inspect long-running jobs and apply actions to selected jobs or every matching result.',
        [
            'Upload Bucket' => 'upload-bucket.php',
            'Upload Files' => 'profiled-upload.php',
            'PAK Import' => 'pak-import.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    echo '<form method="get" class="jobs-queue-switcher">'
        . '<label><strong>Queue</strong> <select name="queue" onchange="this.form.submit()">';
    foreach ($queueOptions as $name => $summary) {
        $label = $name . ' — ' . (int)$summary['total'] . ' jobs';
        if ((int)$summary['running'] > 0 || (int)$summary['queued'] > 0) {
            $label .= ' (' . (int)$summary['running'] . ' running, ' . (int)$summary['queued'] . ' queued)';
        }
        echo '<option value="' . catalog_h($name) . '"' . ($name === $queueName ? ' selected' : '') . '>'
            . catalog_h($label) . '</option>';
    }
    echo '</select></label><noscript><button type="submit">Open queue</button></noscript></form>';

    echo '<section class="ui-section"><div class="ui-section__body">';
    echo '<div id="background-jobs-app" '
        . 'data-queue="' . catalog_h($queueName) . '" '
        . 'data-status-url="api/v1/job-status.php" '
        . 'data-bulk-url="api/v1/job-bulk.php" '
        . 'data-action-url="api/v1/job-action.php" '
        . 'data-run-url="api/v1/job-run.php" '
        . 'data-worker-status-url="api/v1/job-worker-status.php" '
        . 'data-worker-action-url="api/v1/job-worker-action.php" '
        . 'data-pak-rerun-url="api/v1/job-rerun-pak.php" '
        . 'data-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';

    echo '<div class="jobs-toolbar">'
        . '<button id="jobs-start" type="button">Start / resume queue</button>'
        . '<button id="jobs-stop-worker" type="button">Stop worker</button>'
        . '<button id="jobs-refresh" type="button">Refresh</button>'
        . '<span id="jobs-worker-state" class="muted jobs-worker-state">Loading worker status…</span>'
        . '</div>';

    echo '<nav id="jobs-status-tabs" class="jobs-tabs" aria-label="Job status">';
    foreach ([
        '' => 'All',
        'queued' => 'Queued',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'dead_letter' => 'Dead letter',
        'cancelled' => 'Cancelled',
    ] as $value => $label) {
        echo '<button type="button" data-status="' . catalog_h($value) . '" aria-selected="false">'
            . catalog_h($label) . ' <span data-status-count="' . catalog_h($value !== '' ? $value : 'all') . '">0</span>'
            . '</button>';
    }
    echo '</nav>';

    echo '<div class="jobs-filterbar">'
        . '<label class="jobs-search">Search <input id="jobs-search" type="search" placeholder="File, job ID, type or error" autocomplete="off"></label>'
        . '<label>Rows <select id="jobs-page-size">'
        . '<option value="50">50</option><option value="100" selected>100</option><option value="250">250</option>'
        . '<option value="500">500</option><option value="1000">1000</option>'
        . '</select></label>'
        . '</div>';

    echo '<div class="jobs-selectionbar">'
        . '<label><input id="jobs-select-page" type="checkbox" class="jobs-row-checkbox"> Select page</label>'
        . '<span id="jobs-selection-summary" class="jobs-selection-summary muted">Nothing selected</span>'
        . '<button id="jobs-select-matching" type="button">Select all matching</button>'
        . '<button id="jobs-clear-selection" type="button" disabled>Clear selection</button>'
        . '<label>Action <select id="jobs-bulk-action"><option value="">Choose action</option></select></label>'
        . '<button id="jobs-apply-action" type="button" disabled>Apply</button>'
        . '</div>';

    echo '<p id="jobs-message" class="muted" aria-live="polite">Loading jobs…</p>';

    echo '<div class="table-wrap"><table><thead><tr>'
        . '<th></th><th>ID</th><th>Status</th><th>Type</th><th>File / target</th><th>Progress</th>'
        . '<th>Running for</th><th>Attempts</th><th>Created</th><th>Error</th><th>Action</th>'
        . '</tr></thead><tbody id="jobs-table-body"><tr><td colspan="11" class="jobs-empty muted">Loading…</td></tr></tbody></table></div>';

    echo '<div class="jobs-pagination">'
        . '<span id="jobs-page-summary" class="muted"></span>'
        . '<div class="jobs-page-controls">'
        . '<button id="jobs-first-page" type="button">First</button>'
        . '<button id="jobs-previous-page" type="button">Previous</button>'
        . '<span id="jobs-page-label">Page 1 of 1</span>'
        . '<button id="jobs-next-page" type="button">Next</button>'
        . '<button id="jobs-last-page" type="button">Last</button>'
        . '</div></div>';

    echo '<details class="jobs-maintenance"><summary>Maintenance</summary><div class="jobs-maintenance-body">'
        . '<p class="muted">These are occasional repair and cleanup operations, not normal queue controls.</p>'
        . '<p class="button-row">'
        . '<button id="jobs-recover" type="button">Recover expired leases</button>'
        . '<label>Delete terminal jobs older than <select id="jobs-cleanup-days">'
        . '<option value="1">1 day</option><option value="7">7 days</option><option value="30" selected>30 days</option>'
        . '<option value="90">90 days</option><option value="365">1 year</option>'
        . '</select></label>'
        . '<button id="jobs-cleanup" type="button">Clean old jobs</button>'
        . '</p></div></details>';

    echo '</div></div></section>';

    $script = __DIR__ . '/assets/background-jobs.js';
    $version = is_file($script) ? (string)filemtime($script) : '1';
    echo '<script src="assets/background-jobs.js?v=' . catalog_h($version) . '"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB background jobs][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Background Jobs Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Background jobs page failed.');
    catalog_foot();
}
