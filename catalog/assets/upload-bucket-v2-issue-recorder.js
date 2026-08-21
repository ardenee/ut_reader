(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const progressBox = document.getElementById('bucket-progress');
    const currentLabel = document.getElementById('bucket-progress-label');
    const overallCount = document.getElementById('bucket-overall-progress-count');
    const fullLog = document.getElementById('bucket-log');
    const errorLog = document.getElementById('bucket-error-log');
    const errorsOnly = document.getElementById('upload-bucket-errors-only');
    const errorCount = document.getElementById('upload-bucket-error-count');
    if (!form || !progressBox || !fullLog || !errorLog || !errorsOnly) return;

    const issueUrl = progressBox.dataset.issueUrl || 'api/v1/upload-bucket-issue.php';
    const csrf = progressBox.dataset.chunkCsrf || '';
    // v1 could leave unsent failures behind while a flush was already active.
    // Those stale rows were later replayed with a new server timestamp, making
    // old browser failures look like current failures. Start the corrected
    // protocol on a new key and deliberately discard the old diagnostic queue.
    const storageKey = 'unrealdb.uploadBucketV2.pendingIssues.v2';
    const legacyStorageKey = 'unrealdb.uploadBucketV2.pendingIssues';
    const nativePush = Array.prototype.push;
    const ERROR_ROW_HEIGHT = 22;
    const ERROR_OVERSCAN = 12;

    let capturedLines = null;
    let pushPatched = false;
    let uploadSessionId = '';
    let monitorTimer = 0;
    let flushing = false;
    let flushTimer = 0;
    let batchPauseRecorded = false;
    let workerStartRecorded = false;
    let nextInspectIndex = 0;
    let errorFrame = 0;
    let followErrorTail = true;
    const terminalSignatures = new Map();
    const originalToErrorIndex = new Map();
    const errorLines = [];

    const errorSpacer = document.createElement('div');
    const errorViewport = document.createElement('div');
    errorSpacer.className = 'bucket-log-spacer';
    errorViewport.className = 'bucket-log-viewport';
    errorLog.textContent = '';
    errorLog.appendChild(errorSpacer);
    errorLog.appendChild(errorViewport);

    function randomSessionId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        const bytes = new Uint8Array(16);
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, function (value) { return value.toString(16).padStart(2, '0'); }).join('');
        }
        return String(Date.now()) + '-' + Math.random().toString(16).slice(2);
    }

    function discardLegacyPending() {
        try {
            window.localStorage.removeItem(legacyStorageKey);
        } catch (error) {
            // Diagnostic persistence is optional; never block the uploader.
        }
    }

    function isUploadLogLine(value) {
        return value && typeof value === 'object'
            && Array.isArray(value.steps)
            && Object.prototype.hasOwnProperty.call(value, 'detail')
            && Object.prototype.hasOwnProperty.call(value, 'name')
            && Object.prototype.hasOwnProperty.call(value, 'sizeText')
            && Object.prototype.hasOwnProperty.call(value, 'outcome');
    }

    function lineText(line) {
        const tokens = Array.isArray(line.steps) ? line.steps.slice() : [];
        if (line.transient) tokens.push(String(line.transient));
        if (line.detail) tokens.push(String(line.detail));
        tokens.push(String(line.name || 'Unnamed file'));
        tokens.push(String(line.sizeText || '0 B'));
        return tokens.join(' : ');
    }

    function restorePush() {
        if (!pushPatched) return;
        Array.prototype.push = nativePush;
        pushPatched = false;
    }

    function captureCoordinatorLog() {
        if (capturedLines || pushPatched) return;
        pushPatched = true;
        Array.prototype.push = function () {
            const result = nativePush.apply(this, arguments);
            if (!capturedLines) {
                for (let index = 0; index < arguments.length; index++) {
                    if (!isUploadLogLine(arguments[index])) continue;
                    capturedLines = this;
                    restorePush();
                    startMonitor();
                    break;
                }
            }
            return result;
        };
        window.setTimeout(function () {
            if (!capturedLines) restorePush();
        }, 5000);
    }

    function loadPending() {
        try {
            const decoded = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
            return Array.isArray(decoded) ? decoded.filter(function (item) { return item && typeof item === 'object'; }) : [];
        } catch (error) {
            return [];
        }
    }

    function savePending(items) {
        try {
            const bounded = items.slice(-5000);
            if (bounded.length) window.localStorage.setItem(storageKey, JSON.stringify(bounded));
            else window.localStorage.removeItem(storageKey);
        } catch (error) {
            // The browser status line still shows the failure when local storage is unavailable.
        }
    }

    function schedulePendingFlush(delayMs) {
        if (flushTimer) return;
        flushTimer = window.setTimeout(function () {
            flushTimer = 0;
            flushPending();
        }, Math.max(0, Number(delayMs || 0)));
    }

    function queueIssue(payload) {
        const pending = loadPending();
        const item = Object.assign({}, payload, {
            occurred_at: String(payload && payload.occurred_at ? payload.occurred_at : new Date().toISOString())
        });
        pending.push(item);
        savePending(pending);
        schedulePendingFlush(0);
    }

    async function sendIssue(payload) {
        const response = await fetch(issueUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify(payload),
            keepalive: true
        });
        if (!response.ok) throw new Error('Upload Issue API returned HTTP ' + response.status + '.');
        const body = await response.json();
        if (!body || !body.ok) throw new Error('Upload Issue API rejected the record.');
    }

    async function flushPending() {
        if (flushing || !csrf) return;
        flushing = true;
        try {
            const pending = loadPending();
            if (!pending.length) return;
            const remaining = pending.slice();
            let sent = 0;
            while (remaining.length && sent < 100) {
                try {
                    await sendIssue(remaining[0]);
                } catch (error) {
                    break;
                }
                remaining.shift();
                sent++;
                savePending(remaining);
            }
        } finally {
            flushing = false;
            // New issues may have arrived while this async flush was active, and
            // a bounded 100-record pass may intentionally leave more work. Always
            // schedule another pass while anything remains instead of waiting for
            // a future page load to replay stale diagnostics.
            if (loadPending().length) schedulePendingFlush(500);
        }
    }

    function stageForLine(line) {
        const detail = String(line.detail || '').toUpperCase();
        const steps = Array.isArray(line.steps) ? line.steps.map(function (step) { return String(step || '').toUpperCase(); }) : [];
        if (detail.indexOf('EXTENSION NOT ALLOWED') >= 0) return 'selection';
        if (steps.indexOf('UPLOADED') >= 0 && steps.indexOf('QUEUED') < 0) return 'finalisation';
        if (steps.indexOf('READY') >= 0) return 'upload';
        if (steps.indexOf('CHECKED') >= 0) return 'preflight';
        return 'inspection';
    }

    function shouldRecordLine(line) {
        const outcome = String(line.outcome || '').toLowerCase();
        const detail = String(line.detail || '').toUpperCase();
        if (outcome === 'failed') return true;
        return outcome === 'skipped' && detail.indexOf('EXTENSION NOT ALLOWED') >= 0;
    }

    function recordLine(line) {
        const path = String(line.name || 'Unnamed file');
        const normalized = path.replace(/\\/g, '/');
        const parts = normalized.split('/');
        queueIssue({
            action: 'record',
            source_kind: 'upload_bucket_v2',
            upload_session_id: uploadSessionId || randomSessionId(),
            relative_path: path,
            original_name: parts.pop() || path,
            file_size_text: String(line.sizeText || ''),
            stage: stageForLine(line),
            error_message: String(line.detail || 'File handling failed.')
        });
    }

    function updateErrorCount() {
        if (errorCount) {
            errorCount.textContent = errorLines.length.toLocaleString() + ' error' + (errorLines.length === 1 ? '' : 's');
        }
    }

    function renderErrorNow(forceTail) {
        errorFrame = 0;
        if (errorLog.hidden || document.hidden) return;
        updateErrorCount();
        if (!errorLines.length) {
            errorSpacer.style.height = '22px';
            errorViewport.style.transform = 'translateY(0)';
            errorViewport.innerHTML = '<div class="bucket-log-line bucket-log-line-empty">No errors recorded.</div>';
            return;
        }
        const wasNearBottom = errorLog.scrollTop + errorLog.clientHeight >= errorLog.scrollHeight - (ERROR_ROW_HEIGHT * 3);
        if (forceTail || followErrorTail) {
            errorLog.scrollTop = Math.max(0, errorLines.length * ERROR_ROW_HEIGHT - errorLog.clientHeight);
        }
        errorSpacer.style.height = (errorLines.length * ERROR_ROW_HEIGHT) + 'px';
        const start = Math.max(0, Math.floor(errorLog.scrollTop / ERROR_ROW_HEIGHT) - ERROR_OVERSCAN);
        const visibleCount = Math.ceil(Math.max(ERROR_ROW_HEIGHT, errorLog.clientHeight) / ERROR_ROW_HEIGHT) + (ERROR_OVERSCAN * 2);
        const end = Math.min(errorLines.length, start + visibleCount);
        const fragment = document.createDocumentFragment();
        for (let index = start; index < end; index++) {
            const row = document.createElement('div');
            row.className = 'bucket-log-line bucket-log-line-failed';
            row.textContent = errorLines[index];
            row.style.height = ERROR_ROW_HEIGHT + 'px';
            fragment.appendChild(row);
        }
        errorViewport.textContent = '';
        errorViewport.style.transform = 'translateY(' + (start * ERROR_ROW_HEIGHT) + 'px)';
        errorViewport.appendChild(fragment);
        if (forceTail || (followErrorTail && wasNearBottom)) {
            errorLog.scrollTop = Math.max(0, errorLines.length * ERROR_ROW_HEIGHT - errorLog.clientHeight);
        }
    }

    function scheduleErrorRender(forceTail) {
        if (forceTail) followErrorTail = true;
        if (errorFrame || errorLog.hidden || document.hidden) return;
        errorFrame = window.requestAnimationFrame(function () { renderErrorNow(Boolean(forceTail)); });
    }

    function addOrUpdateErrorLine(originalIndex, text) {
        if (originalToErrorIndex.has(originalIndex)) {
            errorLines[originalToErrorIndex.get(originalIndex)] = text;
        } else {
            originalToErrorIndex.set(originalIndex, errorLines.length);
            errorLines.push(text);
        }
        scheduleErrorRender(true);
    }

    function addBatchError(text) {
        errorLines.push(String(text || 'Upload batch failed.'));
        scheduleErrorRender(true);
    }

    function inspectLines() {
        if (!capturedLines) return;
        const start = Math.max(0, nextInspectIndex - 1);
        for (let index = start; index < capturedLines.length; index++) {
            const line = capturedLines[index];
            if (!isUploadLogLine(line)) continue;
            const outcome = String(line.outcome || '').toLowerCase();
            const terminal = ['failed', 'skipped', 'uploaded', 'stopped'].includes(outcome);
            if (!terminal) {
                nextInspectIndex = index;
                break;
            }
            const signature = outcome + '|' + String(line.detail || '') + '|' + String(line.name || '') + '|' + String(line.sizeText || '');
            if (terminalSignatures.get(index) !== signature) {
                terminalSignatures.set(index, signature);
                if (shouldRecordLine(line)) {
                    addOrUpdateErrorLine(index, lineText(line));
                    recordLine(line);
                }
            }
            nextInspectIndex = index + 1;
        }
    }

    function recordBatch(stage, message) {
        const text = String(message || 'Upload batch failed.');
        addBatchError('FAILED : ' + text + ' : Upload batch : 0 B');
        queueIssue({
            action: 'record',
            source_kind: 'upload_bucket_v2',
            upload_session_id: uploadSessionId || randomSessionId(),
            relative_path: 'Upload batch',
            original_name: 'Upload batch',
            file_size_text: '',
            stage: stage,
            error_message: text
        });
    }

    function inspectBatchMessages() {
        const current = currentLabel ? String(currentLabel.textContent || '') : '';
        if (!batchPauseRecorded && current.indexOf('Could not request a safe processing pause:') === 0) {
            batchPauseRecorded = true;
            restorePush();
            recordBatch('worker_pause', current);
        }
        const overall = overallCount ? String(overallCount.textContent || '') : '';
        const marker = 'Worker start failed:';
        const position = overall.indexOf(marker);
        if (!workerStartRecorded && position >= 0) {
            workerStartRecorded = true;
            recordBatch('worker_start', overall.slice(position));
        }
    }

    function startMonitor() {
        if (monitorTimer) return;
        monitorTimer = window.setInterval(function () {
            inspectLines();
            inspectBatchMessages();
        }, 300);
    }

    function applyLogFilter() {
        const onlyErrors = Boolean(errorsOnly.checked);
        fullLog.hidden = onlyErrors;
        errorLog.hidden = !onlyErrors;
        if (onlyErrors) {
            renderErrorNow(false);
        } else {
            fullLog.dispatchEvent(new Event('scroll'));
        }
    }

    errorLog.addEventListener('scroll', function () {
        followErrorTail = errorLog.scrollTop + errorLog.clientHeight >= errorLog.scrollHeight - (ERROR_ROW_HEIGHT * 3);
        scheduleErrorRender(false);
    }, {passive: true});
    errorsOnly.addEventListener('change', applyLogFilter);

    form.addEventListener('submit', function () {
        uploadSessionId = randomSessionId();
        capturedLines = null;
        terminalSignatures.clear();
        originalToErrorIndex.clear();
        errorLines.length = 0;
        nextInspectIndex = 0;
        batchPauseRecorded = false;
        workerStartRecorded = false;
        errorLog.scrollTop = 0;
        updateErrorCount();
        renderErrorNow(false);
        captureCoordinatorLog();
        startMonitor();
        schedulePendingFlush(0);
    }, true);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) renderErrorNow(false);
    });
    window.addEventListener('online', function () { schedulePendingFlush(0); });
    window.addEventListener('beforeunload', function () {
        inspectLines();
        inspectBatchMessages();
        restorePush();
    });

    discardLegacyPending();
    applyLogFilter();
    renderErrorNow(false);
    schedulePendingFlush(0);
}());
