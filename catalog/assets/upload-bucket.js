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
    const statusUrl = progressBox.dataset.statusUrl || 'api/v1/job-status.php';
    const chunkCsrf = progressBox.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    const queuedForegroundWaitMs = 15000;
    const stalledForegroundWaitMs = 60000;
    const maximumForegroundWaitMs = 5 * 60 * 1000;
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
        overallLabel.textContent = 'Overall batch progress (' + percent + '%)';
        overallCount.textContent = processedFiles + ' of ' + totalFiles + ' processed · ' + fmtBytes(completed) + ' of ' + fmtBytes(batchTotalBytes);
    }

    function sleep(milliseconds) {
        return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
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
            xhr.onerror = function () { reject(new Error('Upload connection error. The current chunk can be retried.')); };
            xhr.onabort = function () { reject(new Error('Upload was aborted by the browser.')); };
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
                await sleep(attempt * 750);
            }
        }
        throw lastError || new Error('Chunk upload failed.');
    }

    async function readJob(jobId) {
        const params = new URLSearchParams({job_id: String(jobId), event_offset: '0', event_limit: '1'});
        const response = await fetch(statusUrl + '?' + params.toString(), {cache: 'no-store', credentials: 'same-origin'});
        const text = await response.text();
        const body = parseJsonResponse(text, response.status, response.headers.get('Content-Type'), 'Job status request');
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        if (!response.ok || !jobs.length) throw new Error(responseError(body, 'Redirect job status is unavailable.'));
        return jobs[0];
    }

    function backgroundMessage(jobId, reason) {
        return 'Redirect job #' + jobId + ' ' + reason + ' It remains queued/running in Background Jobs while this upload batch continues.';
    }

    async function waitForJob(jobId, fileName) {
        const startedAt = Date.now();
        let lastActivityAt = startedAt;
        let lastSignature = '';
        let statusErrors = 0;

        while (true) {
            let job;
            try {
                job = await readJob(jobId);
                statusErrors = 0;
            } catch (error) {
                statusErrors++;
                if (statusErrors >= 4) {
                    addLog({status: 'queued', file: fileName, message: backgroundMessage(jobId, 'status could not be polled reliably.')});
                    return 'queued';
                }
                await sleep(1500);
                continue;
            }

            const progress = job.progress || {};
            const percent = Math.max(0, Math.min(100, parseInt(progress.percent || (job.status === 'completed' ? 100 : 0), 10)));
            const signature = [job.status, percent, progress.message || '', job.progress_updated_at || '', job.last_heartbeat_at || '', job.updated_at || ''].join('|');
            if (signature !== lastSignature) {
                lastSignature = signature;
                lastActivityAt = Date.now();
            }

            currentBar.value = percent;
            currentSpeed.textContent = '';
            currentLabel.textContent = 'CLI redirect job #' + jobId + ' for ' + fileName + ' (' + percent + '%) — ' + (progress.message || job.status);

            if (['completed', 'failed', 'dead_letter', 'cancelled'].includes(job.status)) {
                if (job.status === 'completed') {
                    const result = job.result || {};
                    addLog({
                        status: result.status || 'completed',
                        file: result.original_name || fileName,
                        file_id: result.file_id || 0,
                        message: result.message || 'Redirect processing completed.',
                        file_size_text: result.bytes ? fmtBytes(result.bytes) : ''
                    });
                    return String(result.status || 'completed').toLowerCase();
                }
                addLog({
                    status: job.status,
                    file: fileName,
                    message: job.last_error || progress.message || 'Redirect processing did not complete.'
                });
                return 'failed';
            }

            const now = Date.now();
            if (job.status === 'queued' && (now - startedAt) >= queuedForegroundWaitMs) {
                addLog({status: 'queued', file: fileName, message: backgroundMessage(jobId, 'is waiting for the CLI worker.')});
                return 'queued';
            }
            if ((now - lastActivityAt) >= stalledForegroundWaitMs) {
                addLog({status: 'queued', file: fileName, message: backgroundMessage(jobId, 'has not reported progress for 60 seconds.')});
                return 'queued';
            }
            if ((now - startedAt) >= maximumForegroundWaitMs) {
                addLog({status: 'queued', file: fileName, message: backgroundMessage(jobId, 'is still processing after five minutes.')});
                return 'queued';
            }
            await sleep(750);
        }
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
        if (Array.isArray(completed.messages) && completed.messages.length) completed.messages.forEach(addLog);
        const jobId = Math.max(0, parseInt(completed.job_id || 0, 10));
        if (jobId > 0) return await waitForJob(jobId, name);
        if (!Array.isArray(completed.messages) || !completed.messages.length) {
            addLog({status: 'bucketed', file: name, message: 'Stored and indexed in upload bucket.', file_size_text: fmtBytes(file.size)});
        }
        return completed.messages && completed.messages[0]
            ? String(completed.messages[0].status || 'bucketed').toLowerCase()
            : 'bucketed';
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
            else if (['failed', 'dead_letter', 'cancelled'].includes(status)) counts.failed++;
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
        currentLabel.textContent = 'Upload bucket batch complete: stored/queued ' + counts.stored + ', duplicate ' + counts.duplicate + ', failed ' + counts.failed + '.';
        button.disabled = false;
    });
}());
