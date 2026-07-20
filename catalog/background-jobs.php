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

    $queueName = trim((string)($_GET['queue'] ?? ($config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80) {
        $queueName = 'catalog';
    }

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
        'SELECT status,COUNT(*) total FROM ue_background_jobs WHERE queue_name=? GROUP BY status',
        [$queueName]
    ) as $row) {
        $status = (string)$row['status'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int)$row['total'];
        }
    }

    catalog_head('Background Jobs');
    catalog_page_header(
        'Background Jobs',
        'View and control queued uploads, imports, dependency rebuilds, maintenance and package-generation work without using SSH.',
        [
            'Upload Files' => 'profiled-upload.php',
            'PAK Import' => 'pak-import.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    echo '<div class="grid">';
    catalog_stat_card('Queued', $counts['queued'], '', $counts['queued'] > 0 ? 'attention' : '');
    catalog_stat_card('Running', $counts['running'], '', $counts['running'] > 0 ? 'attention' : '');
    catalog_stat_card('Completed', $counts['completed'], '', 'good');
    catalog_stat_card('Failed', $counts['failed'] + $counts['dead_letter'], '', ($counts['failed'] + $counts['dead_letter']) > 0 ? 'warning' : '');
    catalog_stat_card('Cancelled', $counts['cancelled']);
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Queue controls</h2>';
    echo '<p class="muted">Start next launches a detached CLI worker for one job. Start queued launches a detached worker that drains the available queue and exits. The worker continues after this page or browser is closed.</p>';
    echo '</div></div><div class="ui-section__body">';
    echo '<div id="background-jobs-app" '
        . 'data-queue="' . catalog_h($queueName) . '" '
        . 'data-status-url="api/v1/job-status.php" '
        . 'data-action-url="api/v1/job-action.php" '
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
    echo '<p id="jobs-worker-message" class="muted" aria-live="polite">Loading worker status...</p>';
    echo '<p id="jobs-message" class="muted" aria-live="polite">Loading queue...</p>';
    echo '<p><label>Status filter <select id="jobs-status-filter">'
        . '<option value="">All statuses</option>'
        . '<option value="queued">Queued</option>'
        . '<option value="running">Running</option>'
        . '<option value="completed">Completed</option>'
        . '<option value="failed">Failed</option>'
        . '<option value="dead_letter">Dead letter</option>'
        . '<option value="cancelled">Cancelled</option>'
        . '</select></label></p>';
    echo '<div class="table-wrap"><table><thead><tr>'
        . '<th>ID</th><th>Status</th><th>Type</th><th>File / target</th><th>Progress</th><th>Attempts</th><th>Created</th><th>Error</th><th>Actions</th>'
        . '</tr></thead><tbody id="jobs-table-body"><tr><td colspan="9" class="muted">Loading...</td></tr></tbody></table></div>';
    echo '</div></div></section>';

    echo '<script src="assets/background-jobs.js"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB background jobs][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Background Jobs Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Background jobs page failed.');
    catalog_foot();
}
