(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const fileInput = document.getElementById('upload-bucket-files');
    const folderInput = document.getElementById('upload-bucket-folder');
    const button = document.getElementById('upload-bucket-button');
    const progressBox = document.getElementById('bucket-progress');
    const currentBar = document.getElementById('bucket-progress-bar');
    const overallBar = document.getElementById('bucket-overall-progress-bar');
    const currentLabel = document.getElementById('bucket-progress-label');
    const currentSpeed = document.getElementById('bucket-progress-speed');
    const overallLabel = document.getElementById('bucket-overall-progress-label');
    const overallCount = document.getElementById('bucket-overall-progress-count');
    const log = document.getElementById('bucket-log');
    if (!form || !fileInput || !button || !progressBox || !window.XMLHttpRequest || !window.fetch) return;

    const chunkUrl = progressBox.dataset.chunkUrl || 'api/v1/upload-bucket-chunk.php';
    const batchUrl = progressBox.dataset.batchUrl || 'api/v1/upload-bucket-batch.php';
    const queueUrl = progressBox.dataset.processingUrl || 'background-jobs.php?queue=catalog%3Abucket-processing';
    const csrf = progressBox.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    let operationActive = false;

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

    function sleep(milliseconds) {
        return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
    }

    function requestForm(data, onProgress, timeoutMs) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', chunkUrl, true);
            xhr.timeout = Math.max(30000, Number(timeoutMs || 120000));
            xhr.setRequestHeader('Accept', 'application/json');
            if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);
            if (typeof onProgress === 'function') xhr.upload.onprogress = onProgress;
            xhr.onload = function () {
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
            xhr.onerror = function () { reject(new Error('Upload connection error.')); };
            xhr.onabort = function () { reject(new Error('Upload request was aborted.')); };
            xhr.ontimeout = function () { reject(new Error('Upload request timed out.')); };
            xhr.send(data);
        });
    }

    async function requestFormRetry(data, onProgress, fileName, operation, timeoutMs) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            try {
                return await requestForm(data, onProgress, timeoutMs);
            } catch (error) {
                lastError = error;
                if (attempt >= 4) break;
                addLog({status: 'retrying', file: fileName, message: operation + ' failed; retry ' + (attempt + 1) + ' of 4: ' + error.message});
                await sleep(attempt * 750);
            }
        }
        throw lastError || new Error(operation + ' failed.');
    }

    async function postBatch(payload, operation, fileName) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            try {
                const response = await fetch(batchUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify(payload)
                });
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
                lastError = error;
                if (attempt >= 4) break;
                addLog({status: 'retrying', file: fileName, message: operation + ' failed; retry ' + (attempt + 1) + ' of 4: ' + error.message});
                await sleep(attempt * 750);
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

    async function waitUntilPaused(initialPromise) {
        const initial = await initialPromise;
        let body;
        if (initial.error) {
            addLog({status: 'retrying', file: 'Upload batch', message: 'The initial worker pause request failed and will be issued again: ' + initial.error.message});
            body = await processingState('begin_batch');
        } else {
            body = initial.body;
        }
        let processing = body.processing || {};
        while (!processing.ready) {
            currentBar.removeAttribute('value');
            currentLabel.textContent = 'Transfers are complete. Waiting for ' + workerDescription(processing) + ' to finish before queued files are released.';
            overallLabel.textContent = 'Waiting for previous Upload Bucket processing';
            overallCount.textContent = 'No newly uploaded file is being processed yet.';
            await sleep(1000);
            body = await processingState('batch_status');
            processing = body.processing || {};
        }
        currentBar.value = 100;
        addLog({status: 'ready', file: 'Upload batch', message: 'Previous Upload Bucket processing is stopped. Staged files can now be queued safely.'});
    }

    async function calculateIdentity(file, index, total) {
        if (!window.UnrealDbUploadHash || typeof window.UnrealDbUploadHash.hashFile !== 'function') {
            throw new Error('The browser MD5/SHA-1 component is unavailable.');
        }
        const name = displayName(file);
        currentBar.value = 0;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Calculating MD5 and SHA-1 for ' + index + ' of ' + total + ': ' + name;
        return window.UnrealDbUploadHash.hashFile(file, function (done, size) {
            currentBar.value = Math.floor((done * 100) / Math.max(1, size));
        });
    }

    async function preflight(file, identity) {
        const data = new FormData();
        data.append('action', 'preflight');
        data.append('original_name', file.name);
        data.append('relative_path', displayName(file));
        data.append('file_size', String(file.size || 0));
        if (identity) {
            data.append('md5', identity.md5);
            data.append('sha1', identity.sha1);
        }
        currentLabel.textContent = 'Checking physical duplicate: ' + displayName(file);
        return requestFormRetry(data, null, displayName(file), 'Duplicate check', 120000);
    }

    async function uploadFile(file, index, total, uploadedBytesBefore, totalBytes) {
        const name = displayName(file);
        const redirect = isRedirect(file);
        let identity = null;
        const checked = redirect
            ? await preflight(file, null)
            : await preflight(file, identity = await calculateIdentity(file, index, total));

        if (checked.duplicate) {
            const match = checked.match || {};
            addLog({
                status: 'duplicate', file: name, file_id: Number(match.file_id || 0),
                message: String(checked.message || 'Identical physical file already exists.'),
                file_size_text: fmtBytes(file.size)
            });
            currentBar.value = 100;
            return {duplicate: true};
        }
        if (redirect) {
            addLog({status: 'ready', file: name, message: String(checked.message || 'Redirect identity will be checked after decompression.')});
        }

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

        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        let acknowledged = Math.max(0, Number(upload.received_bytes || 0));
        if (received.size) addLog({status: 'uploading', file: name, message: 'Resuming ' + received.size + ' already stored chunk(s).'});

        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
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
            await requestFormRetry(data, function (event) {
                if (!event.lengthComputable) return;
                const current = Math.min(file.size, base + event.loaded);
                currentBar.value = Math.floor((current * 100) / Math.max(1, file.size));
                currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + name
                    + ' · chunk ' + (chunkIndex + 1) + '/' + totalChunks;
                overallBar.value = Math.floor(((uploadedBytesBefore + current) * 100) / Math.max(1, totalBytes));
                overallCount.textContent = index + ' of ' + total + ' · ' + fmtBytes(uploadedBytesBefore + current) + ' of ' + fmtBytes(totalBytes);
            }, name, 'Chunk ' + (chunkIndex + 1) + '/' + totalChunks, 180000);
            acknowledged += end - start;
        }

        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', uploadId);
        currentLabel.textContent = 'Verifying durable staged file ' + index + ' of ' + total + ': ' + name;
        const completed = await requestFormRetry(completeData, null, name, 'Upload completion', 120000);
        if (Array.isArray(completed.messages)) completed.messages.forEach(addLog);
        currentBar.value = 100;
        return {duplicate: false, uploadId: String(completed.upload_id || uploadId), name: name, size: Number(file.size || 0)};
    }

    async function finalizeFiles(files) {
        const totals = {queued: 0, duplicates: 0, failed: 0, retained: 0, pending_jobs: 0, worker_error: '', queue: ''};
        let queuePrepared = false;
        overallLabel.textContent = 'Queuing staged files one at a time';
        overallBar.value = 0;

        for (let index = 0; index < files.length; index++) {
            const item = files[index];
            currentBar.value = 0;
            currentLabel.textContent = 'Validating and queuing staged file ' + (index + 1) + ' of ' + files.length + ': ' + item.name;
            overallCount.textContent = index + ' of ' + files.length + ' finalised · ' + totals.queued + ' queued · '
                + totals.duplicates + ' duplicate(s) · ' + totals.failed + ' failed';
            try {
                const data = await postBatch({
                    upload_ids: [item.uploadId],
                    prepare_queue: !queuePrepared,
                    start_worker: false
                }, 'File finalisation', item.name);
                queuePrepared = true;
                totals.queue = String(data.queue || totals.queue);
                totals.queued += Number(data.queued || 0);
                totals.duplicates += Number(data.duplicates || 0);
                const fileFailures = Number(data.failed || 0);
                totals.failed += fileFailures;
                totals.retained += fileFailures;
                totals.pending_jobs = Number(data.pending_jobs || totals.pending_jobs);
                if (Array.isArray(data.messages)) {
                    data.messages.forEach(function (entry) {
                        const normalized = Object.assign({}, entry);
                        if (!normalized.file || /^[a-f0-9]{64}$/i.test(String(normalized.file))) {
                            normalized.file = item.name;
                        }
                        addLog(normalized);
                    });
                }
            } catch (error) {
                totals.retained++;
                addLog({
                    status: 'failed', file: item.name,
                    message: 'The file remains complete in durable staging and was not discarded. Retry this batch to finalise it: ' + error.message
                });
            }
            currentBar.value = 100;
            overallBar.value = Math.floor(((index + 1) * 100) / Math.max(1, files.length));
        }

        currentLabel.textContent = 'Starting Upload Bucket processing for successfully queued files...';
        try {
            const started = await postBatch({
                upload_ids: [],
                prepare_queue: !queuePrepared,
                start_worker: true
            }, 'Processing worker start', 'Upload batch');
            totals.queue = String(started.queue || totals.queue);
            totals.pending_jobs = Number(started.pending_jobs || totals.pending_jobs);
            totals.worker_error = String(started.worker_error || '');
        } catch (error) {
            totals.worker_error = error.message || 'Worker start failed.';
        }
        return totals;
    }

    function showComplete(result, transferFailures, preflightDuplicates) {
        operationActive = false;
        button.disabled = false;
        overallBar.value = 100;
        currentBar.value = 100;
        currentSpeed.textContent = '';
        const workerText = result.worker_error
            ? ' Jobs are queued, but the worker did not start: ' + result.worker_error
            : (result.pending_jobs > 0 ? ' Processing jobs are now running or ready in ' + (result.queue || 'the Upload Bucket queue') + '.' : ' No processing jobs remain.');
        currentLabel.textContent = 'Upload complete: ' + result.queued + ' queued, '
            + preflightDuplicates + ' duplicate(s) skipped before transfer, '
            + result.duplicates + ' duplicate(s) removed at final validation, '
            + result.failed + ' file validation failure(s), '
            + result.retained + ' staged file(s) retained for retry, '
            + transferFailures + ' transfer failure(s).' + workerText;
        overallLabel.textContent = 'Upload and file-by-file finalisation complete';
        overallCount.textContent = result.retained > 0
            ? result.retained + ' complete staged file(s) remain available for retry; reselect the same files to reuse the durable upload.'
            : 'Every successfully transferred file received a final queue result.';

        const panel = document.createElement('div');
        panel.className = 'bucket-next-phase';
        panel.innerHTML = '<strong>File handling complete.</strong> '
            + '<a class="button" href="' + queueUrl.replace(/"/g, '&quot;') + '">Open processing jobs</a> '
            + '<a class="button secondary" href="unverified-files.php?source_game_id=-1">Review Upload Bucket</a>';
        progressBox.appendChild(panel);
    }

    function showTerminalFailure(error) {
        operationActive = false;
        button.disabled = false;
        currentSpeed.textContent = '';
        if (!currentBar.hasAttribute('value')) currentBar.value = 0;
        currentLabel.textContent = 'Upload coordination stopped safely: ' + (error.message || 'Unknown error');
        overallLabel.textContent = 'Upload coordination requires attention';
        overallCount.textContent = 'Any file already marked uploaded remains complete in durable staging. Reselect the same files to resume without retransferring stored chunks.';
        addLog({
            status: 'failed',
            file: 'Upload batch',
            message: (error.message || 'Upload coordination failed.') + ' Completed staged files were retained.'
        });
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
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
        button.disabled = true;
        progressBox.hidden = false;
        log.textContent = '';
        const oldPanel = progressBox.querySelector('.bucket-next-phase');
        if (oldPanel) oldPanel.remove();
        currentBar.value = 0;
        overallBar.value = 0;
        currentSpeed.textContent = '';
        overallLabel.textContent = 'Uploading selected files to durable staging';
        overallCount.textContent = '0 of ' + files.length + ' files checked';

        // Capture either outcome so a pause-request failure cannot become an
        // unhandled rejection while file transfer continues.
        const pausePromise = processingState('begin_batch').then(
            function (body) { return {body: body, error: null}; },
            function (error) { return {body: null, error: error}; }
        );
        addLog({status: 'ready', file: 'Upload batch', message: 'Worker pause requested in parallel. File transfer is starting immediately.'});

        const totalBytes = Math.max(1, files.reduce(function (sum, file) { return sum + Math.max(0, Number(file.size || 0)); }, 0));
        const completed = [];
        let uploadedBytes = 0;
        let transferFailures = 0;
        let preflightDuplicates = 0;

        try {
            for (let index = 0; index < files.length; index++) {
                const file = files[index];
                try {
                    const result = await uploadFile(file, index + 1, files.length, uploadedBytes, totalBytes);
                    if (result.duplicate) preflightDuplicates++;
                    else completed.push(result);
                } catch (error) {
                    transferFailures++;
                    addLog({status: 'failed', file: displayName(file), message: error.message || 'Transfer failed.', file_size_text: fmtBytes(file.size)});
                }
                uploadedBytes += Math.max(0, Number(file.size || 0));
                overallBar.value = Math.floor((uploadedBytes * 100) / totalBytes);
                overallCount.textContent = (index + 1) + ' of ' + files.length + ' checked · ' + fmtBytes(uploadedBytes) + ' of ' + fmtBytes(totalBytes);
            }

            await waitUntilPaused(pausePromise);
            const result = await finalizeFiles(completed);
            showComplete(result, transferFailures, preflightDuplicates);
        } catch (error) {
            showTerminalFailure(error);
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (!operationActive) return;
        event.preventDefault();
        event.returnValue = '';
    });
}());
