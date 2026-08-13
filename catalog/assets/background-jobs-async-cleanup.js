(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    const notice = document.getElementById('jobs-message') || document.getElementById('job-notice');
    if (!root || !notice || typeof window.fetch !== 'function') {
        return;
    }

    const bulkUrl = String(root.dataset.bulkUrl || '');
    const actionUrl = String(root.dataset.actionUrl || '');
    const workerStatusUrl = String(root.dataset.workerStatusUrl || '');
    const workerState = document.getElementById('jobs-worker-state');
    const statusTabs = document.getElementById('jobs-status-tabs');
    const queueSelect = document.querySelector('.jobs-queue-switcher select[name="queue"]');
    const originalFetch = window.fetch.bind(window);
    let pendingNotice = '';
    let latestWorker = null;

    // The queue selector is navigation, not telemetry. Its old server-rendered
    // counts became stale immediately while the live status/tabs continued to
    // refresh, making several perfectly valid but different scopes look broken.
    if (queueSelect) {
        Array.from(queueSelect.options || []).forEach((option) => {
            const queueName = String(option.value || '').trim();
            if (queueName !== '') {
                option.textContent = queueName;
            }
        });
        queueSelect.title = 'Queue selector. Live work-unit counts are shown in the worker status line.';
    }

    // Tabs intentionally suppress routine workflow children. Label that scope so
    // their counts are never mistaken for the raw durable queue-unit counts.
    if (statusTabs && !document.getElementById('jobs-operator-view-label')) {
        const label = document.createElement('p');
        label.id = 'jobs-operator-view-label';
        label.className = 'muted';
        label.style.margin = '0 0 8px';
        label.textContent = 'Operator view — parent workflows plus child units requiring attention; routine child units are folded into their parent.';
        statusTabs.parentNode.insertBefore(label, statusTabs);
    }

    const requestAction = (init) => {
        try {
            const body = init && typeof init.body === 'string' ? JSON.parse(init.body) : null;
            return body && typeof body === 'object' ? String(body.action || '') : '';
        } catch (_) {
            return '';
        }
    };

    const responseData = async (response) => {
        try {
            const body = await response.clone().json();
            return body && typeof body === 'object' && body.data && typeof body.data === 'object'
                ? body.data
                : null;
        } catch (_) {
            return null;
        }
    };

    const workerSummary = () => {
        const worker = latestWorker && typeof latestWorker === 'object' ? latestWorker : null;
        if (!worker) {
            return '';
        }
        const counts = worker.queue_counts && typeof worker.queue_counts === 'object'
            ? worker.queue_counts
            : {};
        const authority = String(worker.authoritative_status || (worker.active ? 'running' : 'stopped'));
        const active = Math.max(0, Number(worker.active_count || 0));
        const desired = Math.max(1, Number(worker.desired_count || active || 1));
        const processed = Math.max(0, Number(worker.active_processed != null
            ? worker.active_processed
            : ((worker.state || {}).processed || 0)));
        const running = Math.max(0, Number(counts.running || 0));
        const queued = Math.max(0, Number(counts.queued || 0));
        const ready = Math.max(0, Number(counts.ready || 0));
        const delayed = Math.max(0, queued - ready);

        let processText;
        if (authority === 'running') {
            processText = 'Workers ' + active + '/' + desired + ' running';
        } else if (authority === 'orphaned') {
            processText = 'Workers stopped · ' + running + ' orphaned running unit' + (running === 1 ? '' : 's');
        } else if (authority === 'stopped_with_queue') {
            processText = 'Workers stopped';
        } else {
            processText = 'Workers stopped';
        }

        const unitText = 'Work units: ' + running + ' running · ' + queued + ' queued'
            + (queued > 0 ? ' (' + ready + ' available now' + (delayed > 0 ? ', ' + delayed + ' deferred' : '') + ')' : '');
        const processedText = authority === 'running' ? ' · ' + processed + ' completed this worker session' : '';
        return processText + processedText + ' · ' + unitText;
    };

    const renderReadableWorkerSummary = () => {
        if (!workerState) {
            return;
        }
        const text = workerSummary();
        if (text !== '' && workerState.textContent !== text) {
            workerState.textContent = text;
        }
    };

    if (workerState) {
        const workerObserver = new MutationObserver(renderReadableWorkerSummary);
        workerObserver.observe(workerState, {childList: true, characterData: true, subtree: true});
    }

    window.fetch = async (input, init) => {
        const response = await originalFetch(input, init);
        if (!response.ok) {
            return response;
        }

        const url = typeof input === 'string'
            ? input
            : (input && typeof input.url === 'string' ? input.url : '');
        const action = requestAction(init);

        // Reuse the status request the established client already makes. Do not
        // add another polling request merely to present the same queue counts more
        // clearly.
        if (workerStatusUrl !== '' && url.includes(workerStatusUrl)) {
            const data = await responseData(response);
            latestWorker = data && data.worker && typeof data.worker === 'object' ? data.worker : null;
            queueMicrotask(renderReadableWorkerSummary);
        }

        const isBulkDelete = bulkUrl !== '' && url.includes(bulkUrl) && action === 'delete';
        const isRetentionCleanup = actionUrl !== '' && url.includes(actionUrl) && action === 'cleanup';
        if (!isBulkDelete && !isRetentionCleanup) {
            return response;
        }

        const data = await responseData(response);
        const cleanupJobId = Number(data && data.cleanup_job_id || 0);
        if (cleanupJobId < 1) {
            return response;
        }

        const scheduled = Number(data && data.scheduled || 0);
        const requested = Number(data && data.requested || scheduled);
        const limited = Boolean(data && data.limited);
        const workerError = String(data && data.worker_error || '').trim();
        pendingNotice = 'Queued background-job cleanup #' + cleanupJobId
            + ' for ' + scheduled + ' terminal job(s)'
            + (requested > scheduled ? ' from ' + requested + ' matching job(s)' : '')
            + '. Actual deleted/skipped/staged-file counts will be reported by the cleanup job.'
            + (limited ? ' The 10,000-job snapshot limit was reached; run cleanup again after it completes for any remainder.' : '')
            + (workerError !== '' ? ' Worker start warning: ' + workerError : '');

        return response;
    };

    // The established client writes its historical synchronous notice immediately
    // after the fetch resolves. Replace only that next cleanup notice; all other
    // job messages remain owned by background-jobs-stable.js.
    const observer = new MutationObserver(() => {
        if (pendingNotice === '') {
            return;
        }
        const text = String(notice.textContent || '');
        if (text.startsWith('Delete affected ') || text.startsWith('Removed ')) {
            notice.textContent = pendingNotice;
            pendingNotice = '';
        }
    });
    observer.observe(notice, {childList: true, characterData: true, subtree: true});
})();
