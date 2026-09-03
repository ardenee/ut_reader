(function () {
    'use strict';

    const script = document.currentScript;
    const endpoint = script && script.src
        ? new URL('../api/v1/system-error.php', script.src).toString()
        : 'api/v1/system-error.php';
    const exportEndpoint = script && script.src
        ? new URL('../system-errors-export.php', script.src).toString()
        : 'system-errors-export.php';
    const storageKey = 'unrealdb.systemErrors.pending';
    const seenRenderedErrors = new Set();
    let flushing = false;
    let reporting = false;

    function clean(value, limit) {
        const text = String(value === undefined || value === null ? '' : value).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]+/g, ' ').trim();
        return text.length > limit ? text.slice(0, limit) : text;
    }

    function loadPending() {
        try {
            const parsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
            return Array.isArray(parsed) ? parsed.filter(function (item) { return item && typeof item === 'object'; }) : [];
        } catch (error) {
            return [];
        }
    }

    function savePending(items) {
        try {
            const bounded = items.slice(-500);
            if (bounded.length) localStorage.setItem(storageKey, JSON.stringify(bounded));
            else localStorage.removeItem(storageKey);
        } catch (error) {
            // Server-side PHP/API logging remains available when browser storage is blocked.
        }
    }

    function enqueue(payload) {
        if (reporting) return;
        const pending = loadPending();
        pending.push(payload);
        savePending(pending);
        flush();
    }

    async function send(payload) {
        reporting = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-UnrealDB-Error-Report': '1'
                },
                body: JSON.stringify(payload),
                keepalive: true
            });
            if (!response.ok) throw new Error('Error report returned HTTP ' + response.status + '.');
        } finally {
            reporting = false;
        }
    }

    async function flush() {
        if (flushing || reporting) return;
        flushing = true;
        try {
            const pending = loadPending();
            if (!pending.length) return;
            const remaining = pending.slice();
            let sent = 0;
            while (remaining.length && sent < 25) {
                try {
                    await send(remaining[0]);
                } catch (error) {
                    break;
                }
                remaining.shift();
                sent++;
                savePending(remaining);
            }
        } finally {
            flushing = false;
        }
    }

    function basePayload(type, message) {
        return {
            error_type: clean(type, 120) || 'javascript_error',
            severity: 'error',
            message: clean(message, 8000) || 'Unknown browser error.',
            route: clean(location.pathname, 500),
            page_title: clean(document.title, 500)
        };
    }

    function reportRenderedErrors(root) {
        const scope = root && root.querySelectorAll ? root : document;
        const selectors = '.msg.err,.ui-alert--danger,.alert-danger,[role="alert"].error,[role="alert"].danger';
        const nodes = [];
        if (root && root.nodeType === 1 && root.matches && root.matches(selectors)) nodes.push(root);
        scope.querySelectorAll(selectors).forEach(function (node) { nodes.push(node); });
        nodes.forEach(function (node) {
            const text = clean(node.textContent || '', 8000);
            if (!text) return;
            const signature = location.pathname + '|' + text;
            if (seenRenderedErrors.has(signature)) return;
            seenRenderedErrors.add(signature);
            enqueue(basePayload('rendered_application_error', text));
        });
    }

    function reportNavigationStatus() {
        if (!window.performance || typeof performance.getEntriesByType !== 'function') return;
        const navigation = performance.getEntriesByType('navigation')[0];
        const status = navigation ? Number(navigation.responseStatus || 0) : 0;
        if (status < 400) return;
        const payload = basePayload('document_http_error', 'Page navigation returned HTTP ' + status + '.');
        payload.source_line = 0;
        payload.http_status = status;
        enqueue(payload);
    }

    function installExportButton() {
        if (!/(?:^|\/)system-errors\.php$/.test(location.pathname)) return;
        const toolbar = document.querySelector('.system-error-toolbar');
        if (!toolbar || toolbar.querySelector('[data-system-error-export]')) return;

        const params = new URLSearchParams(location.search);
        params.delete('p');
        params.delete('per_page');
        const link = document.createElement('a');
        link.className = 'button secondary';
        link.dataset.systemErrorExport = '1';
        link.textContent = 'Export errors';
        link.href = exportEndpoint + (params.toString() ? '?' + params.toString() : '');
        link.title = 'Download all System Error records matching the current filters as Markdown';
        toolbar.appendChild(link);

        const corruptLink = document.createElement('a');
        corruptLink.className = 'button secondary';
        corruptLink.dataset.systemErrorCorruptExport = '1';
        corruptLink.textContent = 'Export corrupt files';
        corruptLink.href = 'corrupt-files-export.php';
        corruptLink.title = 'Download current corrupt/non-retryable file sources with resolved full paths as CSV';
        toolbar.appendChild(corruptLink);
    }

    function shouldIgnoreResourceError(source) {
        const value = String(source || '').trim();
        if (value === '') return true;
        // blob:/data: resources are generated locally by the current page and are
        // intentionally short-lived. Once revoked they cannot be retried or
        // diagnosed as HTTP resources, so recording them as server resource
        // failures creates noisy, non-actionable System Error records.
        return /^(?:blob:|data:)/i.test(value);
    }

    window.addEventListener('error', function (event) {
        if (reporting) return;
        const target = event.target;
        if (target && target !== window && !event.message) {
            const source = target.src || target.href || '';
            if (shouldIgnoreResourceError(source)) return;
            const payload = basePayload('resource_load_error', 'Browser resource failed to load: ' + source);
            payload.source_file = clean(source, 1000);
            enqueue(payload);
            return;
        }
        const payload = basePayload('javascript_error', event.message || 'Unknown JavaScript error.');
        payload.source_file = clean(event.filename || '', 1000);
        payload.source_line = Math.max(0, Number(event.lineno || 0));
        payload.source_column = Math.max(0, Number(event.colno || 0));
        payload.trace_text = clean(event.error && event.error.stack ? event.error.stack : '', 16000);
        enqueue(payload);
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        if (reporting) return;
        const reason = event.reason;
        const message = reason && reason.message ? reason.message : String(reason || 'Unhandled promise rejection.');
        const payload = basePayload('unhandled_promise_rejection', message);
        payload.trace_text = clean(reason && reason.stack ? reason.stack : '', 16000);
        enqueue(payload);
    });

    function startRenderedErrorObserver() {
        reportRenderedErrors(document);
        reportNavigationStatus();
        installExportButton();
        if (!window.MutationObserver || !document.body) return;
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node && node.nodeType === 1) reportRenderedErrors(node);
                });
            });
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startRenderedErrorObserver, {once: true});
    } else {
        startRenderedErrorObserver();
    }
    window.addEventListener('online', flush);
    window.setTimeout(flush, 0);
}());
