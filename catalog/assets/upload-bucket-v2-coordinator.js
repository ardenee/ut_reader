(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const fileInput = document.getElementById('upload-bucket-files');
    const folderInput = document.getElementById('upload-bucket-folder');
    const folderButton = document.getElementById('upload-bucket-folder-button');
    const folderSummary = document.getElementById('upload-bucket-folder-summary');
    const startButton = document.getElementById('upload-bucket-button');
    const stopButton = document.getElementById('upload-bucket-stop');
    const progressBox = document.getElementById('bucket-progress');
    const currentBar = document.getElementById('bucket-progress-bar');
    const overallBar = document.getElementById('bucket-overall-progress-bar');
    const currentLabel = document.getElementById('bucket-progress-label');
    const currentSpeed = document.getElementById('bucket-progress-speed');
    const overallLabel = document.getElementById('bucket-overall-progress-label');
    const overallCount = document.getElementById('bucket-overall-progress-count');
    const log = document.getElementById('bucket-log');
    if (!form || !fileInput || !startButton || !stopButton || !progressBox || !log
        || !window.XMLHttpRequest || !window.fetch || !window.Worker) return;

    const chunkUrl = progressBox.dataset.chunkUrl || 'api/v1/upload-bucket-chunk.php';
    const batchUrl = progressBox.dataset.batchUrl || 'api/v1/upload-bucket-batch.php';
    const workerUrl = progressBox.dataset.inspectorWorkerUrl || 'assets/upload-file-inspector-worker.js';
    const queueUrl = progressBox.dataset.processingUrl || 'background-jobs.php?queue=catalog%3Abucket-processing';
    const csrf = progressBox.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    const LOG_ROW_HEIGHT = 22;
    const LOG_OVERSCAN = 12;

    let configuredExtensions = [];
    try {
        configuredExtensions = JSON.parse(form.dataset.allowedExtensions || '[]');
    } catch (error) {
        configuredExtensions = [];
    }
    const allowedExtensions = new Set(configuredExtensions.map(function (extension) {
        return String(extension || '').trim().toLowerCase().replace(/^\.+/, '');
    }).filter(Boolean));
    ['uz', 'uz2', 'uz3'].forEach(function (extension) { allowedExtensions.add(extension); });

    let operationActive = false;
    let directoryScanActive = false;
    let stopRequested = false;
    let activeXhr = null;
    let activeFetchController = null;
    let activeInspector = null;
    let activeInspectorReject = null;
    let activeUploadId = '';
    let queuePrepared = false;
    let pickedDirectoryEntries = [];
    let activeLineId = -1;

    const logLines = [];
    let logFrame = 0;
    let followLogTail = true;
    const logSpacer = document.createElement('div');
    const logViewport = document.createElement('div');
    logSpacer.className = 'bucket-log-spacer';
    logViewport.className = 'bucket-log-viewport';
    log.textContent = '';
    log.appendChild(logSpacer);
    log.appendChild(logViewport);

    let progressFrame = 0;
    let pendingProgress = null;

    function stoppedError() {
        const error = new Error('Stopped by user.');
        error.name = 'AbortError';
        return error;
    }

    function isStopped(error) {
        return stopRequested || (error && error.name === 'AbortError');
    }

    function yieldToBrowser() {
        return new Promise(function (resolve) { window.setTimeout(resolve, 0); });
    }

    function delay(milliseconds) {
        return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
    }

    function sleep(milliseconds) {
        return new Promise(function (resolve, reject) {
            const started = Date.now();
            function check() {
                if (stopRequested) {
                    reject(stoppedError());
                    return;
                }
                if (Date.now() - started >= milliseconds) {
                    resolve();
                    return;
                }
                window.setTimeout(check, Math.min(100, milliseconds));
            }
            check();
        });
    }

    function fmtBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = Number(bytes || 0);
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return (unit ? value.toFixed(2) : String(Math.round(value))) + ' ' + units[unit];
    }

    function extensionOf(name) {
        const clean = String(name || '').replace(/\\/g, '/').split('/').pop() || '';
        const position = clean.lastIndexOf('.');
        return position >= 0 ? clean.slice(position + 1).trim().toLowerCase() : '';
    }

    function isAllowedName(name) {
        const extension = extensionOf(name);
        return extension !== '' && allowedExtensions.has(extension);
    }

    function isRedirectName(name) {
        return /\.(?:uz|uz2|uz3)$/i.test(String(name || ''));
    }

    function fileKey(file, relativePath) {
        return [file.name, file.size, file.lastModified || 0, relativePath || file.name].join('|');
    }

    function lineText(line) {
        const tokens = line.steps.slice();
        if (line.transient) tokens.push(line.transient);
        if (line.detail) tokens.push(line.detail);
        tokens.push(line.name || 'Unnamed file');
        tokens.push(line.sizeText || '0 B');
        return tokens.join(' : ');
    }

    function lineClass(line) {
        const outcome = String(line.outcome || '').toLowerCase();
        return 'bucket-log-line' + (outcome ? ' bucket-log-line-' + outcome.replace(/[^a-z0-9_-]+/g, '-') : '');
    }

    function renderLogNow(forceTail) {
        logFrame = 0;
        if (document.hidden) return;
        const wasNearBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - (LOG_ROW_HEIGHT * 3);
        if (forceTail || followLogTail) {
            log.scrollTop = Math.max(0, logLines.length * LOG_ROW_HEIGHT - log.clientHeight);
        }
        logSpacer.style.height = (logLines.length * LOG_ROW_HEIGHT) + 'px';
        const start = Math.max(0, Math.floor(log.scrollTop / LOG_ROW_HEIGHT) - LOG_OVERSCAN);
        const visibleCount = Math.ceil(Math.max(LOG_ROW_HEIGHT, log.clientHeight) / LOG_ROW_HEIGHT) + (LOG_OVERSCAN * 2);
        const end = Math.min(logLines.length, start + visibleCount);
        const fragment = document.createDocumentFragment();
        for (let index = start; index < end; index++) {
            const row = document.createElement('div');
            row.className = lineClass(logLines[index]);
            row.textContent = lineText(logLines[index]);
            row.style.height = LOG_ROW_HEIGHT + 'px';
            fragment.appendChild(row);
        }
        logViewport.textContent = '';
        logViewport.style.transform = 'translateY(' + (start * LOG_ROW_HEIGHT) + 'px)';
        logViewport.appendChild(fragment);
        if (forceTail || (followLogTail && wasNearBottom)) {
            log.scrollTop = Math.max(0, logLines.length * LOG_ROW_HEIGHT - log.clientHeight);
        }
    }

    function scheduleLogRender(forceTail) {
        if (forceTail) followLogTail = true;
        if (logFrame || document.hidden) return;
        logFrame = window.requestAnimationFrame(function () { renderLogNow(Boolean(forceTail)); });
    }

    function resetLog() {
        logLines.length = 0;
        logSpacer.style.height = '0px';
        logViewport.textContent = '';
        log.scrollTop = 0;
        followLogTail = true;
    }

    function beginFileLine(name, size) {
        const line = {
            steps: [],
            transient: 'CHECKING',
            detail: '',
            name: String(name || 'Unnamed file'),
            sizeText: fmtBytes(size),
            outcome: ''
        };
        logLines.push(line);
        const lineId = logLines.length - 1;
        activeLineId = lineId;
        scheduleLogRender(true);
        return lineId;
    }

    function appendStage(lineId, stage, outcome) {
        const line = logLines[lineId];
        if (!line) return;
        line.transient = '';
        line.detail = '';
        line.steps.push(String(stage || '').toUpperCase());
        if (outcome) line.outcome = String(outcome).toLowerCase();
        scheduleLogRender(true);
    }

    function setLineTransient(lineId, status) {
        const line = logLines[lineId];
        if (!line) return;
        line.transient = String(status || '').toUpperCase();
        scheduleLogRender(true);
    }

    function finishLine(lineId, finalStage, outcome, detail) {
        const line = logLines[lineId];
        if (!line) return;
        line.transient = '';
        line.detail = detail ? String(detail) : '';
        line.steps.push(String(finalStage || '').toUpperCase());
        line.outcome = String(outcome || finalStage || '').toLowerCase();
        scheduleLogRender(true);
        if (activeLineId === lineId) activeLineId = -1;
    }

    log.addEventListener('scroll', function () {
        followLogTail = log.scrollTop + log.clientHeight >= log.scrollHeight - (LOG_ROW_HEIGHT * 3);
        scheduleLogRender(false);
    }, {passive: true});

    function flushProgress() {
        progressFrame = 0;
        if (!pendingProgress || document.hidden) return;
        const update = pendingProgress;
        pendingProgress = null;
        if (update.indeterminate) currentBar.removeAttribute('value');
        else currentBar.value = Math.max(0, Math.min(100, Number(update.percent || 0)));
        if (update.label !== undefined) currentLabel.textContent = String(update.label || '');
        if (update.speed !== undefined) currentSpeed.textContent = String(update.speed || '');
    }

    function scheduleProgress(percent, label, speed, indeterminate) {
        pendingProgress = {percent: percent, label: label, speed: speed, indeterminate: Boolean(indeterminate)};
        if (progressFrame || document.hidden) return;
        progressFrame = window.requestAnimationFrame(flushProgress);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            flushProgress();
            renderLogNow(false);
        }
    });

    function setBusy(active) {
        startButton.disabled = active;
        fileInput.disabled = active;
        if (folderInput) folderInput.disabled = active;
        if (folderButton) folderButton.disabled = active;
        stopButton.disabled = !active;
        stopButton.hidden = !active;
    }

    async function* walkDirectory(directoryHandle, prefix) {
        for await (const entry of directoryHandle.values()) {
            if (stopRequested) throw stoppedError();
            const relativePath = prefix ? prefix + '/' + entry.name : entry.name;
            if (entry.kind === 'file') {
                yield {handle: entry, relativePath: relativePath};
            } else if (entry.kind === 'directory') {
                yield* walkDirectory(entry, relativePath);
            }
        }
    }

    async function chooseDirectory() {
        if (operationActive || directoryScanActive) return;
        if (typeof window.showDirectoryPicker !== 'function') {
            if (folderInput) folderInput.click();
            return;
        }
        stopRequested = false;
        directoryScanActive = true;
        pickedDirectoryEntries = [];
        setBusy(true);
        progressBox.hidden = false;
        overallBar.removeAttribute('value');
        overallLabel.textContent = 'Discovering folder without building a browser FileList';
        overallCount.textContent = '0 files found';
        scheduleProgress(0, 'Choose a folder in the browser prompt.', '', true);
        try {
            const rootHandle = await window.showDirectoryPicker({mode: 'read'});
            let count = 0;
            for await (const entry of walkDirectory(rootHandle, rootHandle.name)) {
                pickedDirectoryEntries.push(entry);
                count++;
                if (count % 200 === 0) {
                    overallCount.textContent = count.toLocaleString() + ' files found';
                    scheduleProgress(0, 'Scanning ' + rootHandle.name + ': ' + count.toLocaleString() + ' files found.', '', true);
                    await yieldToBrowser();
                }
            }
            overallBar.value = 0;
            overallLabel.textContent = 'Folder ready';
            overallCount.textContent = count.toLocaleString() + ' files selected';
            scheduleProgress(0, 'Folder discovery complete. Press Check and upload files to start.', '', false);
            if (folderSummary) folderSummary.textContent = rootHandle.name + ' · ' + count.toLocaleString() + ' files';
        } catch (error) {
            pickedDirectoryEntries = [];
            overallBar.value = 0;
            if (stopRequested) {
                overallLabel.textContent = 'Folder discovery stopped';
                overallCount.textContent = 'No folder files retained';
                scheduleProgress(0, 'Folder discovery was stopped.', '', false);
            } else if (error && error.name === 'AbortError') {
                overallLabel.textContent = 'Folder selection cancelled';
                overallCount.textContent = '';
                scheduleProgress(0, 'No folder was selected.', '', false);
            } else {
                overallLabel.textContent = 'Folder discovery failed';
                overallCount.textContent = '';
                scheduleProgress(0, error && error.message ? error.message : 'The folder could not be read.', '', false);
            }
            if (folderSummary) folderSummary.textContent = 'No folder selected';
        } finally {
            stopRequested = false;
            directoryScanActive = false;
            setBusy(false);
        }
    }

    if (folderButton) folderButton.addEventListener('click', chooseDirectory);
    if (folderInput) {
        folderInput.addEventListener('change', function () {
            const count = Number(folderInput.files ? folderInput.files.length : 0);
            if (folderSummary) folderSummary.textContent = count ? count.toLocaleString() + ' fallback folder files' : 'No folder selected';
        });
    }

    function selectionCount() {
        return Number(fileInput.files ? fileInput.files.length : 0)
            + Number(folderInput && folderInput.files ? folderInput.files.length : 0)
            + pickedDirectoryEntries.length;
    }

    async function selectionAt(index) {
        const directCount = Number(fileInput.files ? fileInput.files.length : 0);
        if (index < directCount) {
            const file = fileInput.files[index];
            return {file: file, relativePath: file.webkitRelativePath || file.name};
        }
        index -= directCount;
        const fallbackCount = Number(folderInput && folderInput.files ? folderInput.files.length : 0);
        if (index < fallbackCount) {
            const file = folderInput.files[index];
            return {file: file, relativePath: file.webkitRelativePath || file.name};
        }
        index -= fallbackCount;
        const picked = pickedDirectoryEntries[index];
        if (!picked) throw new Error('The selected file list changed while it was being processed.');
        const file = await picked.handle.getFile();
        return {file: file, relativePath: picked.relativePath};
    }

    function errorText(body, fallback) {
        if (body && typeof body.error === 'string' && body.error) return body.error;
        if (body && body.error && typeof body.error.message === 'string') return body.error.message;
        if (body && typeof body.message === 'string' && body.message) return body.message;
        return fallback;
    }

    function parseJson(text, status, contentType, label) {
        try {
            return JSON.parse(text || '{}');
        } catch (error) {
            if (/^\s*(?:<!doctype\s+html|<html\b)/i.test(text || '') || /text\/html/i.test(contentType || '')) {
                throw new Error(label + ' returned an HTML error page (HTTP ' + status + ').');
            }
            throw new Error(label + ' returned invalid JSON (HTTP ' + status + ').');
        }
    }

    function requestForm(data, onProgress, timeoutMs) {
        if (stopRequested) return Promise.reject(stoppedError());
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            activeXhr = xhr;
            xhr.open('POST', chunkUrl, true);
            xhr.timeout = Math.max(30000, Number(timeoutMs || 120000));
            xhr.setRequestHeader('Accept', 'application/json');
            if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);
            if (typeof onProgress === 'function') xhr.upload.onprogress = onProgress;
            function finish() { if (activeXhr === xhr) activeXhr = null; }
            xhr.onload = function () {
                finish();
                let body;
                try {
                    body = parseJson(xhr.responseText, xhr.status, xhr.getResponseHeader('Content-Type'), 'Upload request');
                } catch (error) {
                    reject(error);
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || !body.ok) {
                    reject(new Error(errorText(body, 'Upload request failed with HTTP ' + xhr.status + '.')));
                    return;
                }
                resolve(body);
            };
            xhr.onerror = function () { finish(); reject(new Error('Upload connection error.')); };
            xhr.onabort = function () { finish(); reject(stoppedError()); };
            xhr.ontimeout = function () { finish(); reject(new Error('Upload request timed out.')); };
            xhr.send(data);
        });
    }

    async function requestFormRetry(data, onProgress, fileName, operation, timeoutMs, lineId) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            if (stopRequested) throw stoppedError();
            try {
                const result = await requestForm(data, onProgress, timeoutMs);
                if (lineId >= 0) setLineTransient(lineId, '');
                return result;
            } catch (error) {
                if (isStopped(error)) throw stoppedError();
                lastError = error;
                if (attempt >= 4) break;
                if (lineId >= 0) setLineTransient(lineId, 'RETRYING ' + (attempt + 1) + '/4');
                scheduleProgress(0, operation + ' retry ' + (attempt + 1) + ' of 4: ' + fileName, '', false);
                await sleep(attempt * 750);
            }
        }
        throw lastError || new Error(operation + ' failed.');
    }

    async function postBatch(payload, operation, fileName, allowWhenStopped, lineId) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            if (stopRequested && !allowWhenStopped) throw stoppedError();
            const controller = new AbortController();
            activeFetchController = controller;
            try {
                const response = await fetch(batchUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify(payload),
                    signal: controller.signal
                });
                if (activeFetchController === controller) activeFetchController = null;
                let body;
                try {
                    body = await response.json();
                } catch (error) {
                    throw new Error(operation + ' returned invalid JSON (HTTP ' + response.status + ').');
                }
                if (!response.ok || !body.ok) {
                    throw new Error(errorText(body, operation + ' failed with HTTP ' + response.status + '.'));
                }
                if (lineId >= 0) setLineTransient(lineId, '');
                return body.data || {};
            } catch (error) {
                if (activeFetchController === controller) activeFetchController = null;
                if ((stopRequested && !allowWhenStopped) || error.name === 'AbortError') throw stoppedError();
                lastError = error;
                if (attempt >= 4) break;
                if (lineId >= 0) setLineTransient(lineId, 'RETRYING ' + (attempt + 1) + '/4');
                await (allowWhenStopped ? delay(attempt * 750) : sleep(attempt * 750));
            }
        }
        throw lastError || new Error(operation + ' failed.');
    }

    async function processingState(action) {
        const data = new FormData();
        data.append('action', action);
        return requestFormRetry(data, null, 'Upload batch', action === 'begin_batch' ? 'Worker pause request' : 'Worker status check', 120000, -1);
    }

    function workerDescription(processing) {
        const workers = processing && Array.isArray(processing.workers) ? processing.workers : [];
        const active = workers.find(function (worker) { return Boolean(worker.active); });
        if (!active) return 'Upload Bucket worker';
        const job = active.running_job || {};
        const parts = [];
        if (job.id) parts.push('job #' + Number(job.id));
        if (job.file) parts.push(String(job.file));
        if (Number(job.percent || 0) > 0) parts.push(Number(job.percent) + '%');
        if (job.message) parts.push(String(job.message));
        return parts.length ? parts.join(' · ') : String(active.queue || 'Upload Bucket worker');
    }

    async function waitUntilPaused(initialBody, lineId) {
        let body = initialBody;
        let processing = body && body.processing ? body.processing : {};
        while (!processing.ready) {
            if (stopRequested) throw stoppedError();
            setLineTransient(lineId, 'WAITING');
            scheduleProgress(0, 'Waiting for ' + workerDescription(processing) + ' to finish its current file.', '', true);
            await sleep(1000);
            body = await processingState('batch_status');
            processing = body.processing || {};
        }
        setLineTransient(lineId, '');
        scheduleProgress(100, 'Previous Upload Bucket processing is paused.', '', false);
    }

    function ensureInspectorWorker() {
        if (activeInspector) return activeInspector;
        const worker = new Worker(workerUrl);
        activeInspector = worker;
        return worker;
    }

    function terminateInspector() {
        if (activeInspector) activeInspector.terminate();
        activeInspector = null;
        activeInspectorReject = null;
    }

    function inspectFile(file, relativePath, index, total, lineId) {
        if (stopRequested) return Promise.reject(stoppedError());
        return new Promise(function (resolve, reject) {
            const worker = ensureInspectorWorker();
            const requestId = String(Date.now()) + '-' + String(index);
            activeInspectorReject = reject;
            scheduleProgress(0, 'Inspecting header for ' + index + ' of ' + total + ': ' + relativePath, '', false);
            setLineTransient(lineId, 'CHECKING');
            worker.onmessage = function (event) {
                const data = event.data || {};
                if (String(data.id || '') !== requestId) return;
                if (data.type === 'progress') {
                    const loaded = Math.max(0, Number(data.loaded || 0));
                    const size = Math.max(1, Number(data.total || file.size || 1));
                    const label = data.phase === 'hash'
                        ? 'Calculating MD5 and SHA-1 for ' + index + ' of ' + total + ': ' + relativePath
                        : 'Checking Unreal file header for ' + index + ' of ' + total + ': ' + relativePath;
                    scheduleProgress(Math.floor((loaded * 100) / size), label, '', false);
                    return;
                }
                if (data.type === 'result') {
                    activeInspectorReject = null;
                    resolve(data.result || {});
                    return;
                }
                if (data.type === 'error') {
                    activeInspectorReject = null;
                    reject(new Error(String(data.message || 'File inspection failed.')));
                }
            };
            worker.onerror = function () {
                activeInspectorReject = null;
                terminateInspector();
                reject(new Error('The browser file-inspection worker failed.'));
            };
            worker.postMessage({type: 'inspect', id: requestId, file: file});
        });
    }

    async function preflight(file, relativePath, inspection, lineId) {
        const data = new FormData();
        data.append('action', 'preflight');
        data.append('original_name', file.name);
        data.append('relative_path', relativePath);
        data.append('file_size', String(file.size || 0));
        if (inspection && inspection.md5 && inspection.sha1) {
            data.append('md5', inspection.md5);
            data.append('sha1', inspection.sha1);
        }
        setLineTransient(lineId, 'CHECKING DATABASE');
        scheduleProgress(0, 'Checking database identity: ' + relativePath, '', false);
        return requestFormRetry(data, null, relativePath, 'Duplicate check', 120000, lineId);
    }

    async function uploadFile(file, relativePath, inspection, index, total, lineId) {
        const identity = inspection && inspection.md5 && inspection.sha1 ? inspection : null;
        const initData = new FormData();
        initData.append('action', 'init');
        initData.append('client_key', fileKey(file, relativePath));
        initData.append('original_name', file.name);
        initData.append('relative_path', relativePath);
        initData.append('file_size', String(file.size || 0));
        if (identity) {
            initData.append('md5', identity.md5);
            initData.append('sha1', identity.sha1);
        }
        setLineTransient(lineId, 'OPENING');
        scheduleProgress(0, 'Opening durable staging for ' + index + ' of ' + total + ': ' + relativePath, '', false);
        const initialized = await requestFormRetry(initData, null, relativePath, 'Upload initialisation', 120000, lineId);
        const upload = initialized.upload || {};
        const uploadId = String(upload.upload_id || '');
        if (!uploadId) throw new Error('Upload initialisation did not return an upload ID.');
        activeUploadId = uploadId;

        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        let acknowledged = Math.max(0, Number(upload.received_bytes || 0));
        setLineTransient(lineId, received.size ? 'RESUMING' : 'UPLOADING');

        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            if (stopRequested) throw stoppedError();
            const start = chunkIndex * chunkBytes;
            const end = Math.min(file.size, start + chunkBytes);
            if (received.has(chunkIndex)) {
                acknowledged = Math.max(acknowledged, end);
                scheduleProgress(Math.floor((acknowledged * 100) / Math.max(1, file.size)),
                    'Resuming ' + index + ' of ' + total + ': ' + relativePath, '', false);
                continue;
            }
            const data = new FormData();
            data.append('action', 'chunk');
            data.append('upload_id', uploadId);
            data.append('chunk_index', String(chunkIndex));
            data.append('chunk', file.slice(start, end), file.name + '.part-' + chunkIndex);
            const base = acknowledged;
            const startedAt = Date.now();
            await requestFormRetry(data, function (event) {
                if (!event.lengthComputable) return;
                const current = Math.min(file.size, base + event.loaded);
                const elapsed = Math.max(0.25, (Date.now() - startedAt) / 1000);
                scheduleProgress(
                    Math.floor((current * 100) / Math.max(1, file.size)),
                    'Uploading ' + index + ' of ' + total + ': ' + relativePath + ' · chunk ' + (chunkIndex + 1) + '/' + totalChunks,
                    fmtBytes(event.loaded / elapsed) + '/s',
                    false
                );
            }, relativePath, 'Chunk ' + (chunkIndex + 1) + '/' + totalChunks, 180000, lineId);
            acknowledged += end - start;
        }

        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', uploadId);
        setLineTransient(lineId, 'VERIFYING');
        scheduleProgress(100, 'Verifying staged file ' + index + ' of ' + total + ': ' + relativePath, '', false);
        const completed = await requestFormRetry(completeData, null, relativePath, 'Upload completion', 120000, lineId);
        activeUploadId = '';
        scheduleProgress(100, 'Staged upload verified: ' + relativePath, '', false);
        return {uploadId: String(completed.upload_id || uploadId), name: relativePath, size: Number(file.size || 0)};
    }

    async function finalizeOne(item, lineId) {
        setLineTransient(lineId, 'QUEUING');
        scheduleProgress(0, 'Validating and queuing: ' + item.name, '', false);
        const data = await postBatch({
            upload_ids: [item.uploadId],
            prepare_queue: !queuePrepared,
            start_worker: false
        }, 'File finalisation', item.name, false, lineId);
        queuePrepared = true;
        scheduleProgress(100, 'Queue result received: ' + item.name, '', false);
        return data;
    }

    async function startProcessing(allowWhenStopped) {
        if (!queuePrepared) return {pending_jobs: 0, worker_error: ''};
        scheduleProgress(0, 'Starting Upload Bucket processing for queued files...', '', true);
        return postBatch({upload_ids: [], prepare_queue: false, start_worker: true},
            'Processing worker start', 'Upload batch', Boolean(allowWhenStopped), -1);
    }

    function updateOverall(done, total, totals) {
        overallBar.value = Math.floor((done * 100) / Math.max(1, total));
        overallLabel.textContent = done < total ? 'Processing file ' + (done + 1) + ' of ' + total : 'Selected files complete';
        overallCount.textContent = done.toLocaleString() + ' of ' + total.toLocaleString() + ' finished · '
            + totals.queued.toLocaleString() + ' queued · ' + totals.duplicates.toLocaleString() + ' duplicate(s) · '
            + totals.skipped.toLocaleString() + ' skipped · ' + totals.failed.toLocaleString() + ' failed';
    }

    function addCompletionPanel() {
        const oldPanel = progressBox.querySelector('.bucket-next-phase');
        if (oldPanel) oldPanel.remove();
        const panel = document.createElement('div');
        panel.className = 'bucket-next-phase';
        panel.innerHTML = '<strong>File handling complete.</strong> '
            + '<a class="button" href="' + queueUrl.replace(/"/g, '&quot;') + '">Open processing jobs</a> '
            + '<a class="button secondary" href="unverified-files.php?source_game_id=-1">Review Upload Bucket</a>';
        progressBox.appendChild(panel);
    }

    async function finishOperation(totals, totalFiles, stopped) {
        let workerResult = {pending_jobs: 0, worker_error: ''};
        try {
            workerResult = await startProcessing(stopped);
        } catch (error) {
            if (!isStopped(error)) workerResult.worker_error = error.message || 'Worker start failed.';
        }
        operationActive = false;
        terminateInspector();
        setBusy(false);
        currentSpeed.textContent = '';
        if (stopped) {
            overallLabel.textContent = 'Stopped by user';
            currentLabel.textContent = activeUploadId
                ? 'Stopped. The current partial upload remains in durable staging and can resume when the same file is selected again.'
                : 'Stopped. Completed files remain queued; unstarted files were not read or uploaded.';
        } else {
            overallBar.value = 100;
            currentBar.value = 100;
            overallLabel.textContent = 'Upload queue complete';
            currentLabel.textContent = 'Finished: ' + totals.queued + ' queued, ' + totals.duplicates + ' duplicate(s), '
                + totals.skipped + ' skipped, ' + totals.failed + ' failed.';
        }
        const workerText = workerResult.worker_error
            ? ' Worker start failed: ' + workerResult.worker_error
            : (Number(workerResult.pending_jobs || 0) > 0 ? ' Processing jobs are running or queued.' : ' No processing jobs remain.');
        overallCount.textContent = totals.finished.toLocaleString() + ' of ' + totalFiles.toLocaleString() + ' finished · '
            + totals.queued.toLocaleString() + ' queued · ' + totals.duplicates.toLocaleString() + ' duplicate(s) · '
            + totals.skipped.toLocaleString() + ' skipped · ' + totals.failed.toLocaleString() + ' failed.' + workerText;
        addCompletionPanel();
        renderLogNow(true);
    }

    stopButton.addEventListener('click', function () {
        if ((!operationActive && !directoryScanActive) || stopRequested) return;
        stopRequested = true;
        stopButton.disabled = true;
        scheduleProgress(0, directoryScanActive
            ? 'Stopping folder discovery...'
            : 'Stopping after the active browser/server operation is aborted...', '', true);
        if (activeInspectorReject) {
            const reject = activeInspectorReject;
            activeInspectorReject = null;
            reject(stoppedError());
        }
        terminateInspector();
        if (activeXhr) activeXhr.abort();
        if (activeFetchController) activeFetchController.abort();
        if (activeLineId >= 0) finishLine(activeLineId, 'STOPPED', 'stopped', 'STOPPED BY USER');
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (operationActive || directoryScanActive) return;
        const totalFiles = selectionCount();
        if (!totalFiles) {
            window.alert('Choose one or more supported files or a folder first.');
            return;
        }
        if (!csrf) {
            window.alert('Upload security token is unavailable. Reload the page.');
            return;
        }

        operationActive = true;
        stopRequested = false;
        activeUploadId = '';
        queuePrepared = false;
        activeLineId = -1;
        setBusy(true);
        progressBox.hidden = false;
        resetLog();
        const oldPanel = progressBox.querySelector('.bucket-next-phase');
        if (oldPanel) oldPanel.remove();
        currentBar.value = 0;
        overallBar.value = 0;
        currentSpeed.textContent = '';

        const totals = {finished: 0, queued: 0, duplicates: 0, skipped: 0, failed: 0, stopped: 0};
        updateOverall(0, totalFiles, totals);

        let initialProcessing;
        try {
            scheduleProgress(0, 'Requesting the existing Upload Bucket worker to stop after its current file...', '', true);
            initialProcessing = await processingState('begin_batch');
        } catch (error) {
            if (isStopped(error)) {
                totals.stopped = totalFiles;
                await finishOperation(totals, totalFiles, true);
                return;
            }
            totals.failed = totalFiles;
            totals.finished = totalFiles;
            currentLabel.textContent = 'Could not request a safe processing pause: ' + error.message;
            await finishOperation(totals, totalFiles, false);
            return;
        }

        let pauseConfirmed = Boolean(initialProcessing.processing && initialProcessing.processing.ready);
        const seen = new Set();

        for (let index = 0; index < totalFiles; index++) {
            if (stopRequested) {
                totals.stopped += totalFiles - index;
                break;
            }

            let source;
            try {
                source = await selectionAt(index);
            } catch (error) {
                totals.failed++;
                totals.finished++;
                updateOverall(totals.finished, totalFiles, totals);
                continue;
            }

            const file = source.file;
            const name = source.relativePath || file.name;
            const lineId = beginFileLine(name, file.size);
            overallLabel.textContent = 'Processing file ' + (index + 1).toLocaleString() + ' of ' + totalFiles.toLocaleString();
            overallCount.textContent = totals.finished.toLocaleString() + ' finished · current: ' + name;

            try {
                const key = fileKey(file, name);
                if (seen.has(key)) {
                    totals.skipped++;
                    finishLine(lineId, 'SKIPPED', 'skipped', 'DUPLICATE SELECTION');
                } else if (!isAllowedName(name)) {
                    seen.add(key);
                    totals.skipped++;
                    finishLine(lineId, 'SKIPPED', 'skipped', 'EXTENSION NOT ALLOWED');
                } else {
                    seen.add(key);
                    const inspection = await inspectFile(file, name, index + 1, totalFiles, lineId);
                    appendStage(lineId, 'CHECKED', 'checked');

                    const checked = await preflight(file, name, inspection, lineId);
                    if (checked.duplicate) {
                        totals.duplicates++;
                        appendStage(lineId, 'DUPLICATE', 'duplicate');
                        finishLine(lineId, 'SKIPPED', 'skipped', 'ALREADY EXISTS');
                    } else {
                        appendStage(lineId, 'READY', 'ready');
                        const uploaded = await uploadFile(file, name, inspection, index + 1, totalFiles, lineId);
                        appendStage(lineId, 'UPLOADED', 'uploaded');
                        if (!pauseConfirmed) {
                            await waitUntilPaused(initialProcessing, lineId);
                            pauseConfirmed = true;
                        }
                        const finalized = await finalizeOne(uploaded, lineId);
                        const queued = Number(finalized.queued || 0);
                        const duplicates = Number(finalized.duplicates || 0);
                        const failed = Number(finalized.failed || 0);
                        if (queued > 0) {
                            totals.queued += queued;
                            appendStage(lineId, 'QUEUED', 'queued');
                            finishLine(lineId, 'UPLOADED', 'uploaded', '');
                        } else if (duplicates > 0) {
                            totals.duplicates += duplicates;
                            appendStage(lineId, 'DUPLICATE', 'duplicate');
                            finishLine(lineId, 'SKIPPED', 'skipped', 'DUPLICATE AFTER UPLOAD');
                        } else {
                            totals.failed += Math.max(1, failed);
                            const message = Array.isArray(finalized.messages) && finalized.messages[0]
                                ? String(finalized.messages[0].message || '') : 'QUEUE RESULT FAILED';
                            finishLine(lineId, 'FAILED', 'failed', message);
                        }
                    }
                }
            } catch (error) {
                if (isStopped(error)) {
                    if (activeLineId === lineId) finishLine(lineId, 'STOPPED', 'stopped', 'STOPPED BY USER');
                    totals.stopped += totalFiles - index;
                    break;
                }
                totals.failed++;
                finishLine(lineId, 'FAILED', 'failed', error.message || 'FILE HANDLING FAILED');
            }

            totals.finished++;
            activeUploadId = '';
            activeLineId = -1;
            updateOverall(totals.finished, totalFiles, totals);
            if ((index + 1) % 100 === 0) await yieldToBrowser();
        }

        if (stopRequested) {
            totals.finished = totalFiles - totals.stopped;
            updateOverall(totals.finished, totalFiles, totals);
            await finishOperation(totals, totalFiles, true);
            return;
        }

        totals.finished = totalFiles;
        updateOverall(totals.finished, totalFiles, totals);
        await finishOperation(totals, totalFiles, false);
    });

    window.addEventListener('beforeunload', function (event) {
        if (!operationActive && !directoryScanActive) return;
        event.preventDefault();
        event.returnValue = '';
    });
}());
