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
    if (!form || !fileInput || !progressBox || !window.XMLHttpRequest) return;

    const chunkUrl = progressBox.dataset.chunkUrl || 'api/v1/upload-bucket-chunk.php';
    const chunkCsrf = progressBox.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    let batchTotalBytes = 1;
    let processedBytes = 0;
    let processedFiles = 0;

    function selectedFiles() {
        return Array.from(fileInput.files || []).concat(folderInput ? Array.from(folderInput.files || []) : []);
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

    function responseError(body, fallback) {
        if (body && typeof body.error === 'string' && body.error) return body.error;
        if (body && body.error && typeof body.error.message === 'string') return body.error.message;
        if (body && typeof body.message === 'string' && body.message) return body.message;
        return fallback;
    }

    function parseJsonResponse(text, status, contentType) {
        try {
            return JSON.parse(text || '{}');
        } catch (error) {
            const looksHtml = /^\s*(?:<!doctype\s+html|<html\b)/i.test(text || '') || /text\/html/i.test(contentType || '');
            if (looksHtml) {
                throw new Error('Chunk request returned an HTML error page instead of JSON' + (status ? ' (HTTP ' + status + ')' : '') + '. Check the web-server and PHP logs.');
            }
            throw new Error('Chunk request returned invalid JSON' + (status ? ' (HTTP ' + status + ')' : '') + '.');
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

        const file = document.createElement('span');
        file.className = 'bucket-result-file';
        file.textContent = String(entry.file || '');
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
        overallLabel.textContent = 'Overall batch progress (' + percent + '%)';
        overallCount.textContent = processedFiles + ' of ' + totalFiles + ' processed · ' + fmtBytes(completed) + ' of ' + fmtBytes(batchTotalBytes);
    }

    function requestForm(data, onProgress) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', chunkUrl, true);
            xhr.setRequestHeader('Accept', 'application/json');
            if (chunkCsrf) xhr.setRequestHeader('X-CSRF-Token', chunkCsrf);
            if (typeof onProgress === 'function') xhr.upload.onprogress = onProgress;
            xhr.onload = function () {
                let body;
                try {
                    body = parseJsonResponse(xhr.responseText, xhr.status, xhr.getResponseHeader('Content-Type'));
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
            xhr.onerror = function () {
                reject(new Error('Upload connection error. The current chunk can be retried.'));
            };
            xhr.onabort = function () {
                reject(new Error('Upload was aborted by the browser.'));
            };
            xhr.send(data);
        });
    }

    async function requestChunkWithRetry(data, onProgress) {
        let lastError = null;
        for (let attempt = 1; attempt <= 4; attempt++) {
            try {
                return await requestForm(data, onProgress);
            } catch (error) {
                lastError = error;
                if (attempt === 4) break;
                await new Promise(function (resolve) { window.setTimeout(resolve, attempt * 750); });
            }
        }
        throw lastError || new Error('Chunk upload failed.');
    }

    async function chunkedUpload(file, index, total) {
        const name = displayName(file);
        const clientKey = [file.name, file.size, file.lastModified || 0, name].join('|');
        const initData = new FormData();
        initData.append('action', 'init');
        initData.append('client_key', clientKey);
        initData.append('original_name', file.name);
        initData.append('relative_path', name);
        initData.append('file_size', String(file.size || 0));

        currentBar.value = 0;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Preparing resumable upload ' + index + ' of ' + total + ': ' + name;
        const initialized = await requestForm(initData);
        const upload = initialized.upload || {};
        const uploadId = String(upload.upload_id || '');
        if (!uploadId) throw new Error('Chunk upload did not return an upload ID.');

        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        let acknowledgedBytes = Math.max(0, Number(upload.received_bytes || 0));
        const started = Date.now();
        if (received.size) {
            addLog({status: 'uploading', file: name, message: 'Resuming ' + received.size + ' previously stored chunk(s).'});
        }
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
            await requestChunkWithRetry(data, function (event) {
                if (!event.lengthComputable) return;
                const currentBytes = Math.min(file.size, baseBytes + event.loaded);
                const percent = Math.floor((currentBytes * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                currentSpeed.textContent = fmtBytes(Math.max(0, currentBytes - Number(upload.received_bytes || 0)) / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
                currentLabel.textContent = 'Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + ' for ' + index + ' of ' + total + ': ' + name + ' (' + percent + '%)';
                setOverall(currentBytes, total);
            });
            acknowledgedBytes += length;
            setOverall(acknowledgedBytes, total);
        }

        currentBar.value = 100;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Finalizing, duplicate-checking and indexing ' + index + ' of ' + total + ': ' + name;
        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', uploadId);
        const completed = await requestForm(completeData);
        if (Array.isArray(completed.messages) && completed.messages.length) {
            completed.messages.forEach(addLog);
            return String(completed.messages[0].status || 'bucketed').toLowerCase();
        }
        addLog({status: 'bucketed', file: name, message: 'Stored and indexed in upload bucket.', file_size_text: fmtBytes(file.size)});
        return 'bucketed';
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
        const counts = {stored: 0, duplicate: 0, failed: 0};
        setOverall(0, files.length);

        for (let index = 0; index < files.length; index++) {
            const file = files[index];
            let status = 'failed';
            try {
                status = await chunkedUpload(file, index + 1, files.length);
            } catch (error) {
                addLog({status: 'failed', file: displayName(file), message: error.message || 'Resumable upload failed.', file_size_text: fmtBytes(file.size)});
            }

            if (status === 'duplicate') counts.duplicate++;
            else if (status === 'failed') counts.failed++;
            else counts.stored++;
            processedBytes += Math.max(0, Number(file.size || 0));
            processedFiles++;
            setOverall(0, files.length);
        }

        overallBar.value = 100;
        overallLabel.textContent = 'Overall batch complete (100%)';
        overallCount.textContent = files.length + ' of ' + files.length + ' processed · ' + fmtBytes(batchTotalBytes);
        currentBar.value = 100;
        currentSpeed.textContent = '';
        currentLabel.textContent = 'Upload bucket batch complete: stored ' + counts.stored + ', duplicate ' + counts.duplicate + ', failed ' + counts.failed + '.';
        button.disabled = false;
    });
}());
