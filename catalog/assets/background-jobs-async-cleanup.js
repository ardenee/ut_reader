(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    const notice = document.getElementById('jobs-message') || document.getElementById('job-notice');
    if (!root || !notice || typeof window.fetch !== 'function') return;

    const bulkUrl = String(root.dataset.bulkUrl || '');
    const actionUrl = String(root.dataset.actionUrl || '');
    const statusUrl = String(root.dataset.statusUrl || '');
    const workerStatusUrl = String(root.dataset.workerStatusUrl || '');
    const originalFetch = window.fetch.bind(window);
    const operatorStatusById = new Map();
    const operatorStartedAtById = new Map();
    let pendingNotice = '';
    let latestWorkerAuthority = '';

    const setText = (element, text) => {
        if (!element) return;
        text = String(text == null ? '' : text);
        if (element.textContent !== text) element.textContent = text;
    };

    const style = document.createElement('style');
    style.textContent = [
        '#jobs-refresh,#jobs-apply-workers{display:none!important}',
        '.jobs-bulk-panel{margin:0 0 14px;border:1px solid var(--line);border-radius:10px;background:rgba(255,255,255,.012)}',
        '.jobs-bulk-panel>summary{cursor:pointer;padding:10px 12px;font-weight:700;list-style-position:inside}',
        '.jobs-bulk-panel .jobs-selectionbar{margin:0;padding:0 12px 12px}',
        '.jobs-pagination.is-single-page{display:none!important}',
        '#jobs-first-page,#jobs-last-page,#jobs-page-summary{display:none!important}',
        '.jobs-toolbar{gap:8px}',
        '.jobs-toolbar #jobs-worker-summary-readable{margin-left:auto}',
        '@media(max-width:900px){.jobs-toolbar #jobs-worker-summary-readable{width:100%;margin-left:0}}'
    ].join('');
    document.head.appendChild(style);

    const queueSelect = document.querySelector('.jobs-queue-switcher select[name="queue"]');
    if (queueSelect) {
        Array.from(queueSelect.options || []).forEach((option) => {
            const name = String(option.value || '').trim();
            if (name) option.textContent = name;
        });
        queueSelect.title = 'Queue selector';
    }

    const legacyWorkerState = document.getElementById('jobs-worker-state');
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

    const tabs = document.getElementById('jobs-status-tabs');
    if (tabs) {
        const labels = {
            '': 'All', queued: 'Waiting', running: 'In progress', completed: 'Completed',
            failed: 'Failed', dead_letter: 'Needs retry', cancelled: 'Cancelled'
        };
        tabs.querySelectorAll('button[data-status]').forEach((button) => {
            const key = String(button.dataset.status || '');
            const count = button.querySelector('[data-status-count]');
            if (!count || !Object.prototype.hasOwnProperty.call(labels, key)) return;
            button.replaceChildren(document.createTextNode(labels[key] + ' '), count);
        });
    }

    const headers = root.querySelectorAll('.jobs-table thead th');
    if (headers.length >= 6) {
        setText(headers[1], 'Job');
        setText(headers[5], 'In progress for');
    }

    const toolbar = root.querySelector('.jobs-toolbar');
    const startButton = document.getElementById('jobs-start');
    const stopButton = document.getElementById('jobs-stop-worker');
    const refreshButton = document.getElementById('jobs-refresh');
    if (refreshButton) refreshButton.hidden = true;

    const simplifyWorkerControls = () => {
        const poolState = document.getElementById('jobs-worker-pool-state');
        const applyWorkers = document.getElementById('jobs-apply-workers');
        const workerCount = document.getElementById('jobs-worker-count');
        if (poolState) {
            poolState.hidden = true;
            poolState.setAttribute('aria-hidden', 'true');
        }
        if (applyWorkers) applyWorkers.hidden = true;
        if (startButton) {
            const label = String(startButton.textContent || '').toLowerCase();
            startButton.hidden = Boolean(startButton.disabled) && (label.includes('start') || label.includes('resume'));
        }
        if (stopButton) stopButton.hidden = Boolean(stopButton.disabled);
        if (workerCount && applyWorkers && !workerCount.dataset.operatorAutoApply) {
            workerCount.dataset.operatorAutoApply = '1';
            workerCount.title = 'Changing this resizes a running worker pool automatically. If stopped, the value is used when the queue starts.';
            workerCount.addEventListener('change', () => {
                if (latestWorkerAuthority === 'running' || latestWorkerAuthority === 'degraded') {
                    window.setTimeout(() => applyWorkers.click(), 0);
                }
            });
        }
    };
    simplifyWorkerControls();
    if (toolbar && typeof MutationObserver !== 'undefined') {
        new MutationObserver(simplifyWorkerControls).observe(toolbar, {
            childList: true, subtree: true, attributes: true, attributeFilter: ['disabled']
        });
    }

    const selectionBar = root.querySelector('.jobs-selectionbar');
    const selectionSummary = document.getElementById('jobs-selection-summary');
    let bulkPanel = null;
    let bulkSummary = null;
    if (selectionBar && !selectionBar.closest('.jobs-bulk-panel')) {
        bulkPanel = document.createElement('details');
        bulkPanel.className = 'jobs-bulk-panel';
        bulkSummary = document.createElement('summary');
        selectionBar.parentNode.insertBefore(bulkPanel, selectionBar);
        bulkPanel.appendChild(bulkSummary);
        bulkPanel.appendChild(selectionBar);
    }
    const syncBulkPanel = () => {
        if (!bulkPanel || !bulkSummary || !selectionSummary) return;
        const text = String(selectionSummary.textContent || '').trim();
        const selected = text !== '' && text !== 'Nothing selected';
        setText(bulkSummary, selected ? 'Bulk actions · ' + text : 'Bulk actions');
        if (selected) bulkPanel.open = true;
    };
    syncBulkPanel();
    if (selectionSummary && typeof MutationObserver !== 'undefined') {
        new MutationObserver(syncBulkPanel).observe(selectionSummary, {
            childList: true, characterData: true, subtree: true
        });
    }

    const pagination = root.querySelector('.jobs-pagination');
    const pageLabel = document.getElementById('jobs-page-label');
    const syncPagination = () => {
        if (!pagination || !pageLabel) return;
        pagination.classList.toggle('is-single-page', /^Page\s+1\s+of\s+1$/i.test(String(pageLabel.textContent || '').trim()));
    };
    syncPagination();
    if (pageLabel && typeof MutationObserver !== 'undefined') {
        new MutationObserver(syncPagination).observe(pageLabel, {
            childList: true, characterData: true, subtree: true
        });
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
        try { return await response.clone().json(); } catch (_) { return null; }
    };
    const responseData = async (response) => {
        const body = await responseBody(response);
        return body && typeof body === 'object' && body.data && typeof body.data === 'object' ? body.data : null;
    };
    const replacementResponse = (response, body) => {
        const headers = new Headers(response.headers);
        headers.set('Content-Type', 'application/json');
        headers.delete('Content-Length');
        return new Response(JSON.stringify(body), {
            status: response.status, statusText: response.statusText, headers: headers
        });
    };

    const rollUpJobStatus = async (response, url) => {
        if (!statusUrl || !url.includes(statusUrl)) return response;
        const body = await responseBody(response);
        if (!body || !body.data || !Array.isArray(body.data.jobs)) return response;
        let changed = false;
        body.data.jobs.forEach((job) => {
            if (!job || typeof job !== 'object') return;
            const id = Number(job.id || 0);
            const raw = String(job.status || '').toLowerCase();
            const operator = String(job.operator_status || raw).toLowerCase();
            if (id > 0) {
                operatorStatusById.set(id, operator);
                operatorStartedAtById.set(id, String(job.operator_started_at || job.leased_at || ''));
            }
            if (operator === 'running' && String(job.display_status || '').toLowerCase() !== 'running') {
                job.display_status = 'running';
                changed = true;
            }
        });
        return changed ? replacementResponse(response, body) : response;
    };

    const parseUtc = (value) => {
        const text = String(value || '').trim();
        if (!text) return 0;
        const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text) ? text.replace(' ', 'T') + 'Z' : text;
        const timestamp = Date.parse(normalized);
        return Number.isFinite(timestamp) ? timestamp : 0;
    };
    const formatDuration = (milliseconds) => {
        let seconds = Math.max(0, Math.floor(milliseconds / 1000));
        const days = Math.floor(seconds / 86400); seconds -= days * 86400;
        const hours = Math.floor(seconds / 3600); seconds -= hours * 3600;
        const minutes = Math.floor(seconds / 60); seconds -= minutes * 60;
        const parts = [];
        if (days) parts.push(days + 'd');
        if (days || hours) parts.push(hours + 'h');
        if (days || hours || minutes) parts.push(minutes + 'm');
        parts.push(seconds + 's');
        return parts.join(' ');
    };

    const renderOperatorRows = () => {
        const tableBody = document.getElementById('jobs-table-body');
        if (!tableBody) return;
        tableBody.querySelectorAll('.jobs-main-row[data-job-id]').forEach((row) => {
            const id = Number(row.dataset.jobId || 0);
            const status = operatorStatusById.get(id) || '';
            const badge = row.querySelector('.job-status');
            if (badge) {
                if (status === 'running') setText(badge, 'in progress');
                else if (status === 'queued') setText(badge, 'waiting');
            }
            if (status === 'running') {
                const started = parseUtc(operatorStartedAtById.get(id));
                const runtime = row.querySelector('.jobs-running-for');
                if (started && runtime) setText(runtime, formatDuration(Date.now() - started));
            }
            const actionButton = row.querySelector('.jobs-actions button');
            if (actionButton && String(actionButton.textContent || '').trim() === 'Cancel') {
                setText(actionButton, 'Cancel job');
            }
        });
    };

    const tableBody = document.getElementById('jobs-table-body');
    if (tableBody && typeof MutationObserver !== 'undefined') {
        let queued = false;
        new MutationObserver(() => {
            if (queued) return;
            queued = true;
            window.queueMicrotask(() => {
                queued = false;
                renderOperatorRows();
            });
        }).observe(tableBody, {childList: true, subtree: true, characterData: true});
    }
    window.setInterval(renderOperatorRows, 1000);

    const renderWorkerSummary = (worker) => {
        if (!readableWorkerState || !worker || typeof worker !== 'object') return;
        const authority = String(worker.authoritative_status || (worker.active ? 'running' : 'stopped'));
        latestWorkerAuthority = authority;
        const active = Math.max(0, Number(worker.active_count || 0));
        const desired = Math.max(1, Number(worker.desired_count || active || 1));
        const launching = Math.max(0, Number(worker.launching_count || 0));
        const stale = Boolean(worker.stale_code);
        let text;
        if (authority === 'running' || authority === 'degraded') {
            text = 'Workers ' + active + '/' + desired + ' running'
                + (authority === 'degraded' ? ' (degraded)' : '')
                + (launching > 0 ? ' · ' + launching + ' starting' : '')
                + (stale ? ' · restart required' : '');
        } else if (authority === 'orphaned') {
            text = 'Workers stopped · orphaned job requires recovery';
        } else if (authority === 'stopped_with_queue') {
            text = 'Workers stopped · jobs waiting';
        } else {
            text = 'Workers stopped';
        }
        readableWorkerState.dataset.authoritativeStatus = authority;
        setText(readableWorkerState, text);
        simplifyWorkerControls();
    };

    window.fetch = async (input, init) => {
        let response = await originalFetch(input, init);
        if (!response.ok) return response;
        const url = typeof input === 'string' ? input : (input && typeof input.url === 'string' ? input.url : '');
        const action = requestAction(init);
        response = await rollUpJobStatus(response, url);
        if (workerStatusUrl && url.includes(workerStatusUrl)) {
            const data = await responseData(response);
            if (data && data.worker) renderWorkerSummary(data.worker);
        }
        window.setTimeout(() => {
            renderOperatorRows(); syncPagination(); syncBulkPanel(); simplifyWorkerControls();
        }, 0);

        const isBulkDelete = bulkUrl && url.includes(bulkUrl) && action === 'delete';
        const isRetentionCleanup = actionUrl && url.includes(actionUrl) && action === 'cleanup';
        if (!isBulkDelete && !isRetentionCleanup) return response;
        const data = await responseData(response);
        const cleanupJobId = Number(data && data.cleanup_job_id || 0);
        if (cleanupJobId < 1) return response;
        const scheduled = Number(data && data.scheduled || 0);
        const requested = Number(data && data.requested || scheduled);
        const limited = Boolean(data && data.limited);
        const workerError = String(data && data.worker_error || '').trim();
        pendingNotice = 'Queued background-job cleanup #' + cleanupJobId
            + ' for ' + scheduled + ' job(s)'
            + (requested > scheduled ? ' from ' + requested + ' matching job(s)' : '')
            + '. Actual deleted/skipped/staged-file counts will be reported by the cleanup job.'
            + (limited ? ' The 10,000-job snapshot limit was reached; run cleanup again after it completes for any remainder.' : '')
            + (workerError ? ' Worker start warning: ' + workerError : '');
        return response;
    };

    new MutationObserver(() => {
        if (!pendingNotice) return;
        const text = String(notice.textContent || '');
        if (text.startsWith('Delete affected ') || text.startsWith('Removed ')) {
            setText(notice, pendingNotice);
            pendingNotice = '';
        }
    }).observe(notice, {childList: true, characterData: true, subtree: true});
})();
