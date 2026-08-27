(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    if (!root || typeof window.fetch !== 'function') return;

    const statusUrl = String(root.dataset.statusUrl || '');
    const bulkUrl = String(root.dataset.bulkUrl || 'api/v1/job-bulk.php');
    const queue = String(root.dataset.queue || 'catalog');
    const csrf = String(root.dataset.csrf || '');
    const tabs = document.getElementById('jobs-status-tabs');
    const tableBody = document.getElementById('jobs-table-body');
    const searchInput = document.getElementById('jobs-search');
    const selectMatchingButton = document.getElementById('jobs-select-matching');
    const notice = document.getElementById('jobs-message');
    const refreshButton = document.getElementById('jobs-refresh');
    const originalFetch = window.fetch.bind(window);
    const archiveTypes = new Set([
        'catalog.process_bucket_archive',
        'catalog.import_staged_archive'
    ]);
    const retainedArchiveIds = new Set();
    const blockedArchiveIds = new Set();
    let retainedArchiveCount = 0;
    let retryMatchingButton = null;

    const requestUrl = (input) => {
        try {
            return new URL(typeof input === 'string' ? input : input.url, window.location.href);
        } catch (_) {
            return null;
        }
    };

    const isStatusRequest = (url) => {
        if (!url) return false;
        if (statusUrl && url.pathname.endsWith('/' + statusUrl.replace(/^\/+/, ''))) return true;
        return /\/api\/v1\/job-status(?:-cursor)?\.php$/i.test(url.pathname);
    };

    const responseBody = async (response) => {
        try {
            return await response.clone().json();
        } catch (_) {
            return null;
        }
    };

    const replaceResponse = (response, body) => {
        const headers = new Headers(response.headers);
        headers.set('Content-Type', 'application/json');
        headers.delete('Content-Length');
        return new Response(JSON.stringify(body), {
            status: response.status,
            statusText: response.statusText,
            headers: headers
        });
    };

    const integer = (value) => {
        const number = Number(value || 0);
        return Number.isFinite(number) ? Math.max(0, Math.trunc(number)) : 0;
    };

    const setText = (element, text) => {
        if (!element) return;
        const next = String(text == null ? '' : text);
        if (element.textContent !== next) element.textContent = next;
    };

    const formatError = (record) => {
        if (!record || typeof record !== 'object') return '';
        const file = String(record.file || '').trim();
        const error = String(record.error || '').trim();
        const meta = [];
        const backend = String(record.backend || '').trim();
        const exception = String(record.exception_type || '').trim();
        const entryIndex = integer(record.entry_index);
        const declaredBytes = integer(record.declared_bytes);
        if (backend) meta.push('backend=' + backend);
        if (exception) meta.push('exception=' + exception);
        if (Object.prototype.hasOwnProperty.call(record, 'entry_index')) meta.push('entry=' + entryIndex);
        if (declaredBytes) meta.push('declared=' + declaredBytes + ' bytes');
        const main = [file, error].filter(Boolean).join(' — ');
        return main + (meta.length ? ' [' + meta.join(', ') + ']' : '');
    };

    const isRetainedPartialArchive = (job) => {
        if (!job || typeof job !== 'object') return false;
        return archiveTypes.has(String(job.job_type || ''))
            && String(job.status || '').toLowerCase() === 'completed'
            && String(job.display_status || '').toLowerCase() === 'partial';
    };

    const isBlockedRetainedArchive = (job) => {
        if (!isRetainedPartialArchive(job)) return false;
        const progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
        const result = job.result && typeof job.result === 'object' ? job.result : {};
        if (progress.recovery_blocked === true || result.recovery_blocked === true) return true;

        const errors = [];
        if (Array.isArray(progress.errors)) errors.push(...progress.errors.map(formatError));
        if (Array.isArray(result.errors)) errors.push(...result.errors.map(formatError));
        const text = [
            String(progress.message || ''),
            String(result.message || ''),
            String(job.last_error || ''),
            ...errors
        ].join(' ').toLowerCase();

        return text.includes('installed php archive decoder cannot decode this archive/member encoding')
            || text.includes('unsupported zip compression method')
            || text.includes('rarentry::extract() returned failure')
            || text.includes('rarentry::extract() also failed')
            || text.includes('configured archive-member limit') && text.includes(' is 0 bytes');
    };

    const decorateArchiveJob = (job) => {
        if (!job || typeof job !== 'object' || !archiveTypes.has(String(job.job_type || ''))) return false;
        const progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
        const result = job.result && typeof job.result === 'object' ? job.result : {};
        const sourceErrors = Array.isArray(result.errors) && result.errors.length
            ? result.errors
            : (Array.isArray(progress.errors) ? progress.errors : []);
        if (!sourceErrors.length) return false;

        const visible = sourceErrors.map(formatError).filter(Boolean);
        if (!visible.length) return false;
        const retained = visible.slice(0, 8);
        if (visible.length > retained.length) {
            retained.push('… and ' + (visible.length - retained.length) + ' more retained archive error(s)');
        }
        const detail = retained.join(' | ');
        const summary = String(progress.message || result.message || 'Archive expansion did not complete cleanly.').trim();
        const combined = summary.includes(detail)
            ? summary
            : summary.replace(/[.\s]+$/, '') + '. Failed archive member(s): ' + detail;

        progress.message = combined;
        progress.archive_error_count = sourceErrors.length;
        result.message = combined;
        result.archive_error_count = sourceErrors.length;
        job.progress = progress;
        job.result = result;
        job.last_error = detail;
        return true;
    };

    const ensureRecoveryTab = () => {
        if (!tabs) return null;
        let button = tabs.querySelector('button[data-status="partial_archive"]');
        if (button) return button;

        button = document.createElement('button');
        button.type = 'button';
        button.dataset.status = 'partial_archive';
        button.setAttribute('aria-selected', 'false');
        button.appendChild(document.createTextNode('Retained archives '));
        const count = document.createElement('span');
        count.dataset.statusCount = 'partial_archive';
        count.textContent = '0';
        button.appendChild(count);

        const cancelled = tabs.querySelector('button[data-status="cancelled"]');
        tabs.insertBefore(button, cancelled || null);
        return button;
    };

    const partialTabActive = () => {
        const button = tabs ? tabs.querySelector('button[data-status="partial_archive"]') : null;
        return Boolean(button && button.getAttribute('aria-selected') === 'true');
    };

    const syncRecoveryRows = () => {
        if (!tableBody) return;
        const retryTitle = 'Replay the retained source archive. Already queued successful members are reused.';
        const blockedTitle = 'The retained source is preserved, but replaying it through the same PHP decoder cannot change this result.';
        tableBody.querySelectorAll('.jobs-main-row[data-job-id]').forEach((row) => {
            const id = Number(row.dataset.jobId || 0);
            if (!retainedArchiveIds.has(id)) return;
            const button = row.querySelector('.jobs-actions button');
            if (!button) return;

            if (blockedArchiveIds.has(id)) {
                button.hidden = false;
                button.disabled = true;
                button.dataset.action = '';
                setText(button, 'Recovery blocked');
                if (button.title !== blockedTitle) button.title = blockedTitle;
                return;
            }

            button.hidden = false;
            button.disabled = false;
            if (button.dataset.action !== 'restart') button.dataset.action = 'restart';
            setText(button, 'Retry archive');
            if (button.title !== retryTitle) button.title = retryTitle;
        });
    };

    const visibleRetryableCount = () => Math.max(0, retainedArchiveIds.size - blockedArchiveIds.size);

    const syncRecoveryControls = () => {
        ensureRecoveryTab();
        syncRecoveryRows();
        if (!retryMatchingButton || !tabs) return;
        const active = partialTabActive();
        const retryable = visibleRetryableCount();
        retryMatchingButton.hidden = !active || retryable < 1;
        retryMatchingButton.disabled = !active || retryable < 1;
        setText(retryMatchingButton, 'Retry retryable archives');
        retryMatchingButton.title = 'Decoder-blocked retained archives are deliberately excluded.';
    };

    const postBulkRetry = async () => {
        if (!bulkUrl || retainedArchiveCount < 1 || visibleRetryableCount() < 1) return;
        const search = searchInput ? String(searchInput.value || '').trim() : '';
        if (!window.confirm('Retry retained archives that are still recoverable with the current PHP decoder? Decoder-blocked archives will remain retained.')) return;

        retryMatchingButton.disabled = true;
        try {
            const response = await originalFetch(bulkUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({
                    action: 'restart',
                    scope: 'matching',
                    queue: queue,
                    status: 'partial_archive',
                    search: search,
                    job_ids: []
                })
            });
            const body = await responseBody(response);
            if (!response.ok) {
                const message = body && body.error && body.error.message
                    ? String(body.error.message)
                    : 'Could not retry retained archives (HTTP ' + response.status + ').';
                throw new Error(message);
            }
            const data = body && body.data ? body.data : {};
            const affected = integer(data.affected);
            let text = affected > 0
                ? 'Retried ' + affected + ' recoverable retained archive job(s). Decoder-blocked archives were left retained.'
                : 'No retained archives are currently retryable; decoder-blocked sources remain safely retained.';
            if (data.limited) text += ' The 10,000-job safety limit was reached; retry the remaining matching archives again.';
            if (data.worker_error) text += ' Jobs were queued, but the worker could not start: ' + String(data.worker_error);
            if (notice) setText(notice, text);
            if (refreshButton) refreshButton.click();
        } catch (error) {
            if (notice) setText(notice, error && error.message ? error.message : 'Could not retry retained archives.');
        } finally {
            retryMatchingButton.disabled = false;
        }
    };

    const activateRetainedArchiveDeepLink = () => {
        const params = new URLSearchParams(window.location.search);
        if (String(params.get('status') || '').toLowerCase() !== 'partial_archive') return;

        // The stable client now recognises partial_archive before its initial fetch.
        // Keep this bounded activation only as a compatibility fallback for an old
        // cached base script; it must never spin indefinitely.
        let attempts = 0;
        const activate = () => {
            const tab = ensureRecoveryTab();
            if (!tab || partialTabActive() || attempts >= 6) return;
            attempts++;
            tab.click();
            if (!partialTabActive()) window.setTimeout(activate, 500);
        };
        window.setTimeout(activate, 0);
    };

    const installRecoveryControls = () => {
        ensureRecoveryTab();
        if (!retryMatchingButton && selectMatchingButton && selectMatchingButton.parentNode) {
            retryMatchingButton = document.createElement('button');
            retryMatchingButton.id = 'jobs-retry-retained-matching';
            retryMatchingButton.type = 'button';
            retryMatchingButton.hidden = true;
            retryMatchingButton.textContent = 'Retry retryable archives';
            retryMatchingButton.addEventListener('click', postBulkRetry);
            selectMatchingButton.parentNode.insertBefore(retryMatchingButton, selectMatchingButton.nextSibling);
        }

        if (tabs && typeof MutationObserver !== 'undefined') {
            new MutationObserver(syncRecoveryControls).observe(tabs, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['aria-selected']
            });
        }

        // Do not observe table childList mutations here. The former observer
        // rewrote button.textContent while observing the same subtree, which can
        // recursively schedule MutationObserver microtasks and hang Chromium.
        // Every successful status response schedules one bounded row sync below.
        activateRetainedArchiveDeepLink();
        syncRecoveryControls();
    };

    window.fetch = async (input, init) => {
        const url = requestUrl(input);
        const response = await originalFetch(input, init);
        if (!response.ok || !isStatusRequest(url)) return response;

        const body = await responseBody(response);
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : null;
        if (!jobs) return response;

        retainedArchiveIds.clear();
        blockedArchiveIds.clear();
        let changed = false;
        jobs.forEach((job) => {
            if (isRetainedPartialArchive(job)) {
                const id = Number(job.id || 0);
                retainedArchiveIds.add(id);
                if (isBlockedRetainedArchive(job)) blockedArchiveIds.add(id);
            }
            if (decorateArchiveJob(job)) changed = true;
        });
        const counts = body && body.meta && body.meta.counts && typeof body.meta.counts === 'object'
            ? body.meta.counts
            : {};
        retainedArchiveCount = integer(counts.partial_archive);
        window.setTimeout(syncRecoveryControls, 0);
        return changed ? replaceResponse(response, body) : response;
    };

    installRecoveryControls();
})();
