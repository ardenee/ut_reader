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
    const queueSelect = document.querySelector('.jobs-queue-switcher select[name="queue"]');
    const originalFetch = window.fetch.bind(window);
    let pendingNotice = '';

    // Queue names are navigation only. Do not mix database-row totals into the
    // selector; the job tabs below are the operator-facing accounting model.
    if (queueSelect) {
        Array.from(queueSelect.options || []).forEach((option) => {
            const queueName = String(option.value || '').trim();
            if (queueName !== '') {
                option.textContent = queueName;
            }
        });
        queueSelect.title = 'Queue selector';
    }

    // The established clients still use the legacy worker banner internally for
    // button/control state. Present one read-only pool summary without exposing
    // volatile workflow-unit counters.
    let readableWorkerState = document.getElementById('jobs-worker-summary-readable');
    if (legacyWorkerState) {
        legacyWorkerState.hidden = true;
        legacyWorkerState.setAttribute('aria-hidden', 'true');
        if (!readableWorkerState) {
            readableWorkerState = document.createElement('span');
            readableWorkerState.id = 'jobs-worker-summary-readable';
            readableWorkerState.className = 'muted jobs-worker-state';
            readableWorkerState.textContent = 'Loading worker state…';
            legacyWorkerState.parentNode.insertBefore(readableWorkerState, legacyWorkerState);
        }
    }

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

    // The durable coordinator row may be internally deferred to `queued` while
    // its own child workflow units are processing. The API exposes operator_status
    // so the row badge follows the parent job's real progress without changing the
    // raw queue status used by cancel/restart controls.
    const rollUpJobStatus = async (response, url) => {
        if (statusUrl === '' || !url.includes(statusUrl)) {
            return response;
        }
        const body = await responseBody(response);
        if (!body || !body.data || !Array.isArray(body.data.jobs)) {
            return response;
        }
        let changed = false;
        body.data.jobs.forEach((job) => {
            if (!job || typeof job !== 'object') {
                return;
            }
            const raw = String(job.status || '').toLowerCase();
            const operator = String(job.operator_status || raw).toLowerCase();
            if (raw === 'queued' && operator === 'running') {
                job.display_status = 'running';
                changed = true;
            }
        });
        return changed ? replacementResponse(response, body) : response;
    };

    const renderWorkerSummary = (worker) => {
        if (!readableWorkerState || !worker || typeof worker !== 'object') {
            return;
        }

        const authority = String(worker.authoritative_status || (worker.active ? 'running' : 'stopped'));
        const active = Math.max(0, Number(worker.active_count || 0));
        const desired = Math.max(1, Number(worker.desired_count || active || 1));
        const launching = Math.max(0, Number(worker.launching_count || 0));
        const stale = Boolean(worker.stale_code);

        let text;
        if (authority === 'running' || authority === 'degraded') {
            text = 'Workers ' + active + '/' + desired + ' running'
                + (authority === 'degraded' ? ' (degraded)' : '')
                + (launching > 0 ? ' · ' + launching + ' launching' : '')
                + (stale ? ' · old code, restart required' : '');
        } else if (authority === 'orphaned') {
            text = 'Workers stopped · orphaned job requires recovery';
        } else if (authority === 'stopped_with_queue') {
            text = 'Workers stopped · jobs waiting';
        } else {
            text = 'Workers stopped';
        }

        readableWorkerState.dataset.authoritativeStatus = authority;
        readableWorkerState.textContent = text;
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

        response = await rollUpJobStatus(response, url);

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
            + ' for ' + scheduled + ' job(s)'
            + (requested > scheduled ? ' from ' + requested + ' matching job(s)' : '')
            + '. Actual deleted/skipped/staged-file counts will be reported by the cleanup job.'
            + (limited ? ' The 10,000-job snapshot limit was reached; run cleanup again after it completes for any remainder.' : '')
            + (workerError !== '' ? ' Worker start warning: ' + workerError : '');

        return response;
    };

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
