(() => {
    'use strict';

    const root = document.getElementById('background-jobs-app');
    if (!root || typeof window.fetch !== 'function') return;

    const statusUrl = String(root.dataset.statusUrl || '');
    const originalFetch = window.fetch.bind(window);
    const archiveTypes = new Set([
        'catalog.process_bucket_archive',
        'catalog.import_staged_archive'
    ]);

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

    window.fetch = async (input, init) => {
        const url = requestUrl(input);
        const response = await originalFetch(input, init);
        if (!response.ok || !isStatusRequest(url)) return response;

        const body = await responseBody(response);
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : null;
        if (!jobs) return response;

        let changed = false;
        jobs.forEach((job) => {
            if (decorateArchiveJob(job)) changed = true;
        });
        return changed ? replaceResponse(response, body) : response;
    };
})();