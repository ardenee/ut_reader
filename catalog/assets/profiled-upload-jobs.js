(function () {
    'use strict';

    const form = document.getElementById('profiled-upload-form');
    const fileInput = document.getElementById('profiled-upload-files');
    const folderInput = document.getElementById('profiled-upload-folder');
    const submitButton = document.getElementById('profiled-upload-button');
    const cancelButton = document.getElementById('profiled-upload-cancel');
    const progress = document.getElementById('profiled-upload-progress');
    const currentBar = document.getElementById('upload-progress-bar');
    const overallBar = document.getElementById('overall-progress-bar');
    const currentLabel = document.getElementById('upload-progress-label');
    const overallLabel = document.getElementById('overall-progress-label');
    const overallCount = document.getElementById('overall-progress-count');
    const speed = document.getElementById('upload-progress-speed');
    const log = document.getElementById('upload-progress-log');
    if (!form || !fileInput || !progress || !window.XMLHttpRequest || !window.fetch) return;

    const queue = progress.dataset.queue || 'catalog';
    const statusUrl = progress.dataset.statusUrl || 'api/v1/job-status.php';
    const actionUrl = progress.dataset.actionUrl || 'api/v1/job-action.php';
    const runUrl = progress.dataset.runUrl || 'api/v1/job-run.php';
    const chunkUrl = progress.dataset.chunkUrl || 'api/v1/profiled-upload-chunk.php';
    const actionCsrf = progress.dataset.actionCsrf || '';
    const chunkCsrf = progress.dataset.chunkCsrf || '';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progress.dataset.chunkBytes || 16 * 1024 * 1024));
    const containerLimit = Math.max(0, Number(progress.dataset.containerLimit || 0));
    let activeJobId = 0;
    let activeUploadId = '';
    let activeXhr = null;
    let cancelRequested = false;

    function installStatusStyles() {
        const style = document.createElement('style');
        style.textContent = [
            '.upload-result { border-left:4px solid var(--line); border-radius:8px; padding:7px 9px; margin:4px 0; background:rgba(255,255,255,.025); }',
            '.upload-result-badge { display:inline-block; min-width:82px; padding:2px 7px; border:1px solid currentColor; border-radius:999px; text-align:center; }',
            '.upload-result-imported,.upload-result-verified,.upload-result-alias,.upload-result-decompressed,.upload-result-completed { border-left-color:#32d583; background:rgba(50,213,131,.08); }',
            '.upload-result-imported .upload-result-badge,.upload-result-verified .upload-result-badge,.upload-result-alias .upload-result-badge,.upload-result-decompressed .upload-result-badge,.upload-result-completed .upload-result-badge { color:#a7f3d0; }',
            '.upload-result-duplicate { border-left-color:#60a5fa; background:rgba(96,165,250,.09); }',
            '.upload-result-duplicate .upload-result-badge { color:#bfdbfe; }',
            '.upload-result-failed,.upload-result-invalid,.upload-result-rejected,.upload-result-unverified,.upload-result-dead_letter,.upload-result-cancelled,.upload-result-not_extracted,.upload-result-encrypted { border-left-color:#ff6b7a; background:rgba(255,107,122,.08); }',
            '.upload-result-failed .upload-result-badge,.upload-result-invalid .upload-result-badge,.upload-result-rejected .upload-result-badge,.upload-result-unverified .upload-result-badge,.upload-result-dead_letter .upload-result-badge,.upload-result-cancelled .upload-result-badge,.upload-result-not_extracted .upload-result-badge,.upload-result-encrypted .upload-result-badge { color:#fecdd3; }',
            '.upload-result-queued,.upload-result-running,.upload-result-uploading,.upload-result-skipped { border-left-color:#f6c453; background:rgba(246,196,83,.08); }',
            '.upload-result-queued .upload-result-badge,.upload-result-running .upload-result-badge,.upload-result-uploading .upload-result-badge,.upload-result-skipped .upload-result-badge { color:#ffe29a; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function files() {
        return Array.from(fileInput.files || []).concat(folderInput ? Array.from(folderInput.files || []) : []);
    }

    function shownName(file) {
        return file.webkitRelativePath || file.name;
    }

    function isPak(file) {
        return /\.pak$/i.test(file.name || '');
    }

    function bytes(value) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let amount = Number(value || 0);
        let unit = 0;
        while (amount >= 1024 && unit < units.length - 1) {
            amount /= 1024;
            unit++;
        }
        return (unit ? amount.toFixed(2) : String(Math.round(amount))) + ' ' + units[unit];
    }

    function addLog(entry) {
        entry = entry || {};
        const status = String(entry.status || 'info').toLowerCase();
        const row = document.createElement('div');
        row.className = 'upload-result upload-result-' + status.replace(/[^a-z0-9_-]+/g, '-');
        const badge = document.createElement('span');
        badge.className = 'upload-result-badge';
        badge.textContent = status.replace(/_/g, ' ');
        row.appendChild(badge);
        if (entry.file) {
            const file = entry.file_id ? document.createElement('a') : document.createElement('span');
            file.className = 'upload-result-file';
            file.textContent = String(entry.file);
            if (entry.file_id) file.href = 'file-examine.php?id=' + encodeURIComponent(entry.file_id);
            row.appendChild(file);
        }
        const message = document.createElement('span');
        message.className = 'upload-result-message';
        message.textContent = String(entry.message || '');
        const meta = entry.meta || {};
        if (entry.file_size_text || meta.file_size_text) message.appendChild(document.createTextNode(' | size: ' + String(entry.file_size_text || meta.file_size_text)));
        if (entry.package_guid || meta.package_guid) message.appendChild(document.createTextNode(' | GUID: ' + String(entry.package_guid || meta.package_guid)));
        row.appendChild(message);
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function setOverall(done, total, currentPercent) {
        const percent = Math.round(((done + currentPercent / 100) / Math.max(1, total)) * 100);
        overallBar.value = percent;
        overallLabel.textContent = 'Overall batch progress (' + percent + '%)';
        overallCount.textContent = done + ' of ' + total + ' complete';
    }

    function responseError(body, fallback) {
        if (body && typeof body.error === 'string' && body.error) return body.error;
        if (body && body.error && typeof body.error.message === 'string') return body.error.message;
        if (body && typeof body.message === 'string' && body.message) return body.message;
        if (body && Array.isArray(body.messages) && body.messages.length && body.messages[0] && body.messages[0].message) {
            return String(body.messages[0].message);
        }
        return fallback;
    }

    function requestForm(url, data, onProgress) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            activeXhr = xhr;
            xhr.open('POST', url, true);
            if (chunkCsrf) xhr.setRequestHeader('X-CSRF-Token', chunkCsrf);
            if (typeof onProgress === 'function') xhr.upload.onprogress = onProgress;
            xhr.onload = function () {
                if (activeXhr === xhr) activeXhr = null;
                let body = {};
                try {
                    body = JSON.parse(xhr.responseText || '{}');
                } catch (error) {
                    reject(new Error('Server returned an invalid response while receiving the upload chunk.'));
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || !body.ok) {
                    reject(new Error(responseError(body, 'Chunked upload request failed.'));
                    return;
                }
                resolve(body);
            };
            xhr.onerror = function () {
                if (activeXhr === xhr) activeXhr = null;
                reject(new Error('Upload connection error. The current chunk can be retried.'));
            };
            xhr.onabort = function () {
                if (activeXhr === xhr) activeXhr = null;
                reject(new Error('Upload cancelled.'));
            };
            xhr.send(data);
        });
    }

    async function readJob(jobId, eventOffset) {
        const params = new URLSearchParams({
            job_id: String(jobId),
            event_offset: String(Math.max(0, Number(eventOffset || 0))),
            event_limit: '500'
        });
        const response = await fetch(statusUrl + '?' + params.toString(), {cache: 'no-store', credentials: 'same-origin'});
        const body = await response.json();
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        if (!response.ok || !jobs.length) throw new Error(responseError(body, 'Job status is unavailable.'));
        const meta = body && body.meta ? body.meta : {};
        return {
            job: jobs[0],
            events: body && body.data && Array.isArray(body.data.events) ? body.data.events : [],
            eventOffset: Math.max(0, Number(meta.event_offset || eventOffset || 0)),
            eventsHasMore: Boolean(meta.events_has_more)
        };
    }

    async function ensureWorker() {
        if (!actionCsrf) return;
        try {
            await fetch(runUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': actionCsrf},
                body: JSON.stringify({queue: queue, mode: 'drain'})
            });
        } catch (error) {
            // Polling retries this while a job remains queued.
        }
    }

    function resultEntries(result, fallbackName) {
        if (!result) return [];
        if (Array.isArray(result.messages)) {
            return result.messages.map(function (entry) { return Object.assign({file: fallbackName}, entry || {}); });
        }
        return [{
            status: result.status || 'completed',
            file: result.original_name || fallbackName,
            file_id: result.file_id || 0,
            message: result.message || 'Background import complete.',
            meta: result.meta || {},
        }];
    }

    function appendSnapshotEvents(snapshot) {
        const events = snapshot && Array.isArray(snapshot.events) ? snapshot.events : [];
        events.forEach(addLog);
        return events.length;
    }

    async function waitForJob(jobId, fileName, index, total) {
        activeJobId = jobId;
        cancelButton.hidden = false;
        let queuedPolls = 0;
        let eventOffset = 0;
        let streamedEntries = 0;
        while (true) {
            let snapshot = await readJob(jobId, eventOffset);
            const current = snapshot.job;
            streamedEntries += appendSnapshotEvents(snapshot);
            eventOffset = snapshot.eventOffset;
            const state = current.progress || {};
            const percent = Math.max(0, Math.min(100, parseInt(state.percent || (current.status === 'completed' ? 100 : 0), 10)));
            currentBar.value = percent;
            currentLabel.textContent = 'Worker job #' + jobId + ' for ' + fileName + ' (' + percent + '%) — ' + (state.message || current.status);
            setOverall(index - 1, total, percent);
            if (current.status === 'queued') {
                queuedPolls++;
                if (queuedPolls === 1 || queuedPolls % 4 === 0) await ensureWorker();
            } else {
                queuedPolls = 0;
            }
            if (['completed', 'failed', 'dead_letter', 'cancelled'].includes(current.status)) {
                while (snapshot.eventsHasMore) {
                    snapshot = await readJob(jobId, eventOffset);
                    streamedEntries += appendSnapshotEvents(snapshot);
                    eventOffset = snapshot.eventOffset;
                }
                activeJobId = 0;
                cancelButton.hidden = true;
                if (current.status === 'completed') {
                    if (streamedEntries === 0) {
                        const entries = resultEntries(current.result, fileName);
                        if (entries.length) entries.forEach(addLog);
                        else addLog({status: 'completed', file: fileName, message: 'Background import complete.'});
                    }
                } else {
                    addLog({status: current.status, file: fileName, message: current.last_error || state.message || 'Background import did not complete.'});
                }
                return;
            }
            await new Promise(function (resolve) { window.setTimeout(resolve, 750); });
        }
    }

    function standardUpload(file, index, total) {
        return new Promise(function (resolve) {
            const data = new FormData();
            const name = shownName(file);
            data.append('ajax', '1');
            data.append('csrf', form.querySelector('[name="csrf"]').value);
            data.append('game_id', form.querySelector('[name="game_id"]').value);
            data.append('strict_profile', form.querySelector('[name="strict_profile"]').value);
            data.append('relative_paths[]', name);
            data.append('files[]', file, file.name);

            const xhr = new XMLHttpRequest();
            activeXhr = xhr;
            const started = Date.now();
            currentBar.value = 0;
            currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + name;
            xhr.open('POST', form.action || window.location.href, true);
            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                currentBar.value = percent;
                speed.textContent = bytes(event.loaded / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
                currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + name + ' (' + percent + '%)';
                setOverall(index - 1, total, Math.min(25, percent / 4));
            };
            xhr.onload = async function () {
                if (activeXhr === xhr) activeXhr = null;
                speed.textContent = '';
                try {
                    const response = JSON.parse(xhr.responseText || '{}');
                    if (!response.ok || !Array.isArray(response.jobs) || !response.jobs.length) {
                        throw new Error(responseError(response, 'Upload could not be queued.'));
                    }
                    const queued = response.jobs[0];
                    addLog({status: 'queued', file: name, message: 'Background job #' + queued.job_id + ' queued; detached worker start requested.'});
                    await ensureWorker();
                    await waitForJob(parseInt(queued.job_id, 10), name, index, total);
                } catch (error) {
                    addLog({status: 'failed', file: name, message: error.message || 'Invalid server response.'});
                }
                setOverall(index, total, 0);
                resolve();
            };
            xhr.onerror = function () {
                if (activeXhr === xhr) activeXhr = null;
                speed.textContent = '';
                addLog({status: 'failed', file: name, message: 'Upload connection error.'});
                setOverall(index, total, 0);
                resolve();
            };
            xhr.onabort = function () {
                if (activeXhr === xhr) activeXhr = null;
                speed.textContent = '';
                addLog({status: 'cancelled', file: name, message: 'Upload cancelled.'});
                resolve();
            };
            xhr.send(data);
        });
    }

    async function chunkRequestWithRetry(data, onProgress, attempts) {
        let lastError = null;
        for (let attempt = 1; attempt <= attempts; attempt++) {
            if (cancelRequested) throw new Error('Upload cancelled.');
            try {
                return await requestForm(chunkUrl, data, onProgress);
            } catch (error) {
                lastError = error;
                if (cancelRequested || attempt === attempts) break;
                await new Promise(function (resolve) { window.setTimeout(resolve, attempt * 750); });
            }
        }
        throw lastError || new Error('Chunk upload failed.');
    }

    async function chunkedPakUpload(file, index, total) {
        const name = shownName(file);
        if (!chunkCsrf) throw new Error('Chunked upload CSRF token is unavailable.');
        if (containerLimit > 0 && file.size > containerLimit) {
            throw new Error('PAK is ' + bytes(file.size) + '; configured container limit is ' + bytes(containerLimit) + '.');
        }
        const gameId = form.querySelector('[name="game_id"]').value;
        const strictProfile = form.querySelector('[name="strict_profile"]').value;
        const clientKey = [file.name, file.size, file.lastModified || 0, name, gameId].join('|');
        const initData = new FormData();
        initData.append('action', 'init');
        initData.append('client_key', clientKey);
        initData.append('original_name', file.name);
        initData.append('relative_path', name);
        initData.append('file_size', String(file.size));
        initData.append('game_id', gameId);
        initData.append('strict_profile', strictProfile);

        cancelRequested = false;
        cancelButton.hidden = false;
        currentLabel.textContent = 'Preparing resumable PAK upload: ' + name;
        const initialized = await requestForm(chunkUrl, initData);
        const upload = initialized.upload || {};
        activeUploadId = String(upload.upload_id || '');
        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        const started = Date.now();
        let uploadedBytes = Number(upload.received_bytes || 0);
        if (received.size) {
            addLog({status: 'uploading', file: name, message: 'Resuming ' + received.size + ' previously stored chunk(s).'});
        }

        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * chunkBytes;
            const end = Math.min(file.size, start + chunkBytes);
            const length = end - start;
            if (received.has(chunkIndex)) {
                const percent = Math.floor((Math.min(file.size, end) * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                currentLabel.textContent = 'Resuming PAK ' + (chunkIndex + 1) + '/' + totalChunks + ': ' + name + ' (' + percent + '%)';
                setOverall(index - 1, total, percent / 4);
                continue;
            }
            const data = new FormData();
            data.append('action', 'chunk');
            data.append('upload_id', activeUploadId);
            data.append('chunk_index', String(chunkIndex));
            data.append('chunk', file.slice(start, end), file.name + '.part-' + chunkIndex);
            const baseUploaded = uploadedBytes;
            await chunkRequestWithRetry(data, function (event) {
                if (!event.lengthComputable) return;
                const currentBytes = Math.min(file.size, baseUploaded + event.loaded);
                const percent = Math.floor((currentBytes * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                speed.textContent = bytes(currentBytes / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
                currentLabel.textContent = 'Uploading PAK chunk ' + (chunkIndex + 1) + '/' + totalChunks + ': ' + name + ' (' + percent + '%)';
                setOverall(index - 1, total, percent / 4);
            }, 4);
            uploadedBytes += length;
        }

        speed.textContent = '';
        currentBar.value = 100;
        currentLabel.textContent = 'Finalizing durable PAK upload: ' + name;
        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', activeUploadId);
        const completed = await requestForm(chunkUrl, completeData);
        activeUploadId = '';
        if (!Array.isArray(completed.jobs) || !completed.jobs.length) {
            throw new Error(responseError(completed, 'Completed PAK upload could not be queued.'));
        }
        const queued = completed.jobs[0];
        addLog({status: 'queued', file: name, message: 'Resumable PAK upload complete; background job #' + queued.job_id + ' queued.'});
        await ensureWorker();
        await waitForJob(parseInt(queued.job_id, 10), name, index, total);
    }

    async function processOne(file, index, total) {
        const name = shownName(file);
        if (!isPak(file)) {
            await standardUpload(file, index, total);
            return;
        }
        try {
            await chunkedPakUpload(file, index, total);
        } catch (error) {
            if (!cancelRequested) {
                addLog({status: 'failed', file: name, message: error.message || 'Resumable PAK upload failed.'});
            }
        } finally {
            speed.textContent = '';
            activeUploadId = '';
            activeXhr = null;
            cancelButton.hidden = activeJobId < 1;
            setOverall(index, total, 0);
        }
    }

    cancelButton.addEventListener('click', async function () {
        cancelRequested = true;
        cancelButton.disabled = true;
        try {
            if (activeXhr) activeXhr.abort();
            if (activeUploadId && chunkCsrf) {
                const data = new FormData();
                data.append('action', 'cancel');
                data.append('upload_id', activeUploadId);
                try {
                    await requestForm(chunkUrl, data);
                } catch (error) {
                    // A concurrent chunk request may already have released it.
                }
                activeUploadId = '';
            } else if (activeJobId && actionCsrf) {
                await fetch(actionUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': actionCsrf},
                    body: JSON.stringify({action: 'cancel', job_id: activeJobId, reason: 'Cancelled from Profiled Upload.'}),
                });
            }
        } finally {
            cancelButton.disabled = false;
        }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const selected = files();
        if (!selected.length) {
            window.alert('Choose one or more files or a folder first.');
            return;
        }
        submitButton.disabled = true;
        progress.hidden = false;
        log.textContent = '';
        cancelRequested = false;
        setOverall(0, selected.length, 0);
        for (let index = 0; index < selected.length; index++) {
            if (cancelRequested) break;
            await processOne(selected[index], index + 1, selected.length);
        }
        currentBar.value = cancelRequested ? 0 : 100;
        if (!cancelRequested) {
            overallBar.value = 100;
            overallLabel.textContent = 'Overall batch complete (100%)';
            overallCount.textContent = selected.length + ' of ' + selected.length + ' processed';
            currentLabel.textContent = 'Upload and import batch complete.';
        } else {
            currentLabel.textContent = 'Upload batch cancelled.';
        }
        submitButton.disabled = false;
        cancelButton.hidden = true;
        cancelRequested = false;
    });

    installStatusStyles();
})();
