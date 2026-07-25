(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    const workerText = document.getElementById('jobs-worker-state');
    const startButton = document.getElementById('jobs-start');
    const stopButton = document.getElementById('jobs-stop-worker');
    const actionSelect = document.getElementById('jobs-bulk-action');
    const applyButton = document.getElementById('jobs-apply-action');
    const selectionSummary = document.getElementById('jobs-selection-summary');
    const tableBody = document.getElementById('jobs-table-body');
    const tabs = document.getElementById('jobs-status-tabs');
    if (!app || !workerText || !window.fetch) return;

    const queue = app.dataset.queue || 'catalog';
    const statusUrl = app.dataset.workerStatusUrl || 'api/v1/job-worker-status.php';
    let worker = null;
    let applyingWorkerText = false;

    function applyWorkerState() {
        if (!worker) return;
        const authority = String(worker.authoritative_status || 'stopped');
        const counts = worker.queue_counts || {};
        const stale = Boolean(worker.stale_code);
        const processed = parseInt((worker.state || {}).processed || 0, 10) || 0;
        let text;
        if (authority === 'running') {
            text = stale
                ? 'Worker running older code · restart required'
                : 'Worker running · ' + processed + ' processed · ' + String(counts.running || 0) + ' active · ' + String(counts.queued || 0) + ' queued';
        } else if (authority === 'orphaned') {
            text = 'Worker stopped · ' + String(counts.running || 0) + ' orphaned running job(s) · use Stop job or Recover expired leases';
        } else if (authority === 'stopped_with_queue') {
            text = 'Worker stopped · ' + String(counts.queued || 0) + ' queued job(s) waiting';
        } else {
            text = 'Worker stopped · queue empty';
        }
        applyingWorkerText = true;
        workerText.textContent = text;
        workerText.dataset.authoritativeStatus = authority;
        if (startButton) {
            startButton.disabled = authority === 'running' && !stale;
            startButton.textContent = stale ? 'Restart worker' : 'Start / resume queue';
        }
        if (stopButton) stopButton.disabled = authority !== 'running';
        window.setTimeout(function () { applyingWorkerText = false; }, 0);
    }

    async function readWorker() {
        try {
            const response = await fetch(statusUrl + '?' + new URLSearchParams({queue: queue}).toString(), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const body = await response.json();
            if (!response.ok) return;
            worker = body && body.data ? body.data.worker || null : null;
            applyWorkerState();
        } catch (error) {
            // The primary page client reports service errors.
        }
    }

    function statusCounts() {
        const counts = {};
        if (!tabs) return counts;
        tabs.querySelectorAll('[data-status-count]').forEach(function (node) {
            counts[String(node.dataset.statusCount || '')] = parseInt(node.textContent || '0', 10) || 0;
        });
        return counts;
    }

    function selectedStatuses() {
        const statuses = [];
        if (!tableBody) return statuses;
        tableBody.querySelectorAll('tr').forEach(function (row) {
            const checkbox = row.querySelector('input.jobs-row-checkbox');
            if (!checkbox || !checkbox.checked || checkbox.disabled) return;
            const badge = row.querySelector('.job-status');
            if (!badge) return;
            const status = String(badge.textContent || '').trim().toLowerCase().replace(/\s+/g, '_');
            if (status) statuses.push(status);
        });
        return statuses;
    }

    function ensureOption(value, label) {
        if (!actionSelect || actionSelect.querySelector('option[value="' + value + '"]')) return;
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        actionSelect.appendChild(option);
    }

    function enhanceBulkActions() {
        if (!actionSelect || !applyButton || !selectionSummary) return;
        const allMatching = /matching jobs selected/i.test(selectionSummary.textContent || '');
        const counts = statusCounts();
        const selected = selectedStatuses();
        const hasQueued = allMatching ? Number(counts.queued || 0) > 0 : selected.includes('queued');
        const hasRetryable = allMatching
            ? Number(counts.failed || 0) + Number(counts.dead_letter || 0) + Number(counts.cancelled || 0) > 0
            : selected.some(function (status) { return ['failed', 'dead_letter', 'cancelled'].includes(status); });
        const hasTerminal = allMatching
            ? Number(counts.completed || 0) + Number(counts.failed || 0) + Number(counts.dead_letter || 0) + Number(counts.cancelled || 0) > 0
            : selected.some(function (status) { return ['completed', 'failed', 'dead_letter', 'cancelled'].includes(status); });

        if (hasQueued) ensureOption('cancel', allMatching ? 'Cancel matching queued jobs' : 'Cancel selected queued jobs');
        if (hasRetryable) ensureOption('restart', allMatching ? 'Restart matching retryable jobs' : 'Restart selected retryable jobs');
        if (hasTerminal) ensureOption('delete', allMatching ? 'Delete matching terminal jobs' : 'Delete selected terminal jobs');

        const hasActions = actionSelect.querySelectorAll('option[value]:not([value=""])').length > 0;
        actionSelect.disabled = !hasActions;
        applyButton.disabled = !actionSelect.value;
    }

    const workerObserver = new MutationObserver(function () {
        if (!applyingWorkerText && worker) window.setTimeout(applyWorkerState, 0);
    });
    workerObserver.observe(workerText, {childList: true, characterData: true, subtree: true});

    const bulkRoot = document.getElementById('background-jobs-app');
    if (bulkRoot) {
        new MutationObserver(function () { window.setTimeout(enhanceBulkActions, 0); })
            .observe(bulkRoot, {childList: true, subtree: true, characterData: true});
        bulkRoot.addEventListener('change', function () { window.setTimeout(enhanceBulkActions, 0); });
        bulkRoot.addEventListener('click', function () { window.setTimeout(enhanceBulkActions, 0); });
    }

    readWorker();
    enhanceBulkActions();
    window.setInterval(function () {
        if (!document.hidden) readWorker();
        enhanceBulkActions();
    }, 2000);
}());
