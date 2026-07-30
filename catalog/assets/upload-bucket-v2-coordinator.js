(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const fileInput = document.getElementById('upload-bucket-files');
    const folderInput = document.getElementById('upload-bucket-folder');
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
    if (!form || !fileInput || !startButton || !stopButton || !progressBox || !window.XMLHttpRequest || !window.fetch || !window.Worker) return;

    const chunkUrl = progressBox.dataset.chunkUrl || 'api/v1/upload-bucket-chunk.php';
    const batchUrl = progressBox.dataset.batchUrl || 'api/v1/upload-bucket-batch.php';
    const workerUrl = progressBox.dataset.inspectorWorkerUrl || 'assets/upload-file-inspector-worker.js';
    const queueUrl = progressBox.dataset.processingUrl || 'background-jobs.php?queue=catalog%3Abucket-processing';
    const csrf = progressBox.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));

    let operationActive = false;
    let stopRequested = false;
    let activeXhr = null;
    let activeFetchController = null;
    let activeInspector = null;
    let activeInspectorReject = null;
    let activeUploadId = '';
    let queuePrepared = false;

    function stoppedError() {
        const error = new Error('Stopped by user.');
        error.name = 'AbortError';
        return error;
    }

    function isStopped(error) {
        return stopRequested || (error && error.name === 'AbortError');
    }

    function fileKey(file) {
        return [file.name, file.size, file.lastModified || 0, file.webkitRelativePath || file.name].join('|');
    }

    function selectedFiles() {
        const unique = new Map();
        Array.from(fileInput.files || [])
            .concat(folderInput ? Array.from(folderInput.files || []) : [])
            .forEach(function (file) {
                const key = fileKey(file);
                if (!unique.has(key)) unique.set(key, file);
            });
        return Array.from(unique.values());
    }

    function displayName(file) {
        return file.webkitRelativePath || file.name;
    }

    function isRedirect(file) {
        return /\.(?:uz|uz2|uz3)$/i.test(String(file && file.name ? file.name : ''));
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

    function addLog(entry) {
        entry = entry || {};
        const status = String(entry.status || 'info').toLowerCase();
        const row = document.createElement('div');
        row.className = 'bucket-result bucket-result-' + status.replace(/[^a-z0-9_-]+/g, '-');

        const badge = document.createElement('span');
        badge.className = 'bucket-result-badge';
        badge.textContent = status.replace(/_/g, ' ');
        row.appendChild(badge);

        const file = entry.file_id ? document.createElement('a') : document.createElement('span');
        file.className = 'bucket-result-file';
        file.textContent = String(entry.file || 'Upload batch');
        if (entry.file_id) file.href = 'file-examine.php?id=' + encodeURIComponent(entry.file_id);
        row.appendChild(file);

        const message = document.createElement('span');
        message.className = 'bucket-result-message';
        message.textContent = String(entry.message || '')
            + (entry.file_size_text ? ' | size: ' + String(entry.file_size_text) : '');
        row.appendChild(message);

        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
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

            function finish() {
                if (activeXhr === xhr) activeXhr = null;
            }

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

    async function requestFormRetry(data, onProgress, fileName, operation, timeoutMs) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            if (stopRequested) throw stoppedError();
            try {
                return await requestForm(data, onProgress, timeoutMs);
            } catch (error) {
                if (isStopped(error)) throw stoppedError();
                lastError = error;
                if (attempt >= 4) break;
                addLog({status: 'retrying', file: fileName, message: operation + ' failed; retry ' + (attempt + 1) + ' of 4: ' + error.message});
                await sleep(attempt * 750);
            }
        }
        throw lastError || new Error(operation + ' failed.');
    }

    async function postBatch(payload, operation, fileName, allowWhenStopped) {
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
                return body.data || {};
            } catch (error) {
                if (activeFetchController === controller) activeFetchController = null;
                if ((stopRequested && !allowWhenStopped) || error.name === 'AbortError') throw stoppedError();
                lastError = error;
                if (attempt >= 4) break;
                addLog({status: 'retrying', file: fileName, message: operation + ' failed; retry ' + (attempt + 1) + ' of 4: ' + error.message});
                await (allowWhenStopped ? delay(attempt * 750) : sleep(attempt * 750));
            }
        }
        throw lastError || new Error(operation + ' failed.');
    }

    async function processingState(action) {
        const data = new FormData();
        data.append('action', action);
        return requestFormRetry(data, null, 'Upload batch', action === 'begin_batch' ? 'Worker pause request' : 'Worker status check', 120000);
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

    async function waitUntilPaused(initialBody) {
        let body = initialBody;
        let processing = body && body.processing ? body.processing : {};
        while (!processing.ready) {
            if (stopRequested) throw stoppedError();
            currentBar.removeAttribute('value');
            currentLabel.textContent = 'Waiting for ' + workerDescription(processing) + ' to finish its current file.';
            await sleep(1000);
            body = await processingState('batch_status');
            processing = body.processing || {};
        }
        currentBar.value = 100;
    }

    function inspectFile(file, index, total) {
        if (stopRequested) return Promise.reject(stoppedError());
        return new Promise(function (resolve, reject) {
            const worker = new Worker(workerUrl);
            activeInspector = worker;
            const requestId = String(Date.now()) + '-' + String(index);
            activeInspectorReject = reject;
            currentBar.value = 0;
            currentSpeed.textContent = '';
            currentLabel.textContent = 'Inspecting header for ' + index + ' of ' + total + ': ' + displayName(file);

            function finish() {
                if (activeInspector === worker) activeInspector = null;
                activeInspectorReject = null;
                worker.terminate();
            }

            worker.addEventListener('message', function (event) {
                const data = event.data || {};
                if (String(data.id || '') !== requestId) return;
                if (data.type === 'progress') {
                    const loaded = Math.max(0, Number(data.loaded || 0));
                    const size = Math.max(1, Number(data.total || file.size || 1));
                    currentBar.value = Math.floor((loaded * 100) / size);
                    if (data.phase === 'hash') {
                        currentLabel.textContent = 'Calculating MD5 and SHA-1 for ' + index + ' of ' + total + ': ' + displayName(file);
                    } else {
                        currentLabel.textContent = 'Checking Unreal file header for ' + index + ' of ' + total + ': ' + displayName(file);
                    }
                    return;
                }
                if (data.type === 'result') {
                    finish();
                    resolve(data.result || {});
                    return;
                }
                if (data.type === 'error') {
                    finish();
                    reject(new Error(String(data.message || 'File inspection failed.')));
                }
            });
            worker.addEventListener('error', function () {
                finish();
                reject(new Error('The browser file-inspection worker failed.'));
            });
            worker.postMessage({type: 'inspect', id: requestId, file: file});
        });
    }

    async function preflight(file, inspection) {
        const data = new FormData();
        data.append('action', 'preflight');
        data.append('original_name', file.name);
        data.append('relative_path', displayName(file));
        data.append('file_size', String(file.size || 0));
        if (inspection && inspection.md5 && inspection.sha1) {
            data.append('md5', inspection.md5);
            data.append('sha1', inspection.sha1);
        }
        currentBar.value = 0;
        currentLabel.textContent = 'Checking database identity: ' + displayName(file);
        return requestFormRetry(data, null, displayName(file), 'Duplicate check', 120000);
    }

    async function uploadFile(file, inspection, index, total) {
        const name = displayName(file);
        const identity = inspection && inspection.md5 && inspection.sha1 ? inspection : null;
        const initData = new FormData();
        initData.append('action', 'init');
        initData.append('client_key', fileKey(file));
        initData.append('original_name', file.name);
        initData.append('relative_path', name);
        initData.append('file_size', String(file.size || 0));
        if (identity) {
            initData.append('md5', identity.md5);
            initData.append('sha1', identity.sha1);
        }
        currentLabel.textContent = 'Opening durable staging for ' + index + ' of ' + total + ': ' + name;
        const initialized = await requestFormRetry(initData, null, name, 'Upload initialisation', 120000);
        const upload = initialized.upload || {};
        const uploadId = String(upload.upload_id || '');
        if (!uploadId) throw new Error('Upload initialisation did not return an upload ID.');
        activeUploadId = uploadId;

        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        let acknowledged = Math.max(0, Number(upload.received_bytes || 0));
        if (received.size) addLog({status: 'uploading', file: name, message: 'Resuming ' + received.size + ' already stored chunk(s).'});

        currentBar.value = Math.floor((acknowledged * 100) / Math.max(1, file.size));
        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            if (stopRequested) throw stoppedError();
            const start = chunkIndex * chunkBytes;
            const end = Math.min(file.size, start + chunkBytes);
            if (received.has(chunkIndex)) {
                acknowledged = Math.max(acknowledged, end);
                currentBar.value = Math.floor((acknowledged * 100) / Math.max(1, file.size));
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
                currentBar.value = Math.floor((current * 100) / Math.max(1, file.size));
                currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + name
                    + ' · chunk ' + (chunkIndex + 1) + '/' + totalChunks;
                currentSpeed.textContent = fmtBytes(event.loaded / elapsed) + '/s';
            }, name, 'Chunk ' + (chunkIndex + 1) + '/' + totalChunks, 180000);
            acknowledged += end - start;
        }

        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', uploadId);
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Verifying staged file ' + index + ' of ' + total + ': ' + name;
        const completed = await requestFormRetry(completeData, null, name, 'Upload completion', 120000);
        if (Array.isArray(completed.messages)) completed.messages.forEach(addLog);
        activeUploadId = '';
        currentBar.value = 100;
        return {uploadId: String(completed.upload_id || uploadId), name: name, size: Number(file.size || 0)};
    }

    async function finalizeOne(item) {
        currentBar.value = 0;
        currentLabel.textContent = 'Validating and queuing: ' + item.name;
        const data = await postBatch({
            upload_ids: [item.uploadId],
            prepare_queue: !queuePrepared,
            start_worker: false
        }, 'File finalisation', item.name, false);
        queuePrepared = true;
        if (Array.isArray(data.messages)) {
            data.messages.forEach(function (entry) {
                const normalized = Object.assign({}, entry);
                if (!normalized.file || /^[a-f0-9]{64}$/i.test(String(normalized.file))) normalized.file = item.name;
                addLog(normalized);
            });
        }
        currentBar.value = 100;
        return data;
    }

    async function startProcessing(allowWhenStopped) {
        if (!queuePrepared) return {pending_jobs: 0, worker_error: ''};
        currentLabel.textContent = 'Starting Upload Bucket processing for queued files...';
        return postBatch({
            upload_ids: [],
            prepare_queue: false,
            start_worker: true
        }, 'Processing worker start', 'Upload batch', Boolean(allowWhenStopped));
    }

    function setButtons(active) {
        startButton.disabled = active;
        stopButton.disabled = !active;
        stopButton.hidden = !active;
    }

    function updateOverall(done, total, totals) {
        overallBar.value = Math.floor((done * 100) / Math.max(1, total));
        overallLabel.textContent = done < total ? 'Processing file ' + (done + 1) + ' of ' + total : 'Selected files complete';
        overallCount.textContent = done + ' of ' + total + ' finished · '
            + totals.queued + ' queued · ' + totals.duplicates + ' duplicate(s) · '
            + totals.failed + ' failed · ' + totals.stopped + ' stopped';
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
            if (!isStopped(error)) {
                workerResult.worker_error = error.message || 'Worker start failed.';
                addLog({status: 'failed', file: 'Upload batch', message: 'Queued files remain available, but the processing worker did not start: ' + workerResult.worker_error});
            }
        }

        operationActive = false;
        setButtons(false);
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
                + totals.failed + ' failed.';
        }
        const workerText = workerResult.worker_error
            ? ' Worker start failed: ' + workerResult.worker_error
            : (Number(workerResult.pending_jobs || 0) > 0 ? ' Processing jobs are running or queued.' : ' No processing jobs remain.');
        overallCount.textContent = totals.finished + ' of ' + totalFiles + ' finished · '
            + totals.queued + ' queued · ' + totals.duplicates + ' duplicate(s) · '
            + totals.failed + ' failed.' + workerText;
        addCompletionPanel();
    }

    stopButton.addEventListener('click', function () {
        if (!operationActive || stopRequested) return;
        stopRequested = true;
        stopButton.disabled = true;
        currentLabel.textContent = 'Stopping after the active browser/server operation is aborted...';
        if (activeInspector) {
            activeInspector.terminate();
            activeInspector = null;
        }
        if (activeInspectorReject) {
            const reject = activeInspectorReject;
            activeInspectorReject = null;
            reject(stoppedError());
        }
        if (activeXhr) activeXhr.abort();
        if (activeFetchController) activeFetchController.abort();
        addLog({status: 'stopped', file: 'Upload batch', message: 'Stop requested. No later file will be inspected or uploaded.'});
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (operationActive) return;
        const files = selectedFiles();
        if (!files.length) {
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
        setButtons(true);
        progressBox.hidden = false;
        log.textContent = '';
        const oldPanel = progressBox.querySelector('.bucket-next-phase');
        if (oldPanel) oldPanel.remove();
        currentBar.value = 0;
        overallBar.value = 0;
        currentSpeed.textContent = '';

        const totals = {finished: 0, queued: 0, duplicates: 0, failed: 0, stopped: 0};
        updateOverall(0, files.length, totals);

        let initialProcessing;
        try {
            currentLabel.textContent = 'Requesting the existing Upload Bucket worker to stop after its current file...';
            initialProcessing = await processingState('begin_batch');
        } catch (error) {
            if (isStopped(error)) {
                totals.stopped = files.length;
                await finishOperation(totals, files.length, true);
                return;
            }
            addLog({status: 'failed', file: 'Upload batch', message: 'Could not request a safe processing pause: ' + error.message});
            totals.failed = files.length;
            totals.finished = files.length;
            await finishOperation(totals, files.length, false);
            return;
        }

        let pauseConfirmed = Boolean(initialProcessing.processing && initialProcessing.processing.ready);

        for (let index = 0; index < files.length; index++) {
            if (stopRequested) {
                totals.stopped += files.length - index;
                break;
            }
            const file = files[index];
            const name = displayName(file);
            overallLabel.textContent = 'Processing file ' + (index + 1) + ' of ' + files.length;
            overallCount.textContent = totals.finished + ' finished · current: ' + name;
            try {
                const inspection = await inspectFile(file, index + 1, files.length);
                addLog({
                    status: 'checked',
                    file: name,
                    message: inspection.header && inspection.header.description
                        ? 'Client-side header check passed: ' + inspection.header.description + '.'
                        : 'Client-side inspection passed.'
                });

                const checked = await preflight(file, inspection);
                if (checked.duplicate) {
                    const match = checked.match || {};
                    totals.duplicates++;
                    addLog({
                        status: 'duplicate', file: name, file_id: Number(match.file_id || 0),
                        message: String(checked.message || 'Identical physical file already exists.'),
                        file_size_text: fmtBytes(file.size)
                    });
                } else {
                    if (isRedirect(file)) {
                        addLog({status: 'ready', file: name, message: 'The compressed redirect wrapper will be uploaded as-is; server processing will decompress it and compare/store the uncompressed package.'});
                    }
                    const uploaded = await uploadFile(file, inspection, index + 1, files.length);
                    if (!pauseConfirmed) {
                        await waitUntilPaused(initialProcessing);
                        pauseConfirmed = true;
                    }
                    const finalized = await finalizeOne(uploaded);
                    totals.queued += Number(finalized.queued || 0);
                    totals.duplicates += Number(finalized.duplicates || 0);
                    totals.failed += Number(finalized.failed || 0);
                }
            } catch (error) {
                if (isStopped(error)) {
                    totals.stopped += files.length - index;
                    break;
                }
                totals.failed++;
                addLog({status: 'failed', file: name, message: error.message || 'File handling failed.', file_size_text: fmtBytes(file.size)});
            }
            totals.finished++;
            activeUploadId = '';
            updateOverall(totals.finished, files.length, totals);
        }

        if (stopRequested) {
            totals.finished = files.length - totals.stopped;
            updateOverall(totals.finished, files.length, totals);
            await finishOperation(totals, files.length, true);
            return;
        }

        totals.finished = files.length;
        updateOverall(totals.finished, files.length, totals);
        await finishOperation(totals, files.length, false);
    });

    window.addEventListener('beforeunload', function (event) {
        if (!operationActive) return;
        event.preventDefault();
        event.returnValue = '';
    });
}());
