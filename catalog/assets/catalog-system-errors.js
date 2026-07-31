(function () {
    'use strict';

    const script = document.currentScript;
    const endpoint = script && script.src
        ? new URL('../api/v1/system-error.php', script.src).toString()
        : 'api/v1/system-error.php';
    const storageKey = 'unrealdb.systemErrors.pending';
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

    window.addEventListener('error', function (event) {
        if (reporting) return;
        const target = event.target;
        if (target && target !== window && !event.message) {
            const source = target.src || target.href || '';
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

    window.addEventListener('online', flush);
    window.setTimeout(flush, 0);
}());
