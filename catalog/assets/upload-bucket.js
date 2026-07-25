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
    if (!form || !fileInput || !progressBox || !window.XMLHttpRequest || !window.fetch) return;

    const chunkUrl = progressBox.dataset.chunkUrl || 'api/v1/upload-bucket-chunk.php';
    const batchUrl = progressBox.dataset.batchUrl || 'api/v1/upload-bucket-batch.php';
    const chunkCsrf = progressBox.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    let batchTotalBytes = 1;
    let processedBytes = 0;
    let processedFiles = 0;

    function fileKey(file) {
        return [file.name, file.size, file.lastModified || 0, file.webkitRelativePath || file.name].join('|');
    }

    function selectedFiles() {
        const unique = new Map();
        Array.from(fileInput.files || []).concat(folderInput ? Array.from(folderInput.files || []) : []).forEach(function (file) {
            const key = fileKey(file);
            if (!unique.has(key)) unique.set(key, file);
        });
        return Array.from(unique.values());
    }

    function displayName(file) {
        return file.webkitRelativePath || file.name;
    }

    function fmtBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        let value = Number(bytes || 0);
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return (unit ? value.toFixed(2) : String(Math.round(value))) + ' ' + units[unit];
    }

    function requestReference(body) {
        if (!body || typeof body !== 'object') return '';
        if (body.request_id) return String(body.request_id);
        if (body.error && body.error.request_id) return String(body.error.request_id);
        if (body.error && body.error.details && body.error.details.request_id) return String(body.error.details.request_id);
        if (body.meta && body.meta.request_id) return String(body.meta.request_id);
        return '';
    }

    function responseError(body, fallback) {
        let text = fallback;
        if (body && typeof body.error === 'string' && body.error) text = body.error;
        else if (body && body.error && typeof body.error.message === 'string') text = body.error.message;
        else if (body && typeof body.message === 'string' && body.message) text = body.message;
        const reference = requestReference(body);
        return reference && text.indexOf(reference) === -1 ? text + ' | reference: ' + reference : text;
    }

    function parseJsonResponse(text, status, contentType, label) {
        try {
            return JSON.parse(text || '{}');
        } catch (error) {
            const looksHtml = /^\s*(?:<!doctype\s+html|<html\b)/i.test(text || '') || /text\/html/i.test(contentType || '');
            if (looksHtml) {
                throw new Error(label + ' returned an HTML error page instead of JSON' + (status ? ' (HTTP ' + status + ')' : '') + '. Check the web-server and PHP logs.');
            }
            throw new Error(label + ' returned invalid JSON' + (status ? ' (HTTP ' + status + ')' : '') + '.');
        }
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
        file.textContent = String(entry.file || '');
        if (entry.file_id) file.href = 'file-examine.php?id=' + encodeURIComponent(entry.file_id);
        row.appendChild(file);

        const message = document.createElement('span');
        message.className = 'bucket-result-message';
        message.textContent = String(entry.message || '') + (entry.file_size_text ? ' | size: ' + String(entry.file_size_text) : '');
        row.appendChild(message);

        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function setOverall(currentFileBytes, totalFiles) {
        const completed = Math.min(batchTotalBytes, processedBytes + Math.max(0, Number(currentFileBytes || 0)));
        const percent = Math.max(0, Math.min(100, Math.round((completed * 100) / batchTotalBytes)));
        overallBar.value = percent;
        overallLabel.textContent = 'Upload phase (' + percent + '%)';
        overallCount.textContent = processedFiles + ' of ' + totalFiles + ' transferred · ' + fmtBytes(completed) + ' of ' + fmtBytes(batchTotalBytes);
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
            if (chunkCsrf) xhr.setRequestHeader('X-CSRF-Token', chunkCsrf);
            if (typeof onProgress === 'function') xhr.upload.onprogress = onProgress;
            xhr.onload = function () {
                let body;
                try {
                    body = parseJsonResponse(xhr.responseText, xhr.status, xhr.getResponseHeader('Content-Type'), 'Chunk request');
                } catch (error) {
                    reject(error);
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || !body.ok) {
                    reject(new Error(responseError(body, 'Chunk request failed with HTTP ' + xhr.status + '.')));
                    return;
                }
                resolve(body);
            };
            xhr.onerror = function () { reject(new Error('Upload connection error. The current request can be retried.')); };
            xhr.onabort = function () { reject(new Error('Upload was aborted by the browser.')); };
            xhr.ontimeout = function () { reject(new Error('Upload request timed out after ' + Math.round(xhr.timeout / 1000) + ' seconds.')); };
            xhr.send(data);
        });
    }

    async function requestWithRetry(data, onProgress, fileName, operation, timeoutMs) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            try {
                return await requestForm(data, onProgress, timeoutMs);
            } catch (error) {
                lastError = error;
                if (attempt === 4) break;
                addLog({
                    status: 'retrying',
                    file: fileName,
                    message: operation + ' attempt ' + attempt + ' failed: ' + (error.message || 'Unknown error') + '. Retrying...'
                });
                await sleep(attempt * 1000);
            }
        }
        throw lastError || new Error(operation + ' failed.');
    }

    async function beginBatch() {
        const data = new FormData();
        data.append('action', 'begin_batch');
        currentLabel.textContent = 'Preparing durable upload staging...';
        return requestWithRetry(data, null, 'Upload batch', 'Batch preparation', 120000);
    }

    async function chunkedUpload(file, index, total) {
        const name = displayName(file);
        const clientKey = fileKey(file);
        const initData = new FormData();
        initData.append('action', 'init');
        initData.append('client_key', clientKey);
        initData.append('original_name', file.name);
        initData.append('relative_path', name);
        initData.append('file_size', String(file.size || 0));

        currentBar.value = 0;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Preparing upload ' + index + ' of ' + total + ': ' + name;
        const initialized = await requestWithRetry(initData, null, name, 'Upload initialisation', 120000);
        const upload = initialized.upload || {};
        const uploadId = String(upload.upload_id || '');
        if (!uploadId) throw new Error('Chunk upload did not return an upload ID.');

        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        let acknowledgedBytes = Math.max(0, Number(upload.received_bytes || 0));
        const started = Date.now();
        if (received.size) addLog({status: 'uploading', file: name, message: 'Resuming ' + received.size + ' previously stored chunk(s).'});
        setOverall(Math.min(file.size, acknowledgedBytes), total);

        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * chunkBytes;
            const end = Math.min(file.size, start + chunkBytes);
            const length = end - start;
            if (received.has(chunkIndex)) {
                acknowledgedBytes = Math.max(acknowledgedBytes, end);
                const resumedPercent = Math.floor((acknowledgedBytes * 100) / Math.max(1, file.size));
                currentBar.value = resumedPercent;
                currentLabel.textContent = 'Resuming chunk ' + (chunkIndex + 1) + '/' + totalChunks + ': ' + name + ' (' + resumedPercent + '%)';
                setOverall(acknowledgedBytes, total);
                continue;
            }

            const data = new FormData();
            data.append('action', 'chunk');
            data.append('upload_id', uploadId);
            data.append('chunk_index', String(chunkIndex));
            data.append('chunk', file.slice(start, end), file.name + '.part-' + chunkIndex);
            const baseBytes = acknowledgedBytes;
            await requestWithRetry(data, function (event) {
                if (!event.lengthComputable) return;
                const currentBytes = Math.min(file.size, baseBytes + event.loaded);
                const percent = Math.floor((currentBytes * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                currentSpeed.textContent = fmtBytes(Math.max(0, currentBytes - Number(upload.received_bytes || 0)) / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
                currentLabel.textContent = 'Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + ' for ' + index + ' of ' + total + ': ' + name + ' (' + percent + '%)';
                setOverall(currentBytes, total);
            }, name, 'Chunk ' + (chunkIndex + 1) + '/' + totalChunks, 180000);
            acknowledgedBytes += length;
            setOverall(acknowledgedBytes, total);
        }

        currentBar.value = 100;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Verifying transferred file ' + index + ' of ' + total + ': ' + name;
        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', uploadId);
        const completed = await requestWithRetry(completeData, null, name, 'Upload completion', 120000);
        if (Array.isArray(completed.messages)) completed.messages.forEach(addLog);
        return {uploadId: String(completed.upload_id || uploadId), name: name, size: Number(file.size || 0)};
    }

    async function finalizeBatch(uploadIds) {
        currentBar.value = 100;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'All files transferred. Removing exact duplicates and creating processing jobs...';
        const controller = window.AbortController ? new AbortController() : null;
        const timer = controller ? window.setTimeout(function () { controller.abort(); }, 300000) : 0;
        try {
            const response = await fetch(batchUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': chunkCsrf},
                body: JSON.stringify({upload_ids: uploadIds}),
                signal: controller ? controller.signal : undefined
            });
            let body;
            try {
                body = await response.json();
            } catch (error) {
                throw new Error('Batch finalisation returned invalid JSON (HTTP ' + response.status + ').');
            }
            if (!response.ok || !body.ok) {
                throw new Error(responseError(body, 'Batch finalisation failed with HTTP ' + response.status + '.'));
            }
            return body.data || {};
        } catch (error) {
            if (error && error.name === 'AbortError') {
                throw new Error('Batch finalisation timed out after 300 seconds. Uploaded sources remain in durable staging.');
            }
            throw error;
        } finally {
            if (timer) window.clearTimeout(timer);
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const files = selectedFiles();
        if (!files.length) {
            window.alert('Choose one or more files, or choose a folder/subfolders first.');
            return;
        }
        if (!chunkCsrf) {
            window.alert('Chunk upload security token is unavailable. Reload the page and try again.');
            return;
        }

        button.disabled = true;
        progressBox.hidden = false;
        log.textContent = '';
        processedBytes = 0;
        processedFiles = 0;
        batchTotalBytes = Math.max(1, files.reduce(function (sum, file) { return sum + Math.max(0, Number(file.size || 0)); }, 0));
        const completedUploads = [];
        let uploadFailed = 0;
        setOverall(0, files.length);

        try {
            await beginBatch();
        } catch (error) {
            addLog({status: 'failed', file: 'Upload batch', message: error.message || 'Could not prepare durable upload staging.'});
            currentLabel.textContent = 'Upload batch could not start.';
            button.disabled = false;
            return;
        }

        for (let index = 0; index < files.length; index++) {
            const file = files[index];
            try {
                completedUploads.push(await chunkedUpload(file, index + 1, files.length));
            } catch (error) {
                uploadFailed++;
                addLog({status: 'failed', file: displayName(file), message: error.message || 'Resumable upload failed.', file_size_text: fmtBytes(file.size)});
            }
            processedBytes += Math.max(0, Number(file.size || 0));
            processedFiles++;
            setOverall(0, files.length);
        }

        overallBar.value = 100;
        overallLabel.textContent = 'Upload phase complete (100%)';
        overallCount.textContent = files.length + ' of ' + files.length + ' attempted · ' + fmtBytes(batchTotalBytes);

        if (!completedUploads.length) {
            currentLabel.textContent = 'No files completed transfer. Failed ' + uploadFailed + '.';
            button.disabled = false;
            return;
        }

        try {
            const finalized = await finalizeBatch(completedUploads.map(function (item) { return item.uploadId; }));
            if (Array.isArray(finalized.messages)) finalized.messages.forEach(addLog);
            const queue = String(finalized.queue || 'catalog:bucket-processing');
            const workerText = finalized.worker_error
                ? ' Processing jobs are queued, but the worker could not start: ' + String(finalized.worker_error)
                : ' Processing starts now in ' + queue + '.';
            currentLabel.textContent = 'Batch ready: ' + String(finalized.queued || 0) + ' queued, '
                + String(finalized.duplicates || 0) + ' exact upload duplicate(s) removed, '
                + String(finalized.failed || 0) + ' finalisation failure(s), '
                + uploadFailed + ' transfer failure(s).' + workerText;
        } catch (error) {
            addLog({status: 'failed', file: 'Batch finalisation', message: error.message || 'Could not create processing jobs.'});
            currentLabel.textContent = 'All successful files were transferred, but batch finalisation failed. The staged uploads were retained for recovery.';
        }

        button.disabled = false;
    });
}());
