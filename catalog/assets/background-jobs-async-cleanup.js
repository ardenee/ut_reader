(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    const notice = document.getElementById('jobs-message') || document.getElementById('job-notice');
    if (!root || !notice || typeof window.fetch !== 'function') {
        return;
    }

    const bulkUrl = String(root.dataset.bulkUrl || '');
    const actionUrl = String(root.dataset.actionUrl || '');
    const statusUrl = String(root.dataset.statusUrl || '');
    const workerStatusUrl = String(root.dataset.workerStatusUrl || '');
    const legacyWorkerState = document.getElementById('jobs-worker-state');
    const statusTabs = document.getElementById('jobs-status-tabs');
    const queueSelect = document.querySelector('.jobs-queue-switcher select[name="queue"]');
    const originalFetch = window.fetch.bind(window);
    let pendingNotice = '';

    // The queue selector is navigation, not telemetry. Its old server-rendered
    // counts became stale immediately while the live status/tabs continued to
    // refresh, making several valid but different scopes look broken.
    if (queueSelect) {
        Array.from(queueSelect.options || []).forEach((option) => {
            const queueName = String(option.value || '').trim();
            if (queueName !== '') {
                option.textContent = queueName;
            }
        });
        queueSelect.title = 'Queue selector. Live work-unit counts are shown in the worker summary.';
    }

    // Tabs are deliberately a folded workflow/operator view. Make that scope
    // part of every caption so e.g. "Queued 106" cannot be confused with the
    // hundreds of thousands of queued child work units shown above.
    if (statusTabs) {
        const labels = {
            '': 'All workflows',
            queued: 'Queued workflows',
            running: 'Running workflows',
            completed: 'Completed workflows',
            failed: 'Failed workflows',
            dead_letter: 'Dead-letter workflows',
            cancelled: 'Cancelled workflows'
        };
        statusTabs.querySelectorAll('button[data-status]').forEach((button) => {
            const value = String(button.dataset.status || '');
            const count = button.querySelector('[data-status-count]');
            if (!count || !Object.prototype.hasOwnProperty.call(labels, value)) {
                return;
            }
            button.replaceChildren(document.createTextNode(labels[value] + ' '), count);
        });
    }

    if (statusTabs && !document.getElementById('jobs-operator-view-label')) {
        const label = document.createElement('p');
        label.id = 'jobs-operator-view-label';
        label.className = 'muted';
        label.style.margin = '0 0 8px';
        label.textContent = 'Workflow view — routine child work units are folded into their parent; the counters below are workflow/operator rows, not queue work-unit totals.';
        statusTabs.parentNode.insertBefore(label, statusTabs);
        statusTabs.title = 'Workflow/operator-row counts. Raw work-unit counts are shown in the worker summary above.';
    }

    // The established clients still own the hidden legacy banner because they use
    // it for status/control state. Present one separate read-only summary instead
    // of allowing stable.js and cursor-bridge.js to compete visibly over wording.
    let readableWorkerState = document.getElementById('jobs-worker-summary-readable');
    if (legacyWorkerState) {
        legacyWorkerState.hidden = true;
        legacyWorkerState.setAttribute('aria-hidden', 'true');
        if (!readableWorkerState) {
            readableWorkerState = document.createElement('span');
            readableWorkerState.id = 'jobs-worker-summary-readable';
            readableWorkerState.className = 'muted jobs-worker-state';
            readableWorkerState.textContent = 'Loading worker and queue state…';
            legacyWorkerState.parentNode.insertBefore(readableWorkerState, legacyWorkerState);
        }
    }

    // cursor-bridge.js also injects a second "Pool x/y active · max n" label.
    // It is useful internally for its controls but redundant once the readable
    // summary above includes the process pool.
    const hideDuplicatePoolState = () => {
        const poolState = document.getElementById('jobs-worker-pool-state');
        if (poolState) {
            poolState.hidden = true;
            poolState.setAttribute('aria-hidden', 'true');
        }
    };
    hideDuplicatePoolState();
    const toolbar = root.querySelector('.jobs-toolbar');
    if (toolbar && typeof MutationObserver !== 'undefined') {
        const toolbarObserver = new MutationObserver(hideDuplicatePoolState);
        toolbarObserver.observe(toolbar, {childList: true, subtree: true});
    }

    const requestAction = (init) => {
        try {
            const body = init && typeof init.body === 'string' ? JSON.parse(init.body) : null;
            return body && typeof body === 'object' ? String(body.action || '') : '';
        } catch (_) {
            return '';
        }
    };

    const responseBody = async (response) => {
        try {
            return await response.clone().json();
        } catch (_) {
            return null;
        }
    };

    const responseData = async (response) => {
        const body = await responseBody(response);
        return body && typeof body === 'object' && body.data && typeof body.data === 'object'
            ? body.data
            : null;
    };

    const replacementResponse = (response, body) => {
        const headers = new Headers(response.headers);
        headers.set('Content-Type', 'application/json');
        headers.delete('Content-Length');
        return new Response(JSON.stringify(body), {
            status: response.status,
            statusText: response.statusText,
            headers: headers
        });
    };

    // A live running workflow can transition after the short aggregate-count
    // cache was populated. Never return meta.total=0 alongside an actual running
    // workflow row; that was the source of "Running 0" plus a visible running job.
    const reconcileLiveRunningWorkflow = async (response, url) => {
        if (statusUrl === '' || !url.includes(statusUrl)) {
            return response;
        }
        let parsedUrl;
        try {
            parsedUrl = new URL(url, window.location.href);
        } catch (_) {
            return response;
        }
        if (String(parsedUrl.searchParams.get('status') || '').toLowerCase() !== 'running') {
            return response;
        }
        const body = await responseBody(response);
        if (!body || !body.data || !Array.isArray(body.data.jobs) || !body.meta) {
            return response;
        }
        const liveRunning = body.data.jobs.filter((job) => String(job && job.status || '').toLowerCase() === 'running').length;
        if (liveRunning < 1 || Number(body.meta.total || 0) >= liveRunning) {
            return response;
        }
        body.meta.total = liveRunning;
        body.meta.pages = Math.max(1, Math.ceil(liveRunning / Math.max(1, Number(body.meta.per_page || 100))));
        if (!body.meta.counts || typeof body.meta.counts !== 'object') {
            body.meta.counts = {};
        }
        body.meta.counts.running = Math.max(liveRunning, Number(body.meta.counts.running || 0));
        body.meta.counts.all = Math.max(Number(body.meta.counts.all || 0), liveRunning);
        return replacementResponse(response, body);
    };

    const renderWorkerSummary = (worker) => {
        if (!readableWorkerState || !worker || typeof worker !== 'object') {
            return;
        }

        const authority = String(worker.authoritative_status || (worker.active ? 'running' : 'stopped'));
        const counts = worker.queue_counts && typeof worker.queue_counts === 'object' ? worker.queue_counts : {};
        const active = Math.max(0, Number(worker.active_count || 0));
        const desired = Math.max(1, Number(worker.desired_count || active || 1));
        const launching = Math.max(0, Number(worker.launching_count || 0));
        const processed = Math.max(0, Number(worker.active_processed != null
            ? worker.active_processed
            : ((worker.state || {}).processed || 0)));
        const running = Math.max(0, Number(counts.running || 0));
        const queued = Math.max(0, Number(counts.queued || 0));
        const available = Math.max(0, Number(counts.ready || 0));
        const deferred = Math.max(0, queued - available);
        const terminal = Math.max(0, Number(counts.terminal || 0));
        const stale = Boolean(worker.stale_code);

        let workers;
        if (authority === 'running' || authority === 'degraded') {
            workers = 'Workers ' + active + '/' + desired + ' running'
                + (authority === 'degraded' ? ' (degraded)' : '')
                + (launching > 0 ? ' · ' + launching + ' launching' : '')
                + (stale ? ' · old code, restart required' : '');
        } else if (authority === 'orphaned') {
            workers = 'Workers stopped · ' + running + ' orphaned running work unit' + (running === 1 ? '' : 's');
        } else {
            workers = 'Workers stopped';
        }

        const session = (authority === 'running' || authority === 'degraded')
            ? ' · Session completed: ' + processed + ' work units'
            : '';
        const queueWork = ' · Work units now: ' + running + ' running · ' + queued + ' queued'
            + (queued > 0
                ? ' (' + available + ' ready' + (deferred > 0 ? ', ' + deferred + ' deferred' : '') + ')'
                : '')
            + (terminal > 0 ? ' · ' + terminal + ' terminal history' : '');

        readableWorkerState.dataset.authoritativeStatus = authority;
        readableWorkerState.textContent = workers + session + queueWork;
    };

    window.fetch = async (input, init) => {
        let response = await originalFetch(input, init);
        if (!response.ok) {
            return response;
        }

        const url = typeof input === 'string'
            ? input
            : (input && typeof input.url === 'string' ? input.url : '');
        const action = requestAction(init);

        response = await reconcileLiveRunningWorkflow(response, url);

        // Reuse the worker-status response already requested by the established
        // client. This adds no extra two-second polling request or database query.
        if (workerStatusUrl !== '' && url.includes(workerStatusUrl)) {
            const data = await responseData(response);
            if (data && data.worker) {
                renderWorkerSummary(data.worker);
            }
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