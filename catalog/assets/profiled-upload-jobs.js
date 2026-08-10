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

    const chunkUrl = progress.dataset.chunkUrl || 'api/v1/profiled-upload-chunk.php';
    const chunkCsrf = progress.dataset.chunkCsrf || '';
    const preflightUrl = progress.dataset.preflightUrl || 'api/v1/profiled-upload-preflight.php';
    const preflightCsrf = progress.dataset.preflightCsrf || '';
    const hashWorkerUrl = progress.dataset.hashWorkerUrl || 'assets/profiled-upload-hash-worker.js';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progress.dataset.chunkBytes || 16 * 1024 * 1024));
    const containerLimit = Math.max(0, Number(progress.dataset.containerLimit || 0));
    let activeUploadId = '';
    let activeXhr = null;
    let activeHashWorker = null;
    let activeHashReject = null;
    let cancelRequested = false;
    let queuedJobIds = [];
    let stagedHashes = new Map();
    let preflightDuplicates = 0;
    let preflightWarningShown = false;
    let batchGameId = '';
    let batchStrictProfile = '1';

    function installStatusStyles() {
        const style = document.createElement('style');
        style.textContent = [
            '.upload-result { border-left:4px solid var(--line); border-radius:8px; padding:7px 9px; margin:4px 0; background:rgba(255,255,255,.025); }',
            '.upload-result-badge { display:inline-block; min-width:82px; padding:2px 7px; border:1px solid currentColor; border-radius:999px; text-align:center; }',
            '.upload-result-imported,.upload-result-verified,.upload-result-alias,.upload-result-decompressed,.upload-result-completed { border-left-color:#32d583; background:rgba(50,213,131,.08); }',
            '.upload-result-imported .upload-result-badge,.upload-result-verified .upload-result-badge,.upload-result-alias .upload-result-badge,.upload-result-decompressed .upload-result-badge,.upload-result-completed .upload-result-badge { color:#a7f3d0; }',
            '.upload-result-duplicate { border-left-color:#60a5fa; background:rgba(96,165,250,.09); }',
            '.upload-result-duplicate .upload-result-badge { color:#bfdbfe; }',
            '.upload-result-failed,.upload-result-invalid,.upload-result-rejected,.upload-result-unverified,.upload-result-dead_letter,.upload-result-cancelled { border-left-color:#ff6b7a; background:rgba(255,107,122,.08); }',
            '.upload-result-failed .upload-result-badge,.upload-result-invalid .upload-result-badge,.upload-result-rejected .upload-result-badge,.upload-result-unverified .upload-result-badge,.upload-result-dead_letter .upload-result-badge,.upload-result-cancelled .upload-result-badge { color:#fecdd3; }',
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

    function isRedirectWrapper(file) {
        return /\.uz(?:2|3)?$/i.test(file.name || '');
    }

    function preflightEligible(file) {
        return Number(file.size || 0) > 0 && !isPak(file) && !isRedirectWrapper(file);
    }

    function shouldUseChunks(file) {
        return isPak(file) || Number(file.size || 0) > configuredChunkBytes;
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
            const file = document.createElement('span');
            file.className = 'upload-result-file';
            file.textContent = String(entry.file);
            row.appendChild(file);
        }
        const message = document.createElement('span');
        message.className = 'upload-result-message';
        message.textContent = String(entry.message || '');
        row.appendChild(message);
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function setOverall(done, total, currentPercent) {
        const percent = Math.round(((done + currentPercent / 100) / Math.max(1, total)) * 100);
        overallBar.value = percent;
        overallLabel.textContent = 'Overall preflight/upload (' + percent + '%)';
        overallCount.textContent = done + ' of ' + total + ' checked/staged';
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

    function parseJsonResponse(text, status, contentType, label) {
        try {
            return JSON.parse(text || '{}');
        } catch (error) {
            const looksHtml = /^\s*(?:<!doctype\s+html|<html\b)/i.test(text || '') || /text\/html/i.test(contentType || '');
            if (looksHtml) {
                if (status === 413) {
                    throw new Error(label + ' was rejected as too large by Apache, PHP, or a reverse proxy (HTTP 413).');
                }
                throw new Error(label + ' returned an HTML error page instead of JSON' + (status ? ' (HTTP ' + status + ')' : '') + '. Check the web-server and PHP error logs.');
            }
            throw new Error(label + ' returned an invalid JSON response' + (status ? ' (HTTP ' + status + ')' : '') + '.');
        }
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
                let body;
                try {
                    body = parseJsonResponse(xhr.responseText, xhr.status, xhr.getResponseHeader('Content-Type'), 'Chunked upload request');
                } catch (error) {
                    reject(error);
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || !body.ok) {
                    reject(new Error(responseError(body, 'Chunked upload request failed with HTTP ' + xhr.status + '.')));
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

    function rememberJob(jobId) {
        const id = parseInt(jobId || 0, 10);
        if (id > 0 && !queuedJobIds.includes(id)) queuedJobIds.push(id);
    }

    async function releaseBatch() {
        if (!queuedJobIds.length) return {released: 0, requested: 0};
        const data = new FormData();
        data.append('ajax', '1');
        data.append('action', 'release_batch');
        data.append('csrf', form.querySelector('[name="csrf"]').value);
        data.append('job_ids', JSON.stringify(queuedJobIds));
        const response = await fetch(form.action || window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            body: data,
            cache: 'no-store'
        });
        const text = await response.text();
        const body = parseJsonResponse(text, response.status, response.headers.get('Content-Type'), 'Upload batch release');
        if (!response.ok || !body.ok) {
            throw new Error(responseError(body, 'Uploaded files are staged, but their background jobs could not be released.'));
        }
        if (body.worker_error) {
            throw new Error('The batch was released, but the detached worker could not be started: ' + body.worker_error);
        }
        return body;
    }

    function cancelActiveHash() {
        if (activeHashWorker) {
            activeHashWorker.terminate();
            activeHashWorker = null;
        }
        if (activeHashReject) {
            const reject = activeHashReject;
            activeHashReject = null;
            reject(new Error('Upload cancelled.'));
        }
    }

    function hashFileLocally(file, index, total) {
        return new Promise(function (resolve, reject) {
            if (!window.Worker || !hashWorkerUrl) {
                reject(new Error('Web Worker hashing is unavailable in this browser.'));
                return;
            }
            const id = String(Date.now()) + '-' + String(index) + '-' + Math.random().toString(16).slice(2);
            const worker = new Worker(hashWorkerUrl);
            const started = Date.now();
            activeHashWorker = worker;
            activeHashReject = reject;
            currentBar.value = 0;
            currentLabel.textContent = 'Checking duplicate locally ' + index + ' of ' + total + ': ' + shownName(file);

            function cleanup() {
                worker.terminate();
                if (activeHashWorker === worker) activeHashWorker = null;
                if (activeHashReject === reject) activeHashReject = null;
                speed.textContent = '';
            }

            worker.onmessage = function (event) {
                const message = event.data || {};
                if (String(message.id || '') !== id) return;
                if (message.type === 'progress') {
                    const loaded = Number(message.loaded || 0);
                    const totalBytes = Math.max(1, Number(message.total || file.size || 1));
                    const percent = Math.floor((loaded * 100) / totalBytes);
                    currentBar.value = percent;
                    currentLabel.textContent = 'Checking duplicate locally ' + index + ' of ' + total + ': ' + shownName(file) + ' (' + percent + '%)';
                    speed.textContent = bytes(loaded / Math.max(0.1, (Date.now() - started) / 1000)) + '/s hash';
                    return;
                }
                if (message.type === 'done') {
                    cleanup();
                    resolve(String(message.sha1 || '').toLowerCase());
                    return;
                }
                if (message.type === 'cancelled') {
                    cleanup();
                    reject(new Error('Upload cancelled.'));
                    return;
                }
                if (message.type === 'error') {
                    cleanup();
                    reject(new Error(String(message.message || 'Client hash failed.')));
                }
            };
            worker.onerror = function () {
                cleanup();
                reject(new Error('Client hash worker failed.'));
            };
            worker.postMessage({type: 'hash', id: id, file: file});
        });
    }

    async function serverDuplicatePreflight(file, sha1) {
        if (!preflightUrl || !preflightCsrf) {
            throw new Error('Duplicate preflight endpoint is unavailable.');
        }
        const response = await fetch(preflightUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': preflightCsrf
            },
            body: JSON.stringify({
                game_id: Number(batchGameId || 0),
                sha1: sha1,
                file_size: Number(file.size || 0)
            })
        });
        const text = await response.text();
        const body = parseJsonResponse(text, response.status, response.headers.get('Content-Type'), 'Duplicate preflight');
        if (!response.ok) {
            throw new Error(responseError(body, 'Duplicate preflight failed with HTTP ' + response.status + '.'));
        }
        return body;
    }

    async function duplicatePreflight(file, index, total) {
        if (!preflightEligible(file)) {
            return {skip: false, hashKey: ''};
        }

        let sha1;
        try {
            sha1 = await hashFileLocally(file, index, total);
        } catch (error) {
            if (cancelRequested || (error && error.message === 'Upload cancelled.')) throw error;
            if (!preflightWarningShown) {
                preflightWarningShown = true;
                addLog({status: 'skipped', message: 'Client duplicate preflight is unavailable; affected files will upload normally and remain protected by authoritative server-side duplicate detection.'});
            }
            return {skip: false, hashKey: ''};
        }

        if (!/^[a-f0-9]{40}$/.test(sha1)) {
            if (!preflightWarningShown) {
                preflightWarningShown = true;
                addLog({status: 'skipped', message: 'Client duplicate preflight returned an invalid digest; upload will continue normally.'});
            }
            return {skip: false, hashKey: ''};
        }

        const hashKey = String(file.size) + ':' + sha1;
        if (stagedHashes.has(hashKey)) {
            preflightDuplicates++;
            addLog({
                status: 'duplicate',
                file: shownName(file),
                message: 'Skipped before upload: identical SHA-1 and byte size to ' + stagedHashes.get(hashKey) + ', already staged in this browser batch.'
            });
            setOverall(index, total, 0);
            return {skip: true, hashKey: hashKey};
        }

        try {
            const preflight = await serverDuplicatePreflight(file, sha1);
            if (preflight && preflight.duplicate && preflight.match) {
                const match = preflight.match;
                preflightDuplicates++;
                addLog({
                    status: 'duplicate',
                    file: shownName(file),
                    message: 'Skipped before upload: matching SHA-1 and byte size already verified as file #' + String(match.id || '?') + ' (' + String(match.original_name || match.package_name || 'existing package') + ').'
                });
                setOverall(index, total, 0);
                return {skip: true, hashKey: hashKey};
            }
        } catch (error) {
            if (!preflightWarningShown) {
                preflightWarningShown = true;
                addLog({status: 'skipped', message: (error.message || 'Duplicate preflight failed.') + ' Upload will continue normally; server-side duplicate detection remains authoritative.'});
            }
        }

        return {skip: false, hashKey: hashKey};
    }

    function standardUpload(file, index, total) {
        return new Promise(function (resolve) {
            const data = new FormData();
            const name = shownName(file);
            data.append('ajax', '1');
            data.append('defer_worker_start', '1');
            data.append('csrf', form.querySelector('[name="csrf"]').value);
            data.append('game_id', batchGameId);
            data.append('strict_profile', batchStrictProfile);
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
                setOverall(index - 1, total, percent);
            };
            xhr.onload = function () {
                if (activeXhr === xhr) activeXhr = null;
                speed.textContent = '';
                let staged = false;
                try {
                    const response = parseJsonResponse(xhr.responseText, xhr.status, xhr.getResponseHeader('Content-Type'), 'Upload request');
                    if (xhr.status < 200 || xhr.status >= 300 || !response.ok || !Array.isArray(response.jobs) || !response.jobs.length) {
                        throw new Error(responseError(response, 'Upload could not be staged and queued (HTTP ' + xhr.status + ').'));
                    }
                    response.jobs.forEach(function (queued) {
                        rememberJob(queued.job_id);
                        addLog({
                            status: 'queued',
                            file: name,
                            message: 'Durably staged as held background job #' + queued.job_id + '. Existing workers cannot claim it until the upload batch is released.'
                        });
                    });
                    staged = true;
                } catch (error) {
                    addLog({status: 'failed', file: name, message: error.message || 'Invalid server response.'});
                }
                setOverall(index, total, 0);
                resolve(staged);
            };
            xhr.onerror = function () {
                if (activeXhr === xhr) activeXhr = null;
                speed.textContent = '';
                addLog({status: 'failed', file: name, message: 'Upload connection error.'});
                setOverall(index, total, 0);
                resolve(false);
            };
            xhr.onabort = function () {
                if (activeXhr === xhr) activeXhr = null;
                speed.textContent = '';
                addLog({status: 'cancelled', file: name, message: 'Upload cancelled.'});
                resolve(false);
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

    async function chunkedUpload(file, index, total) {
        const name = shownName(file);
        const pak = isPak(file);
        if (!chunkCsrf) throw new Error('Chunked upload CSRF token is unavailable.');
        if (pak && containerLimit > 0 && file.size > containerLimit) {
            throw new Error('PAK is ' + bytes(file.size) + '; configured container limit is ' + bytes(containerLimit) + '.');
        }
        const clientKey = [file.name, file.size, file.lastModified || 0, name, batchGameId].join('|');
        const initData = new FormData();
        initData.append('action', 'init');
        initData.append('client_key', clientKey);
        initData.append('original_name', file.name);
        initData.append('relative_path', name);
        initData.append('file_size', String(file.size));
        initData.append('game_id', batchGameId);
        initData.append('strict_profile', batchStrictProfile);

        cancelButton.hidden = false;
        currentLabel.textContent = 'Preparing resumable upload: ' + name;
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
                uploadedBytes += length;
                const percent = Math.floor((Math.min(file.size, end) * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                currentLabel.textContent = 'Resuming chunk ' + (chunkIndex + 1) + '/' + totalChunks + ': ' + name + ' (' + percent + '%)';
                setOverall(index - 1, total, percent);
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
                currentLabel.textContent = 'Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + ': ' + name + ' (' + percent + '%)';
                setOverall(index - 1, total, percent);
            }, 4);
            uploadedBytes += length;
        }

        speed.textContent = '';
        currentBar.value = 100;
        currentLabel.textContent = 'Publishing durable upload: ' + name;
        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('upload_id', activeUploadId);
        completeData.append('defer_worker_start', '1');
        const completed = await requestForm(chunkUrl, completeData);
        activeUploadId = '';
        if (!Array.isArray(completed.jobs) || !completed.jobs.length) {
            throw new Error(responseError(completed, 'Completed upload could not be queued.'));
        }
        completed.jobs.forEach(function (queued) {
            rememberJob(queued.job_id);
            addLog({
                status: 'queued',
                file: name,
                message: 'Resumable upload durably staged as held background job #' + queued.job_id + '. Existing workers cannot claim it until the upload batch is released.'
            });
        });
        return true;
    }

    async function processOne(file, index, total) {
        const name = shownName(file);
        let preflight;
        try {
            preflight = await duplicatePreflight(file, index, total);
        } catch (error) {
            if (cancelRequested) return;
            addLog({status: 'failed', file: name, message: error.message || 'Duplicate preflight failed.'});
            return;
        }
        if (preflight.skip || cancelRequested) return;

        let staged = false;
        if (!shouldUseChunks(file)) {
            staged = await standardUpload(file, index, total);
        } else {
            try {
                staged = await chunkedUpload(file, index, total);
            } catch (error) {
                if (!cancelRequested) {
                    addLog({status: 'failed', file: name, message: error.message || 'Resumable upload failed.'});
                }
            }
        }

        if (staged && preflight.hashKey) {
            stagedHashes.set(preflight.hashKey, name);
        }
        speed.textContent = '';
        activeUploadId = '';
        activeXhr = null;
        cancelButton.hidden = false;
        setOverall(index, total, 0);
    }

    cancelButton.addEventListener('click', async function () {
        cancelRequested = true;
        cancelButton.disabled = true;
        try {
            cancelActiveHash();
            if (activeXhr) activeXhr.abort();
            if (activeUploadId && chunkCsrf) {
                const data = new FormData();
                data.append('action', 'cancel');
                data.append('upload_id', activeUploadId);
                try {
                    await requestForm(chunkUrl, data);
                } catch (error) {
                    // The active request may already have released it.
                }
                activeUploadId = '';
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

        batchGameId = String(form.querySelector('[name="game_id"]').value || '');
        batchStrictProfile = String(form.querySelector('[name="strict_profile"]').value || '1');
        submitButton.disabled = true;
        progress.hidden = false;
        log.textContent = '';
        cancelRequested = false;
        queuedJobIds = [];
        stagedHashes = new Map();
        preflightDuplicates = 0;
        preflightWarningShown = false;
        cancelButton.hidden = false;
        setOverall(0, selected.length, 0);

        let attempted = 0;
        for (let index = 0; index < selected.length; index++) {
            if (cancelRequested) break;
            attempted = index + 1;
            await processOne(selected[index], index + 1, selected.length);
        }

        speed.textContent = '';
        activeXhr = null;
        activeUploadId = '';
        cancelActiveHash();
        if (queuedJobIds.length > 0) {
            currentLabel.textContent = 'Upload staging complete. Releasing ' + queuedJobIds.length + ' background job(s)...';
            try {
                const released = await releaseBatch();
                addLog({
                    status: 'completed',
                    message: (released.released || queuedJobIds.length) + ' background import job(s) released. You may leave this page; processing continues independently.'
                });
            } catch (error) {
                addLog({
                    status: 'failed',
                    message: (error.message || 'Could not release the staged batch.') + ' The staged jobs remain safe and have a 24-hour fallback availability time.'
                });
            }
        }

        currentBar.value = cancelRequested ? 0 : 100;
        const duplicateText = preflightDuplicates > 0 ? '; ' + preflightDuplicates + ' duplicate(s) skipped before upload' : '';
        if (!cancelRequested) {
            overallBar.value = 100;
            overallLabel.textContent = 'Preflight/upload complete (100%)';
            overallCount.textContent = selected.length + ' of ' + selected.length + ' checked; ' + queuedJobIds.length + ' job(s) staged' + duplicateText;
            currentLabel.textContent = 'All selected files have finished duplicate preflight/upload staging. Background processing continues independently.';
        } else {
            overallCount.textContent = attempted + ' of ' + selected.length + ' checked; ' + queuedJobIds.length + ' job(s) staged' + duplicateText;
            currentLabel.textContent = 'Upload batch cancelled. Already-staged jobs were released for background processing.';
        }
        submitButton.disabled = false;
        cancelButton.hidden = true;
        cancelRequested = false;
        batchGameId = '';
        batchStrictProfile = '1';
    });

    installStatusStyles();
}());
