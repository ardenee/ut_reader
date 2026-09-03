<?php
/**
 * File-centric Background Jobs administrator view.
 *
 * Durable job mechanics stay behind the page. The operator sees one source/file
 * row, its current action/progress and expandable child files/work beneath it.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobQueueSummaryQuery;
use UnrealDb\Catalog\Presentation\Ui\CatalogUi;

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

    $queueOptions = (new PdoBackgroundJobQueueSummaryQuery($db))->all();
    if (!isset($queueOptions[$configuredQueue])) {
        $queueOptions[$configuredQueue] = ['total' => 0, 'queued' => 0, 'running' => 0];
    }

    // Upload Bucket and other specialised work can run on child queues such as
    // catalog:bucket-processing. If no queue was explicitly requested, open the
    // queue that currently has live work instead of always falling back to the
    // configured base queue and making active files appear to have disappeared.
    $queueName = $requestedQueue;
    if ($queueName === '') {
        foreach ($queueOptions as $candidate => $queueSummary) {
            if ((int)($queueSummary['running'] ?? 0) > 0 || (int)($queueSummary['queued'] ?? 0) > 0) {
                $queueName = (string)$candidate;
                break;
            }
        }
    }
    if ($queueName === '') {
        $bucketQueue = $configuredQueue . ':bucket-processing';
        if (isset($queueOptions[$bucketQueue]) && (int)($queueOptions[$bucketQueue]['total'] ?? 0) > 0) {
            $queueName = $bucketQueue;
        }
    }
    if ($queueName === '') {
        $queueName = $configuredQueue;
    }
    if (!isset($queueOptions[$queueName])) {
        $queueOptions[$queueName] = ['total' => 0, 'queued' => 0, 'running' => 0];
    }
    ksort($queueOptions, SORT_NATURAL | SORT_FLAG_CASE);

    $jobStorageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'jobs';

    catalog_head('Background Jobs');
    echo '<style>'
        . '.jobs-file-worker,.jobs-file-filters,.jobs-file-pagination,.jobs-file-maintenance,.jobs-file-bulk{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.jobs-file-worker{margin:0 0 14px}'
        . '.jobs-file-worker-state{margin-left:auto;font-weight:600}'
        . '.jobs-file-queue{margin:0 0 14px}'
        . '.jobs-file-queue select{min-width:260px}'
        . '.jobs-file-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;border-bottom:1px solid var(--line);padding-bottom:10px}'
        . '.jobs-file-tabs button[aria-selected="true"]{font-weight:700;box-shadow:inset 0 -2px 0 currentColor}'
        . '.jobs-file-filters{margin:0 0 12px}'
        . '.jobs-file-search{min-width:280px;flex:1}'
        . '.jobs-file-notice{margin:8px 0 10px}'
        . '.jobs-file-bulk{margin:0 0 10px;min-height:34px}'
        . '.jobs-file-bulk .muted{margin-right:auto}'
        . '.jobs-file-table{table-layout:fixed;min-width:1040px}'
        . '.jobs-file-table .col-select{width:42px}.jobs-file-table .col-id{width:82px}.jobs-file-table .col-size{width:96px}'
        . '.jobs-file-table .col-action{width:360px}.jobs-file-table .col-progress{width:76px}.jobs-file-table .col-status{width:118px}.jobs-file-table .col-control{width:52px}'
        . '.jobs-file-row td{vertical-align:top}'
        . '.jobs-file-row-working td{background:rgba(246,196,83,.025)}'
        . '.jobs-file-row-issue td{background:rgba(255,107,122,.035)}'
        . '.jobs-file-select{text-align:center;vertical-align:middle!important}.jobs-file-select input,.jobs-file-select-all{width:16px;height:16px}'
        . '.jobs-file-id,.jobs-file-size,.jobs-file-control,.jobs-file-progress{white-space:nowrap}'
        . '.jobs-file-tree{display:flex;gap:8px;align-items:flex-start;padding-left:calc(var(--tree-depth,0) * 30px)}'
        . '.jobs-file-type-icon{width:36px;height:36px;flex:0 0 36px;display:block;margin-top:-4px}'
        . '.jobs-file-toggle{width:24px;height:24px;padding:0;line-height:1;flex:0 0 24px}'
        . '.jobs-file-toggle-spacer{display:inline-block;width:24px;flex:0 0 24px}'
        . '.jobs-file-identity{min-width:0}'
        . '.jobs-file-identity strong,.jobs-file-path,.jobs-file-child-count,.jobs-file-action strong,.jobs-file-activity,.jobs-file-issue-text,.jobs-file-type,.jobs-file-result-label{display:block}'
        . '.jobs-file-path,.jobs-file-activity,.jobs-file-issue-text{overflow-wrap:anywhere}'
        . '.jobs-file-child-count,.jobs-file-type,.jobs-file-result-label{font-size:12px;margin-top:3px}'
        . '.jobs-file-issue-text{color:#fecdd3;margin-top:4px;font-size:13px}'
        . '.jobs-file-progress{text-align:right;font-weight:700;vertical-align:middle!important}'
        . '.jobs-file-status{display:inline-block;min-width:86px;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700;text-align:center}'
        . '.jobs-file-status-working{color:#ffe29a;border-color:rgba(246,196,83,.75);background:rgba(246,196,83,.10)}'
        . '.jobs-file-status-completed{color:#a7f3d0;border-color:rgba(50,213,131,.75);background:rgba(50,213,131,.10)}'
        . '.jobs-file-status-issue{color:#fecdd3;border-color:rgba(255,107,122,.75);background:rgba(255,107,122,.10)}'
        . '.jobs-file-status-stopped{color:#cbd5e1;border-color:rgba(148,163,184,.75);background:rgba(148,163,184,.10)}'
        . '.jobs-file-control{text-align:center;vertical-align:middle!important}'
        . '.jobs-file-source-download{margin:auto}'
        . '.jobs-file-empty{text-align:center;padding:32px}'
        . '.jobs-file-more-row td,.jobs-file-loading-row td{background:rgba(255,255,255,.015)}'
        . '.jobs-file-pagination{justify-content:space-between;margin-top:13px}'
        . '.jobs-file-page-controls{display:flex;gap:8px;align-items:center}'
        . '.jobs-file-maintenance-wrap{margin-top:18px}'
        . '.jobs-file-maintenance-wrap summary{cursor:pointer;font-weight:700}'
        . '.jobs-file-maintenance{padding-top:12px}'
        . '@media(max-width:900px){.jobs-file-worker-state{width:100%;margin-left:0}.jobs-file-type-icon{width:30px;height:30px;flex-basis:30px}.jobs-file-tree{padding-left:calc(var(--tree-depth,0) * 24px)}}'
        . '</style>';

    catalog_page_header(
        'Background Jobs',
        'File view: one row per source/file. Expand an archive, UMod or workflow to follow its child files and related work back to the original source.',
        [
            'Upload Issues' => 'upload-issues.php',
            'Upload Bucket' => 'upload-bucket-v2.php',
            'System Operations' => 'system-operations.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    echo '<form method="get" class="jobs-file-queue">'
        . '<label><strong>Queue</strong> <select name="queue" onchange="this.form.submit()">';
    foreach ($queueOptions as $name => $queueSummary) {
        echo '<option value="' . catalog_h($name) . '"' . ($name === $queueName ? ' selected' : '') . '>' . catalog_h($name) . '</option>';
    }
    echo '</select></label></form>';

    echo '<section class="ui-section"><div class="ui-section__body">';
    echo '<div id="background-jobs-app" '
        . 'data-queue="' . catalog_h($queueName) . '" '
        . 'data-tree-url="api/v1/job-file-tree.php" '
        . 'data-run-url="api/v1/job-run.php" '
        . 'data-worker-status-url="api/v1/job-worker-status.php" '
        . 'data-worker-action-url="api/v1/job-worker-action.php" '
        . 'data-action-url="api/v1/job-action.php" '
        . 'data-bulk-url="api/v1/job-bulk.php" '
        . 'data-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';

    echo '<div class="jobs-file-worker">'
        . '<label>Workers <select id="jobs-worker-count">';
    for ($workers = 1; $workers <= 8; $workers++) {
        echo '<option value="' . $workers . '"' . ($workers === 4 ? ' selected' : '') . '>' . $workers . '</option>';
    }
    echo '</select></label>'
        . CatalogUi::button('Apply workers', ['variant' => 'secondary', 'attributes' => ['id' => 'jobs-apply-workers']])
        . CatalogUi::button('Start / resume', ['variant' => 'primary', 'attributes' => ['id' => 'jobs-start']])
        . CatalogUi::button('Stop workers', ['variant' => 'danger', 'attributes' => ['id' => 'jobs-stop-worker']])
        . CatalogUi::button('Refresh', ['variant' => 'secondary', 'attributes' => ['id' => 'jobs-refresh']])
        . '<span id="jobs-worker-state" class="muted jobs-file-worker-state">Loading worker state…</span>'
        . '</div>';

    echo '<nav id="jobs-file-tabs" class="jobs-file-tabs" aria-label="File processing state">';
    foreach ([
        'all' => 'All',
        'working' => 'Working',
        'issue' => 'Issues',
        'completed' => 'Completed',
        'stopped' => 'Stopped',
    ] as $value => $label) {
        echo '<button type="button" data-state="' . $value . '" aria-selected="false">'
            . $label . ' <span data-count>0</span></button>';
    }
    echo '</nav>';

    echo '<div class="jobs-file-filters">'
        . '<label class="jobs-file-search">Search file/job <input id="jobs-file-search" type="search" placeholder="Filename, path, job ID or issue" autocomplete="off"></label>'
        . '<label>Rows <select id="jobs-file-per-page">'
        . '<option value="25">25</option><option value="50">50</option><option value="100" selected>100</option><option value="200">200</option>'
        . '</select></label>'
        . '<a id="jobs-file-export" class="button secondary" href="background-jobs-export.php?queue=' . rawurlencode($queueName) . '">Export</a>'
        . '<a id="jobs-corrupt-export" class="button secondary" href="corrupt-files-export.php?queue=' . rawurlencode($queueName) . '">Export corrupt files</a>'
        . '</div>';

    echo '<div id="jobs-file-notice" class="muted jobs-file-notice">Loading files…</div>';
    echo '<div class="jobs-file-bulk">'
        . '<span id="jobs-selected-count" class="muted">0 selected</span>'
        . CatalogUi::button('Retry selected', [
            'variant' => 'secondary',
            'size' => 'sm',
            'disabled' => true,
            'attributes' => ['id' => 'jobs-retry-selected'],
        ])
        . CatalogUi::button('Retry all matching', [
            'variant' => 'secondary',
            'size' => 'sm',
            'disabled' => true,
            'attributes' => ['id' => 'jobs-retry-all-matching'],
        ])
        . CatalogUi::button('Stop selected', [
            'variant' => 'danger',
            'size' => 'sm',
            'disabled' => true,
            'attributes' => ['id' => 'jobs-stop-selected'],
        ])
        . CatalogUi::button('Delete selected', [
            'variant' => 'danger',
            'size' => 'sm',
            'disabled' => true,
            'attributes' => ['id' => 'jobs-delete-selected'],
        ])
        . CatalogUi::button('Delete all matching', [
            'variant' => 'danger',
            'size' => 'sm',
            'disabled' => true,
            'attributes' => ['id' => 'jobs-delete-all-matching'],
        ])
        . '</div>';

    $table = '<table class="jobs-file-table"><caption class="ui-sr-only">File processing jobs for queue ' . catalog_h($queueName) . '</caption>'
        . '<colgroup><col class="col-select"><col class="col-id"><col><col class="col-size"><col class="col-action"><col class="col-progress"><col class="col-status"><col class="col-control"></colgroup>'
        . '<thead><tr><th class="jobs-file-select"><input id="jobs-select-visible" class="jobs-file-select-all" type="checkbox" aria-label="Select all shown source rows" title="Select all shown source rows"></th>'
        . '<th>Job</th><th>File / source</th><th>Size</th><th>Current action / issue</th><th>Progress</th><th>Status</th><th><span class="ui-sr-only">Download source</span></th></tr></thead>'
        . '<tbody id="jobs-file-body"><tr><td colspan="8" class="jobs-file-empty muted">Loading…</td></tr></tbody></table>';
    echo CatalogUi::tableRegion($table, [
        'id' => 'jobs-file-table-region',
        'label' => 'File processing jobs',
        'focusable' => true,
    ]);

    echo '<div class="jobs-file-pagination">'
        . '<span id="jobs-file-summary" class="muted"></span>'
        . '<div class="jobs-file-page-controls">'
        . CatalogUi::button('First', ['variant' => 'secondary', 'size' => 'sm', 'attributes' => ['id' => 'jobs-file-first']])
        . CatalogUi::button('Previous', ['variant' => 'secondary', 'size' => 'sm', 'attributes' => ['id' => 'jobs-file-previous']])
        . '<span id="jobs-file-page">Page 1 of 1</span>'
        . CatalogUi::button('Next', ['variant' => 'secondary', 'size' => 'sm', 'attributes' => ['id' => 'jobs-file-next']])
        . CatalogUi::button('Last', ['variant' => 'secondary', 'size' => 'sm', 'attributes' => ['id' => 'jobs-file-last']])
        . '</div></div>';

    echo '<details class="jobs-file-maintenance-wrap"><summary>Maintenance</summary>'
        . '<div class="jobs-file-maintenance">'
        . '<button id="jobs-recover" type="button">Recover orphaned jobs</button>'
        . '<label>Delete resolved completed/stopped history older than <select id="jobs-cleanup-days">'
        . '<option value="1">1 day</option><option value="7">7 days</option><option value="30" selected>30 days</option>'
        . '<option value="90">90 days</option><option value="365">1 year</option>'
        . '</select></label>'
        . '<button id="jobs-cleanup" type="button">Queue history cleanup</button>'
        . '<button id="jobs-storage-cleanup" type="button">Clean job storage</button>'
        . '<span class="muted">Job storage: <span class="mono">' . catalog_h($jobStorageRoot) . '</span>. '
        . 'Storage cleanup runs in the background and removes orphaned prepared, incoming, chunked-upload, batch, event, working and lock artifacts while retaining live/problem sources.</span>'
        . '</div></details>';

    echo '</div></div></section>';

    $script = __DIR__ . '/assets/background-jobs-files.js';
    $version = is_file($script) ? (string)filemtime($script) : '1';
    echo '<script src="assets/background-jobs-files.js?v=' . catalog_h($version) . '"></script>';
    $iconScript = __DIR__ . '/assets/background-jobs-file-icons.js';
    $iconVersion = is_file($iconScript) ? (string)filemtime($iconScript) : '1';
    echo '<script src="assets/background-jobs-file-icons.js?v=' . catalog_h($iconVersion) . '"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB background jobs][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Background Jobs Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Background jobs page failed.');
    catalog_foot();
}