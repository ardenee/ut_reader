(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    const notice = document.getElementById('jobs-message') || document.getElementById('job-notice');
    if (!root || !notice || typeof window.fetch !== 'function') {
        return;
    }

    const bulkUrl = String(root.dataset.bulkUrl || '');
    const actionUrl = String(root.dataset.actionUrl || '');
    const originalFetch = window.fetch.bind(window);
    let pendingNotice = '';

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
