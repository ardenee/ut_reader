(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    const notice = document.getElementById('jobs-message') || document.getElementById('job-notice');
    if (!root || !notice || typeof window.fetch !== 'function') {
        return;
    }

    const bulkUrl = String(root.dataset.bulkUrl || '');
    const actionUrl = String(root.dataset.actionUrl || '');
    const workerState = document.getElementById('jobs-worker-state');
    const statusTabs = document.getElementById('jobs-status-tabs');
    const queueSelect = document.querySelector('.jobs-queue-switcher select[name="queue"]');
    const originalFetch = window.fetch.bind(window);
    let pendingNotice = '';

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
        queueSelect.title = 'Queue selector. Live raw work-unit counts are shown in the worker status line.';
    }

    // The stable/cursor clients intentionally suppress routine workflow children.
    // Label that scope so tab counts are never mistaken for raw queue-unit counts.
    if (statusTabs && !document.getElementById('jobs-operator-view-label')) {
        const label = document.createElement('p');
        label.id = 'jobs-operator-view-label';
        label.className = 'muted';
        label.style.margin = '0 0 8px';
        label.textContent = 'Operator view — parent workflows plus child units requiring attention; routine child units are folded into their parent. The live worker banner uses raw work units; “processed” is only completions since the current worker processes started.';
        statusTabs.parentNode.insertBefore(label, statusTabs);
        statusTabs.title = 'These are operator-visible rows, not raw queue work-unit totals.';
    }

    // background-jobs-cursor-bridge.js already owns the authoritative live worker
    // banner. Hide only its second, redundant "Pool x/y active · max n" label so
    // the page has one worker/process count instead of two competing summaries.
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
    if (workerState) {
        workerState.title = 'Pool = detached worker processes; processed = jobs completed by the current worker processes; active/queued = raw durable work units. Tab counts below use the folded operator view.';
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

    window.fetch = async (input, init) => {
        const response = await originalFetch(input, init);
        if (!response.ok) {
            return response;
        }

        const url = typeof input === 'string'
            ? input
            : (input && typeof input.url === 'string' ? input.url : '');
        const action = requestAction(init);
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
