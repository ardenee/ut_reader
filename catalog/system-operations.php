<?php
/**
 * Single-host production operations console.
 *
 * The page is intentionally read-only. It exposes the information a solo
 * maintainer needs to decide whether to inspect workers, resource limits,
 * errors, storage or the database without requiring PowerShell/SQL first.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Jobs\CatalogWorkerStatusPolicy;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobResourceLimitStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoSystemOperationsQuery;
use UnrealDb\Catalog\Infrastructure\Storage\LocalFilesystemPackageStorage;
use UnrealDb\Catalog\Presentation\Ui\CatalogUi;

function catalog_operations_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $seconds -= $days * 86400;
    $hours = intdiv($seconds, 3600);
    $seconds -= $hours * 3600;
    $minutes = intdiv($seconds, 60);
    $seconds -= $minutes * 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($days > 0 || $hours > 0) $parts[] = $hours . 'h';
    if ($days > 0 || $hours > 0 || $minutes > 0) $parts[] = $minutes . 'm';
    $parts[] = $seconds . 's';
    return implode(' ', $parts);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('System Operations')) {
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $query = new PdoSystemOperationsQuery($db);
    $database = $query->database();
    $queues = $query->queues();

    $launcher = new CatalogDetachedWorker($config);
    $worker = $launcher->status($queueName, false);

    // Worker-health policy intentionally uses durable execution-row counts. The
    // operator-visible job totals below use the Background Jobs rolled-up scope.
    $operational = new PdoBackgroundJobOperationalQuery($db, $config);
    $workerQueueCounts = $operational->queueCounts($queueName);
    $workerStatus = CatalogWorkerStatusPolicy::evaluate(
        $worker,
        $workerQueueCounts,
        $launcher->configuredWorkerCount()
    );

    $limits = (new CatalogJobResourceLimitStore($db, $queueName))->summaries();
    $storage = (new LocalFilesystemPackageStorage(
        (string)$config['storage_path'],
        __DIR__
    ))->health();

    $activeWorkers = max(0, (int)($worker['active_count'] ?? 0));
    $desiredWorkers = max(1, (int)($worker['desired_count'] ?? $launcher->configuredWorkerCount()));
    $workerAuthority = trim((string)($workerStatus['authoritative_status'] ?? 'stopped')) ?: 'stopped';
    $workerMessage = trim((string)($workerStatus['authoritative_message'] ?? ''));

    $queueCurrent = null;
    foreach ($queues as $queue) {
        if ($queue['queue'] === $queueName) {
            $queueCurrent = $queue;
            break;
        }
    }
    $queueCurrent ??= [
        'queue' => $queueName,
        'total' => 0,
        'queued' => 0,
        'running' => 0,
        'completed' => 0,
        'failed' => 0,
        'dead_letter' => 0,
        'cancelled' => 0,
        'oldest_queued_seconds' => 0,
        'longest_running_seconds' => 0,
        'concurrency_blocked' => 0,
    ];

    $limitingClasses = 0;
    foreach ($limits as $row) {
        if (!empty($row['is_limiting'])) {
            $limitingClasses++;
        }
    }
    $attentionJobs = (int)$queueCurrent['failed'] + (int)$queueCurrent['dead_letter'];

    $freePercent = null;
    if (($storage['total_bytes'] ?? null) !== null && (int)$storage['total_bytes'] > 0 && ($storage['free_bytes'] ?? null) !== null) {
        $freePercent = ((int)$storage['free_bytes'] / (int)$storage['total_bytes']) * 100;
    }

    catalog_head('System Operations');
    echo CatalogUi::pageHeader(
        'System Operations',
        'Read-only production health for this UnrealDB host. Job totals use the same rolled-up operator scope as Background Jobs, so routine workflow children do not inflate the headline numbers.',
        [
            'Background Jobs' => 'background-jobs.php',
            'Resource Limits' => 'job-resource-limits.php',
            'System Errors' => 'system-errors.php',
            'Performance Readiness' => 'performance-readiness.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    echo '<div class="grid">';
    catalog_stat_card(
        'Worker processes',
        $activeWorkers . '/' . $desiredWorkers,
        $workerMessage !== '' ? $workerMessage : ucfirst(str_replace('_', ' ', $workerAuthority)),
        $workerAuthority === 'running' ? 'good' : ((int)$queueCurrent['queued'] > 0 ? 'warning' : '')
    );
    catalog_stat_card('Queued jobs', number_format((int)$queueCurrent['queued']), 'Operator-visible jobs waiting', (int)$queueCurrent['queued'] > 0 ? 'attention' : 'good');
    catalog_stat_card('Running jobs', number_format((int)$queueCurrent['running']), 'Operator-visible jobs in progress', (int)$queueCurrent['running'] > 0 ? 'good' : '');
    catalog_stat_card('Needs attention', number_format($attentionJobs), 'Failed or retry-required jobs', $attentionJobs > 0 ? 'warning' : 'good');
    catalog_stat_card('Resource limits', $limitingClasses > 0 ? number_format($limitingClasses) . ' limiting' : 'All clear', 'Execution capacity by workload class', $limitingClasses > 0 ? 'warning' : 'good');
    catalog_stat_card('Concurrency blocked', number_format((int)$queueCurrent['concurrency_blocked']), 'Distinct jobs waiting on an identical target', (int)$queueCurrent['concurrency_blocked'] > 0 ? 'attention' : 'good');
    catalog_stat_card('Oldest queued job', catalog_operations_duration((int)$queueCurrent['oldest_queued_seconds']), 'Age only; never an automatic timeout', (int)$queueCurrent['oldest_queued_seconds'] > 3600 ? 'warning' : '');
    catalog_stat_card('Longest running job', catalog_operations_duration((int)$queueCurrent['longest_running_seconds']), 'Operator visibility only; jobs are not failed by age', '');
    catalog_stat_card('Database', catalog_bytes((int)$database['size_bytes']), (string)$database['database'], '');
    catalog_stat_card('Verified files', number_format((int)$database['verified_files']), number_format((int)$database['files']) . ' total catalog rows', '');
    catalog_stat_card('Storage free', ($storage['free_bytes'] ?? null) !== null ? catalog_bytes((int)$storage['free_bytes']) : 'Unknown', $freePercent !== null ? number_format($freePercent, 1) . '% free' : '', $freePercent !== null && $freePercent < 10 ? 'warning' : 'good');
    echo '</div>';

    $queueRows = '';
    foreach ($queues as $row) {
        $queueRows .= '<tr>'
            . '<td class="mono">' . catalog_h($row['queue']) . '</td>'
            . '<td>' . number_format((int)$row['queued']) . '</td>'
            . '<td>' . number_format((int)$row['running']) . '</td>'
            . '<td>' . number_format((int)$row['failed']) . '</td>'
            . '<td>' . number_format((int)$row['dead_letter']) . '</td>'
            . '<td>' . catalog_h(catalog_operations_duration((int)$row['oldest_queued_seconds'])) . '</td>'
            . '<td>' . catalog_h(catalog_operations_duration((int)$row['longest_running_seconds'])) . '</td>'
            . '<td>' . number_format((int)$row['concurrency_blocked']) . '</td>'
            . '</tr>';
    }
    if ($queueRows === '') {
        $queueRows = '<tr><td colspan="8" class="muted">No current jobs require operational reporting.</td></tr>';
    }
    $queueTable = '<table><caption class="ui-sr-only">Operator-visible background job summary</caption>'
        . '<thead><tr><th scope="col">Queue</th><th scope="col">Queued jobs</th><th scope="col">Running jobs</th><th scope="col">Failed</th><th scope="col">Needs retry</th><th scope="col">Oldest queued</th><th scope="col">Longest running</th><th scope="col">Concurrency blocked</th></tr></thead>'
        . '<tbody>' . $queueRows . '</tbody></table>';
    echo CatalogUi::section(CatalogUi::tableRegion($queueTable, ['label' => 'Operator-visible queue summary']), [
        'title' => 'Queue pressure',
        'description' => 'One reported row represents one operator-visible job. Routine workflow children stay folded into their parent; failed or retry-required children remain visible because they need direct action. Runtime ages are diagnostic only and never trigger automatic failure or stealing.',
    ]);

    $limitRows = '';
    foreach ($limits as $row) {
        $blocked = max(0, (int)($row['class_blocked'] ?? 0));
        $capacityBadge = $blocked > 0
            ? CatalogUi::statusBadge('queued', ['label' => 'At capacity'])
            : CatalogUi::statusBadge('ready', ['label' => 'Available']);
        $limitRows .= '<tr>'
            . '<td>' . catalog_h((string)($row['label'] ?? $row['resource_class'])) . '</td>'
            . '<td class="mono">' . catalog_h((string)$row['resource_class']) . '</td>'
            . '<td>' . (int)$row['limit'] . '</td>'
            . '<td>' . (int)$row['running'] . '</td>'
            . '<td>' . (int)$row['available_slots'] . '</td>'
            . '<td>' . $capacityBadge . '</td>'
            . '</tr>';
    }
    $limitsTable = '<table><caption class="ui-sr-only">Job execution capacity by resource class</caption>'
        . '<thead><tr><th scope="col">Workload</th><th scope="col">Class</th><th scope="col">Limit</th><th scope="col">Running slots</th><th scope="col">Free slots</th><th scope="col">State</th></tr></thead>'
        . '<tbody>' . $limitRows . '</tbody></table>';
    echo CatalogUi::section(CatalogUi::tableRegion($limitsTable, ['label' => 'Job execution capacity']), [
        'title' => 'Resource limits',
        'description' => 'Capacity is shown as execution slots, not as another job total. Limits are evaluated when workers consider runnable queue rows; the job totals above remain the operator-facing source of truth.',
        'actions' => ['Change limits' => 'job-resource-limits.php'],
    ]);

    $storageReady = !empty($storage['available']) && !empty($storage['readable']) && !empty($storage['writable']);
    $storageStatus = CatalogUi::statusBadge($storageReady ? 'ready' : 'not_ready');
    $storageTable = '<table><caption class="ui-sr-only">Database and package storage health</caption><tbody>'
        . '<tr><th scope="row">MySQL version</th><td class="mono">' . catalog_h((string)$database['version']) . '</td></tr>'
        . '<tr><th scope="row">Database size</th><td>' . catalog_h(catalog_bytes((int)$database['size_bytes'])) . '</td></tr>'
        . '<tr><th scope="row">Package storage</th><td>' . $storageStatus . '</td></tr>'
        . '<tr><th scope="row">Storage root</th><td class="mono path">' . catalog_h((string)$storage['path']) . '</td></tr>'
        . '<tr><th scope="row">Capacity</th><td>' . (($storage['total_bytes'] ?? null) !== null ? catalog_h(catalog_bytes((int)$storage['total_bytes'])) : 'Unknown') . '</td></tr>'
        . '<tr><th scope="row">Free</th><td>' . (($storage['free_bytes'] ?? null) !== null ? catalog_h(catalog_bytes((int)$storage['free_bytes'])) : 'Unknown') . '</td></tr>'
        . '</tbody></table>';
    echo CatalogUi::section(CatalogUi::tableRegion($storageTable, ['label' => 'Database and package storage health']), [
        'title' => 'Database and storage',
        'description' => 'The package store remains local disk on this single-host deployment; this page checks the exact configured production root.',
    ]);

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB system operations][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('System Operations Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'System operations could not be loaded.');
    catalog_foot();
}
