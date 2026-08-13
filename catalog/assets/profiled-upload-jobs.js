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

    const batchUrl = progress.dataset.batchUrl || 'api/v1/profiled-upload-batch.php';
    const batchCsrf = (form.querySelector('[name="csrf"]') || {}).value || '';
    const chunkUrl = progress.dataset.chunkUrl || 'api/v1/profiled-upload-chunk.php';
    const chunkCsrf = progress.dataset.chunkCsrf || '';
    const preflightUrl = progress.dataset.preflightUrl || 'api/v1/profiled-upload-preflight.php';
    const preflightCsrf = progress.dataset.preflightCsrf || '';
    const hashWorkerUrl = progress.dataset.hashWorkerUrl || 'assets/profiled-upload-hash-worker.js';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progress.dataset.chunkBytes || 16 * 1024 * 1024));
    const containerLimit = Math.max(0, Number(progress.dataset.containerLimit || 0));
    const MAX_LOG_ROWS = 250;

    let activeBatchId = '';
    let activeUploadId = '';
    let activeXhr = null;
    let activeHashWorker = null;
    let activeHashReject = null;
    let cancelRequested = false;
    let batchCancelled = false;
    let stagedItemCount = 0;
    let stagedHashes = new Map();
    let verifiedHashes = new Set();
    let verifiedSnapshotComplete = false;
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
            '.upload-result-queued,.upload-result-running,.upload-result-uploading,.upload-result-staged,.upload-result-skipped { border-left-color:#f6c453; background:rgba(246,196,83,.08); }',
            '.upload-result-queued .upload-result-badge,.upload-result-running .upload-result-badge,.upload-result-uploading .upload-result-badge,.upload-result-staged .upload-result-badge,.upload-result-skipped .upload-result-badge { color:#ffe29a; }'
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
        while (log.childElementCount > MAX_LOG_ROWS) {
            log.removeChild(log.firstElementChild);
        }
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

    function requestForm(url, data, csrf, onProgress, label) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            activeXhr = xhr;
            xhr.open('POST', url, true);
            if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);
            if (typeof onProgress === 'function') xhr.upload.onprogress = onProgress;
            xhr.onload = function () {
                if (activeXhr === xhr) activeXhr = null;
                let body;
                try {
                    body = parseJsonResponse(xhr.responseText, xhr.status, xhr.getResponseHeader('Content-Type'), label || 'Upload request');
                } catch (error) {
                    reject(error);
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || !body.ok) {
                    reject(new Error(responseError(body, (label || 'Upload request') + ' failed with HTTP ' + xhr.status + '.')));
                    return;
                }
                resolve(body);
            };
            xhr.onerror = function () {
                if (activeXhr === xhr) activeXhr = null;
                reject(new Error((label || 'Upload request') + ' connection error.'));
            };
            xhr.onabort = function () {
                if (activeXhr === xhr) activeXhr = null;
                reject(new Error('Upload cancelled.'));
            };
            xhr.send(data);
        });
    }

    async function initBatch() {
        const data = new FormData();
        data.append('action', 'init');
        data.append('game_id', batchGameId);
        data.append('strict_profile', batchStrictProfile);
        const body = await requestForm(batchUrl, data, batchCsrf, null, 'Upload batch initialization');
        const batch = body.batch || {};
        activeBatchId = String(batch.batch_id || '');
        if (!/^[a-f0-9]{64}$/i.test(activeBatchId)) {
            throw new Error('Server did not return a valid upload batch identifier.');
        }
        verifiedHashes = new Set(Array.isArray(body.duplicate_keys) ? body.duplicate_keys.map(String) : []);
        verifiedSnapshotComplete = body.duplicate_snapshot_complete === true;
        return body;
    }

    async function finalizeBatch() {
        if (!activeBatchId) return {job_id: 0};
        const data = new FormData();
        data.append('action', 'finalize');
        data.append('batch_id', activeBatchId);
        return requestForm(batchUrl, data, batchCsrf, null, 'Upload batch finalization');
    }

    async function cancelBatch() {
        if (!activeBatchId || batchCancelled) return;
        const data = new FormData();
        data.append('action', 'cancel');
        data.append('batch_id', activeBatchId);
        try {
            await requestForm(batchUrl, data, batchCsrf, null, 'Upload batch cancellation');
        } finally {
            batchCancelled = true;
        }
    }

    function destroyHashWorker() {
        if (activeHashWorker) {
            activeHashWorker.terminate();
            activeHashWorker = null;
        }
        activeHashReject = null;
    }

    function cancelActiveHash() {
        if (activeHashReject) {
            const reject = activeHashReject;
            activeHashReject = null;
            reject(new Error('Upload cancelled.'));
        }
        destroyHashWorker();
    }

    function hashFileLocally(file, index, total) {
        return new Promise(function (resolve, reject) {
            if (!window.Worker || !hashWorkerUrl) {
                reject(new Error('Web Worker hashing is unavailable in this browser.'));
                return;
            }
            if (!activeHashWorker) {
                activeHashWorker = new Worker(hashWorkerUrl);
            }
            const worker = activeHashWorker;
            const id = String(Date.now()) + '-' + String(index) + '-' + Math.random().toString(16).slice(2);
            const started = Date.now();
            activeHashReject = reject;
            currentBar.value = 0;
            currentLabel.textContent = 'Checking duplicate locally ' + index + ' of ' + total + ': ' + shownName(file);

            function cleanup() {
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
                    destroyHashWorker();
                    reject(new Error(String(message.message || 'Client hash failed.')));
                }
            };
            worker.onerror = function () {
                cleanup();
                destroyHashWorker();
                reject(new Error('Client hash worker failed.'));
            };
            worker.postMessage({type: 'hash', id: id, file: file});
        });
    }

    async function serverDuplicatePreflight(file, sha1) {
        if (!preflightUrl || !preflightCsrf) {
            throw new Error('Duplicate preflight endpoint is unavailable.');
        }
        const controller = window.AbortController ? new AbortController() : null;
        const timeout = controller ? window.setTimeout(function () { controller.abort(); }, 2000) : 0;
        try {
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
                }),
                signal: controller ? controller.signal : undefined
            });
            const text = await response.text();
            const body = parseJsonResponse(text, response.status, response.headers.get('Content-Type'), 'Duplicate preflight');
            if (!response.ok) {
                throw new Error(responseError(body, 'Duplicate preflight failed with HTTP ' + response.status + '.'));
            }
            return body;
        } finally {
            if (timeout) window.clearTimeout(timeout);
        }
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
                addLog({status: 'skipped', message: 'Client duplicate hashing is unavailable; affected files will upload normally and remain protected by authoritative background hashing.'});
            }
            return {skip: false, hashKey: ''};
        }

        if (!/^[a-f0-9]{40}$/.test(sha1)) {
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

        if (verifiedHashes.has(hashKey)) {
            preflightDuplicates++;
            addLog({
                status: 'duplicate',
                file: shownName(file),
                message: 'Skipped before upload: matching SHA-1 and byte size already verified in the selected game.'
            });
            setOverall(index, total, 0);
            return {skip: true, hashKey: hashKey};
        }

        // Older servers may not provide the one-time verified-hash snapshot.
        // Keep the legacy advisory lookup as a bounded fallback only; never let
        // it stall the upload stream for more than two seconds.
        if (!verifiedSnapshotComplete) {
            try {
                const preflight = await serverDuplicatePreflight(file, sha1);
                if (preflight && preflight.duplicate) {
                    preflightDuplicates++;
                    addLog({
                        status: 'duplicate',
                        file: shownName(file),
                        message: 'Skipped before upload: matching content is already verified in the selected game.'
                    });
                    setOverall(index, total, 0);
                    return {skip: true, hashKey: hashKey};
                }
            } catch (error) {
                if (!preflightWarningShown) {
                    preflightWarningShown = true;
                    addLog({status: 'skipped', message: 'Duplicate lookup was slow/unavailable; upload continues without waiting. Background duplicate detection remains authoritative.'});
                }
            }
        }

        return {skip: false, hashKey: hashKey};
    }

    function standardUpload(file, index, total) {
        return new Promise(function (resolve) {
            const data = new FormData();
            const name = shownName(file);
            data.append('action', 'stage');
            data.append('batch_id', activeBatchId);
            data.append('original_name', file.name);
            data.append('relative_path', name);
            data.append('file', file, file.name);

            const started = Date.now();
            currentBar.value = 0;
            currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + name;
            requestForm(batchUrl, data, batchCsrf, function (event) {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                currentBar.value = percent;
                speed.textContent = bytes(event.loaded / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
                currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + name + ' (' + percent + '%)';
                setOverall(index - 1, total, percent);
            }, 'Upload staging').then(function (response) {
                stagedItemCount++;
                addLog({
                    status: 'staged',
                    file: name,
                    message: 'Durably staged. No background import job has been created yet.'
                });
                setOverall(index, total, 0);
                resolve(true);
            }).catch(function (error) {
                if (!cancelRequested) {
                    addLog({status: 'failed', file: name, message: error.message || 'Upload could not be staged.'});
                }
                setOverall(index, total, 0);
                resolve(false);
            }).finally(function () {
                speed.textContent = '';
            });
        });
    }

    async function chunkRequestWithRetry(data, onProgress, attempts, label) {
        let lastError = null;
        for (let attempt = 1; attempt <= attempts; attempt++) {
            if (cancelRequested) throw new Error('Upload cancelled.');
            try {
                return await requestForm(chunkUrl, data, chunkCsrf, onProgress, label || 'Chunked upload request');
            } catch (error) {
                lastError = error;
                if (cancelRequested || attempt === attempts) break;
                await new Promise(function (resolve) { window.setTimeout(resolve, attempt * 500); });
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
        initData.append('batch_id', activeBatchId);
        initData.append('client_key', clientKey);
        initData.append('original_name', file.name);
        initData.append('relative_path', name);
        initData.append('file_size', String(file.size));
        initData.append('game_id', batchGameId);
        initData.append('strict_profile', batchStrictProfile);

        cancelButton.hidden = false;
        currentLabel.textContent = 'Preparing resumable upload: ' + name;
        const initialized = await requestForm(chunkUrl, initData, chunkCsrf, null, 'Chunked upload initialization');
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
            }, 4, 'Chunk upload');
            uploadedBytes += length;
        }

        speed.textContent = '';
        currentBar.value = 100;
        currentLabel.textContent = 'Publishing durable upload: ' + name;
        const completeData = new FormData();
        completeData.append('action', 'complete');
        completeData.append('batch_id', activeBatchId);
        completeData.append('upload_id', activeUploadId);
        const completed = await requestForm(chunkUrl, completeData, chunkCsrf, null, 'Chunked upload completion');
        activeUploadId = '';
        if (!completed.staged) {
            throw new Error(responseError(completed, 'Completed upload was not added to the upload batch.'));
        }
        stagedItemCount++;
        addLog({
            status: 'staged',
            file: name,
            message: 'Durably staged. No background import job has been created yet.'
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
                    await requestForm(chunkUrl, data, chunkCsrf, null, 'Chunked upload cancellation');
                } catch (error) {
                    // The active request may already have released it.
                }
                activeUploadId = '';
            }
            await cancelBatch();
        } catch (error) {
            addLog({status: 'failed', message: error.message || 'Could not close the cancelled upload batch.'});
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
        batchCancelled = false;
        activeBatchId = '';
        stagedItemCount = 0;
        stagedHashes = new Map();
        verifiedHashes = new Set();
        verifiedSnapshotComplete = false;
        preflightDuplicates = 0;
        preflightWarningShown = false;
        cancelButton.hidden = false;
        setOverall(0, selected.length, 0);

        try {
            currentLabel.textContent = 'Initializing isolated upload batch...';
            const initialized = await initBatch();
            const snapshotCount = verifiedHashes.size;
            addLog({
                status: 'uploading',
                message: 'Upload batch initialized. Background import jobs are disabled until all selected files finish staging.'
                    + (snapshotCount > 0 ? ' Loaded ' + snapshotCount + ' verified hash identities for local duplicate checks.' : '')
            });
        } catch (error) {
            addLog({status: 'failed', message: error.message || 'Could not initialize upload batch.'});
            submitButton.disabled = false;
            cancelButton.hidden = true;
            return;
        }

        let attempted = 0;
        for (let index = 0; index < selected.length; index++) {
            if (cancelRequested) break;
            attempted = index + 1;
            await processOne(selected[index], index + 1, selected.length);
        }

        speed.textContent = '';
        activeXhr = null;
        activeUploadId = '';
        destroyHashWorker();

        let finalJobId = 0;
        if (!cancelRequested) {
            currentLabel.textContent = 'All file transfers are complete. Starting background processing...';
            try {
                const finalized = await finalizeBatch();
                finalJobId = Number(finalized.job_id || 0);
                if (finalized.worker_error) {
                    addLog({
                        status: 'failed',
                        message: 'Upload completed and batch job #' + finalJobId + ' was queued, but the detached worker could not start: ' + finalized.worker_error
                    });
                } else if (finalJobId > 0) {
                    addLog({
                        status: 'completed',
                        message: 'Upload complete. Background batch job #' + finalJobId + ' now owns ' + stagedItemCount + ' staged file(s). You may leave this page.'
                    });
                } else {
                    addLog({status: 'completed', message: 'Upload complete; no files require background import.'});
                }
            } catch (error) {
                addLog({
                    status: 'failed',
                    message: (error.message || 'Could not finalize the staged upload batch.') + ' Staged files remain durable and no import jobs were started early.'
                });
            }
        } else if (!batchCancelled) {
            try {
                await cancelBatch();
            } catch (error) {
                addLog({status: 'failed', message: error.message || 'Could not close cancelled upload batch.'});
            }
        }

        currentBar.value = cancelRequested ? 0 : 100;
        const duplicateText = preflightDuplicates > 0 ? '; ' + preflightDuplicates + ' duplicate(s) skipped before upload' : '';
        if (!cancelRequested) {
            overallBar.value = 100;
            overallLabel.textContent = 'Preflight/upload complete (100%)';
            overallCount.textContent = selected.length + ' of ' + selected.length + ' checked; ' + stagedItemCount + ' file(s) staged' + duplicateText;
            currentLabel.textContent = finalJobId > 0
                ? 'All selected files finished staging. Background processing started only after upload completion as batch job #' + finalJobId + '.'
                : 'All selected files finished staging.';
        } else {
            overallCount.textContent = attempted + ' of ' + selected.length + ' checked; ' + stagedItemCount + ' file(s) staged before cancellation' + duplicateText;
            currentLabel.textContent = 'Upload batch cancelled. No background import jobs were started.';
        }

        submitButton.disabled = false;
        cancelButton.hidden = true;
        cancelRequested = false;
        batchGameId = '';
        batchStrictProfile = '1';
        activeBatchId = '';
        verifiedHashes = new Set();
    });

    installStatusStyles();
}());
