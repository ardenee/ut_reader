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
        . '.jobs-worker-state{margin-left:auto;font-weight:600}'
        . '.jobs-worker-state[data-authoritative-status="running"]{color:#a7f3d0}'
        . '.jobs-worker-state[data-authoritative-status="orphaned"]{color:#fecdd3}'
        . '.jobs-worker-state[data-authoritative-status="stopped_with_queue"]{color:#fde68a}'
        . '.jobs-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line);padding-bottom:10px}'
        . '.jobs-tabs button[aria-selected="true"]{font-weight:700;box-shadow:inset 0 -2px 0 currentColor}'
        . '.jobs-search{min-width:260px;flex:1}'
        . '.jobs-selection-summary{min-width:160px}'
        . '.jobs-pagination{justify-content:space-between;margin-top:14px}'
        . '.jobs-page-controls{display:flex;gap:8px;align-items:center}'
        . '.jobs-running-for,.jobs-actions,.jobs-attempts,.jobs-created,.jobs-id{white-space:nowrap}'
        . '.jobs-type,.jobs-target{min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important}'
        . '.jobs-maintenance{margin-top:18px}'
        . '.jobs-maintenance summary{cursor:pointer;font-weight:700}'
        . '.jobs-maintenance-body{padding:14px 0 0}'
        . '.jobs-empty{text-align:center;padding:30px}'
        . '.jobs-row-checkbox{width:18px;height:18px}'
        . '.jobs-table{table-layout:fixed;min-width:1280px}'
        . '.jobs-table .jobs-col-select{width:42px}.jobs-table .jobs-col-id{width:72px}.jobs-table .jobs-col-status{width:118px}'
        . '.jobs-table .jobs-col-type{width:310px}.jobs-table .jobs-col-runtime{width:135px}.jobs-table .jobs-col-attempts{width:82px}'
        . '.jobs-table .jobs-col-created{width:175px}.jobs-table .jobs-col-action{width:110px}'
        . '.jobs-main-row td{vertical-align:top;border-bottom:0;padding-bottom:7px}'
        . '.jobs-main-row.is-running td{background:rgba(246,196,83,.025)}'
        . '.jobs-detail-row td{padding-top:0;border-top:0}'
        . '.jobs-detail-row td::before{display:none}'
        . '.jobs-detail-card{display:grid;grid-template-columns:190px minmax(300px,1fr);gap:14px;align-items:start;padding:8px 12px 11px;border-left:3px solid var(--line2);background:rgba(255,255,255,.018)}'
        . '.jobs-detail-row.is-running .jobs-detail-card{border-left-color:#f6c453;background:rgba(246,196,83,.035)}'
        . '.jobs-detail-progress{display:grid;grid-template-columns:minmax(0,1fr) 44px;gap:8px;align-items:center;white-space:nowrap}'
        . '.jobs-detail-progress progress{width:100%;height:13px}'
        . '.jobs-detail-text strong,.jobs-detail-text span,.jobs-detail-meta span{display:block}'
        . '.jobs-detail-text strong{text-transform:capitalize;margin-bottom:3px}'
        . '.jobs-detail-text span,.jobs-detail-error{overflow-wrap:anywhere}'
        . '.jobs-detail-meta{grid-column:1/-1;text-align:left;font-size:12px}'
        . '.jobs-detail-meta>span:first-child{display:none!important}'
        . '.jobs-detail-error{margin-top:0;color:#fecdd3}'
        . '.job-status{display:inline-block;min-width:84px;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700;text-align:center}'
        . '.job-status-queued,.job-status-running{color:#ffe29a;border-color:rgba(246,196,83,.75);background:rgba(246,196,83,.10)}'
        . '.job-status-completed,.job-status-imported,.job-status-verified,.job-status-alias,.job-status-bucketed,.job-status-decompressed{color:#a7f3d0;border-color:rgba(50,213,131,.75);background:rgba(50,213,131,.10)}'
        . '.job-status-duplicate{color:#bfdbfe;border-color:rgba(96,165,250,.8);background:rgba(96,165,250,.12)}'
        . '.job-status-failed,.job-status-rejected,.job-status-unverified,.job-status-dead_letter,.job-status-cancelled{color:#fecdd3;border-color:rgba(255,107,122,.75);background:rgba(255,107,122,.10)}'
        . '@media(max-width:900px){.jobs-detail-card{grid-template-columns:1fr}.jobs-detail-meta{text-align:left}}'
        . '</style>';

    catalog_page_header(
        'Background Jobs',
        'Each job uses a fixed summary row plus a full-width live status row. Current file time is measured from that job’s own claim time. The worker banner describes the detached queue process separately.',
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
            $label .= ' (' . (int)$summary['running'] . ' active database row, ' . (int)$summary['queued'] . ' queued)';
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
        . '<span id="jobs-worker-state" class="muted jobs-worker-state">Loading authoritative worker status…</span>'
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

    echo '<div class="table-wrap"><table class="jobs-table"><colgroup>'
        . '<col class="jobs-col-select"><col class="jobs-col-id"><col class="jobs-col-status"><col class="jobs-col-type">'
        . '<col><col class="jobs-col-runtime"><col class="jobs-col-attempts"><col class="jobs-col-created"><col class="jobs-col-action">'
        . '</colgroup><thead><tr>'
        . '<th></th><th>ID</th><th>Status</th><th>Type</th><th>File / target</th>'
        . '<th>Current file time</th><th>Attempts</th><th>Created</th><th>Action</th>'
        . '</tr></thead><tbody id="jobs-table-body"><tr class="jobs-empty-row"><td colspan="9" class="jobs-empty muted">Loading…</td></tr></tbody></table></div>';

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
        . '<p class="muted">Use recovery only when the authoritative state reports orphaned running rows or expired leases.</p>'
        . '<p class="button-row">'
        . '<button id="jobs-recover" type="button">Recover orphaned / expired jobs</button>'
        . '<label>Delete terminal jobs older than <select id="jobs-cleanup-days">'
        . '<option value="1">1 day</option><option value="7">7 days</option><option value="30" selected>30 days</option>'
        . '<option value="90">90 days</option><option value="365">1 year</option>'
        . '</select></label>'
        . '<button id="jobs-cleanup" type="button">Clean old jobs</button>'
        . '</p></div></details>';

    echo '</div></div></section>';

    $script = __DIR__ . '/assets/background-jobs-stable.js';
    $version = is_file($script) ? (string)filemtime($script) : '1';
    echo '<script src="assets/background-jobs-stable.js?v=' . catalog_h($version) . '"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB background jobs][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Background Jobs Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Background jobs page failed.');
    catalog_foot();
}
