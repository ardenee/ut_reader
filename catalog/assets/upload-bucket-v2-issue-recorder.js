(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const progressBox = document.getElementById('bucket-progress');
    const currentLabel = document.getElementById('bucket-progress-label');
    const overallCount = document.getElementById('bucket-overall-progress-count');
    if (!form || !progressBox) return;

    const issueUrl = progressBox.dataset.issueUrl || 'api/v1/upload-bucket-issue.php';
    const csrf = progressBox.dataset.chunkCsrf || '';
    const storageKey = 'unrealdb.uploadBucketV2.pendingIssues';
    const nativePush = Array.prototype.push;
    let capturedLines = null;
    let pushPatched = false;
    let uploadSessionId = '';
    let monitorTimer = 0;
    let flushing = false;
    let batchPauseRecorded = false;
    let workerStartRecorded = false;
    const terminalSignatures = new Map();

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

    function isUploadLogLine(value) {
        return value && typeof value === 'object'
            && Array.isArray(value.steps)
            && Object.prototype.hasOwnProperty.call(value, 'detail')
            && Object.prototype.hasOwnProperty.call(value, 'name')
            && Object.prototype.hasOwnProperty.call(value, 'sizeText')
            && Object.prototype.hasOwnProperty.call(value, 'outcome');
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

    function queueIssue(payload) {
        const pending = loadPending();
        pending.push(payload);
        savePending(pending);
        flushPending();
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
                const payload = remaining[0];
                try {
                    await sendIssue(payload);
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

    function stageForLine(line) {
        const detail = String(line.detail || '').toUpperCase();
        const steps = Array.isArray(line.steps) ? line.steps.map(function (step) { return String(step || '').toUpperCase(); }) : [];
        if (detail.indexOf('EXTENSION NOT ALLOWED') >= 0 || detail.indexOf('DUPLICATE SELECTION') >= 0) return 'selection';
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
        const message = String(line.detail || 'File handling failed.');
        queueIssue({
            action: 'record',
            source_kind: 'upload_bucket_v2',
            upload_session_id: uploadSessionId || randomSessionId(),
            relative_path: path,
            original_name: parts.pop() || path,
            file_size_text: String(line.sizeText || ''),
            stage: stageForLine(line),
            error_message: message
        });
    }

    function inspectLines() {
        if (!capturedLines) return;
        for (let index = 0; index < capturedLines.length; index++) {
            const line = capturedLines[index];
            if (!isUploadLogLine(line)) continue;
            const outcome = String(line.outcome || '').toLowerCase();
            if (!['failed', 'skipped', 'uploaded', 'stopped'].includes(outcome)) continue;
            const signature = outcome + '|' + String(line.detail || '') + '|' + String(line.name || '') + '|' + String(line.sizeText || '');
            if (terminalSignatures.get(index) === signature) continue;
            terminalSignatures.set(index, signature);
            if (shouldRecordLine(line)) recordLine(line);
        }
    }

    function recordBatch(stage, message) {
        queueIssue({
            action: 'record',
            source_kind: 'upload_bucket_v2',
            upload_session_id: uploadSessionId || randomSessionId(),
            relative_path: 'Upload batch',
            original_name: 'Upload batch',
            file_size_text: '',
            stage: stage,
            error_message: message
        });
    }

    function inspectBatchMessages() {
        const current = currentLabel ? String(currentLabel.textContent || '') : '';
        if (!batchPauseRecorded && current.indexOf('Could not request a safe processing pause:') === 0) {
            batchPauseRecorded = true;
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
        }, 400);
    }

    form.addEventListener('submit', function () {
        uploadSessionId = randomSessionId();
        capturedLines = null;
        terminalSignatures.clear();
        batchPauseRecorded = false;
        workerStartRecorded = false;
        captureCoordinatorLog();
        startMonitor();
        flushPending();
    }, true);

    window.addEventListener('online', flushPending);
    window.addEventListener('beforeunload', function () {
        inspectLines();
        inspectBatchMessages();
        restorePush();
    });

    flushPending();
}());
