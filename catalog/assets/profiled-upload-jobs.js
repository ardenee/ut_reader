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
    if (!form || !fileInput || !progress || !window.XMLHttpRequest) return;

    const batchUrl = progress.dataset.batchUrl || 'api/v1/profiled-upload-batch.php';
    const batchCsrf = (form.querySelector('[name="csrf"]') || {}).value || '';
    const chunkUrl = progress.dataset.chunkUrl || 'api/v1/profiled-upload-chunk.php';
    const chunkCsrf = progress.dataset.chunkCsrf || '';
    const hashWorkerUrl = progress.dataset.hashWorkerUrl || 'assets/profiled-upload-hash-worker.js';
    const configuredChunkBytes = Math.max(1024 * 1024, Number(progress.dataset.chunkBytes || 16 * 1024 * 1024));
    const ARCHIVE_EXTENSIONS = new Set(['zip', '7z', 'rar', 'umod', 'ut2mod', 'ut4mod']);
    const MAX_LOG_ROWS = 250;

    let activeBatchId = '';
    let activeUploadId = '';
    let activeXhr = null;
    let activeHashWorker = null;
    let activeHashReject = null;
    let cancelRequested = false;
    let batchCancelled = false;
    let batchGameId = '';
    let batchStrictProfile = '1';
    let batchEngineKey = '';
    let normalUploadLimit = Math.max(0, Number(progress.dataset.normalLimit || 0));
    let containerLimit = Math.max(0, Number(progress.dataset.containerLimit || 0));
    let allowedExtensions = new Set();
    let preflightDuplicates = 0;
    let filteredItemCount = 0;
    let stagedItemCount = 0;
    let failedItemCount = 0;
    let folderSelectionStartedAt = 0;

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
            '.upload-result-queued .upload-result-badge,.upload-result-running .upload-result-badge,.upload-result-uploading .upload-result-badge,.upload-result-staged .upload-result-badge,.upload-result-skipped .upload-result-badge { color:#ffe29a; }',
            '.profiled-folder-status { display:block; margin-top:6px; }',
            '.profiled-folder-status[aria-busy="true"]::before { content:""; display:inline-block; width:.8em; height:.8em; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; margin-right:7px; vertical-align:-.1em; animation:profiled-folder-spin .8s linear infinite; }',
            '@keyframes profiled-folder-spin { to { transform:rotate(360deg); } }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function ensureFolderStatus() {
        if (!folderInput) return null;
        let status = document.getElementById('profiled-upload-folder-status');
        if (status) return status;
        status = document.createElement('span');
        status.id = 'profiled-upload-folder-status';
        status.className = 'muted small profiled-folder-status';
        status.hidden = true;
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        folderInput.insertAdjacentElement('afterend', status);
        return status;
    }

    const folderStatus = ensureFolderStatus();

    function formatDuration(milliseconds) {
        const seconds = Math.max(0, Math.round(Number(milliseconds || 0) / 1000));
        if (seconds < 60) return seconds + 's';
        const minutes = Math.floor(seconds / 60);
        const remainder = seconds % 60;
        return minutes + 'm ' + remainder + 's';
    }

    function beginFolderEnumeration() {
        if (!folderStatus) return;
        folderSelectionStartedAt = Date.now();
        folderStatus.hidden = false;
        folderStatus.setAttribute('aria-busy', 'true');
        folderStatus.textContent = 'Folder picker opened. After you choose a large folder, the browser may need several minutes to read all files and subfolders before this page can continue.';
    }

    function finishFolderEnumeration() {
        if (!folderStatus || !folderInput) return;
        const count = Number((folderInput.files || []).length || 0);
        const elapsed = folderSelectionStartedAt > 0 ? Date.now() - folderSelectionStartedAt : 0;
        folderStatus.hidden = false;
        folderStatus.setAttribute('aria-busy', 'false');
        folderStatus.textContent = count > 0
            ? count.toLocaleString() + ' file(s) selected from the folder tree'
                + (elapsed >= 1000 ? ' after ' + formatDuration(elapsed) : '') + '. Ready for preflight/upload.'
            : 'No folder files were selected.';
        folderSelectionStartedAt = 0;
    }

    if (folderInput) {
        folderInput.addEventListener('pointerdown', beginFolderEnumeration);
        folderInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') beginFolderEnumeration();
        });
        folderInput.addEventListener('change', finishFolderEnumeration);
    }

    function selectedFiles() {
        return Array.from(fileInput.files || []).concat(folderInput ? Array.from(folderInput.files || []) : []);
    }

    function shownName(file) {
        return file.webkitRelativePath || file.name;
    }

    function extensionOf(file) {
        const name = String((file && file.name) || '');
        const index = name.lastIndexOf('.');
        return index >= 0 ? name.slice(index + 1).trim().toLowerCase() : '';
    }

    function isPak(file) {
        return extensionOf(file) === 'pak';
    }

    function isRedirectWrapper(file) {
        return ['uz', 'uz2', 'uz3'].includes(extensionOf(file));
    }

    function isArchive(file) {
        return ARCHIVE_EXTENSIONS.has(extensionOf(file));
    }

    function preflightEligible(file) {
        return Number(file.size || 0) > 0 && !isPak(file) && !isRedirectWrapper(file) && !isArchive(file);
    }

    function shouldUseChunks(file) {
        return isPak(file) || isArchive(file) || Number(file.size || 0) > configuredChunkBytes;
    }

    function clientPolicy(file) {
        const extension = extensionOf(file);
        if (isPak(file)) {
            if (!['UE4', 'UE5'].includes(batchEngineKey)) {
                return {allowed: false, reason: 'PAK containers are only valid for UE4/UE5 target games.'};
            }
            if (containerLimit > 0 && Number(file.size || 0) > containerLimit) {
                return {allowed: false, reason: 'File is ' + bytes(file.size) + '; configured PAK/container limit is ' + bytes(containerLimit) + '.'};
            }
            return {allowed: Number(file.size || 0) > 0, reason: Number(file.size || 0) > 0 ? '' : 'File is empty.'};
        }
        if (isArchive(file)) {
            if (containerLimit > 0 && Number(file.size || 0) > containerLimit) {
                return {allowed: false, reason: 'File is ' + bytes(file.size) + '; configured archive/container limit is ' + bytes(containerLimit) + '.'};
            }
            return {allowed: Number(file.size || 0) > 0, reason: Number(file.size || 0) > 0 ? '' : 'File is empty.'};
        }
        if (isRedirectWrapper(file)) {
            if (normalUploadLimit > 0 && Number(file.size || 0) > normalUploadLimit) {
                return {allowed: false, reason: 'File is ' + bytes(file.size) + '; configured normal upload limit is ' + bytes(normalUploadLimit) + '.'};
            }
            return {allowed: Number(file.size || 0) > 0, reason: Number(file.size || 0) > 0 ? '' : 'File is empty.'};
        }
        if (!extension || !allowedExtensions.has(extension)) {
            return {allowed: false, reason: 'Extension .' + (extension || '(none)') + ' is not allowed by the selected game profile.'};
        }
        if (normalUploadLimit > 0 && Number(file.size || 0) > normalUploadLimit) {
            return {allowed: false, reason: 'File is ' + bytes(file.size) + '; configured normal upload limit is ' + bytes(normalUploadLimit) + '.'};
        }
        return {allowed: Number(file.size || 0) > 0, reason: Number(file.size || 0) > 0 ? '' : 'File is empty.'};
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
                    reject(new Error(responseError(body, (label || 'Upload request') + ' failed with HTTP ' + xhr.status + '.'));
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
        batchEngineKey = String(batch.engine_key || '').toUpperCase();
        allowedExtensions = new Set((Array.isArray(batch.allowed_extensions) ? batch.allowed_extensions : [])
            .map(function (extension) { return String(extension || '').trim().toLowerCase().replace(/^\.+/, ''); })
            .filter(Boolean));
        normalUploadLimit = Math.max(0, Number(batch.normal_upload_limit_bytes || normalUploadLimit || 0));
        containerLimit = Math.max(normalUploadLimit, Number(batch.container_upload_limit_bytes || containerLimit || 0));
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
            currentLabel.textContent = 'Preflight ' + index + ' of ' + total + ': ' + shownName(file);

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
                    currentLabel.textContent = 'Preflight ' + index + ' of ' + total + ': ' + shownName(file) + ' (' + percent + '%)';
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
                    reject(new Error(String(message.message || 'Client hash failed.'));
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

    async function buildUploadPlan(files, duplicateKeys, snapshotComplete) {
        const plan = [];
        const seen = new Map();
        let hashingWarningShown = false;
        const verified = new Set(Array.isArray(duplicateKeys) ? duplicateKeys.map(String) : []);

        overallLabel.textContent = 'Local duplicate/profile preflight';
        for (let i = 0; i < files.length; i++) {
            if (cancelRequested) break;
            const file = files[i];
            const index = i + 1;
            const name = shownName(file);
            const policy = clientPolicy(file);

            if (!policy.allowed) {
                filteredItemCount++;
                addLog({status: 'skipped', file: name, message: 'Skipped before upload: ' + policy.reason});
                overallBar.value = Math.round((index * 100) / Math.max(1, files.length));
                overallCount.textContent = index + ' of ' + files.length + ' preflight checked';
                continue;
            }

            if (!preflightEligible(file)) {
                plan.push({file: file, selectedIndex: index, hashKey: ''});
                overallBar.value = Math.round((index * 100) / Math.max(1, files.length));
                overallCount.textContent = index + ' of ' + files.length + ' preflight checked';
                continue;
            }

            let sha1 = '';
            try {
                sha1 = await hashFileLocally(file, index, files.length);
            } catch (error) {
                if (cancelRequested || (error && error.message === 'Upload cancelled.')) break;
                if (!hashingWarningShown) {
                    hashingWarningShown = true;
                    addLog({
                        status: 'skipped',
                        message: 'Client hashing is unavailable for some files. They will upload normally; authoritative duplicate detection remains in background processing.'
                    });
                }
            }

            const hashKey = /^[a-f0-9]{40}$/.test(sha1) ? String(file.size) + ':' + sha1 : '';
            if (hashKey && seen.has(hashKey)) {
                preflightDuplicates++;
                addLog({
                    status: 'duplicate',
                    file: name,
                    message: 'Skipped before upload: identical SHA-1 and byte size to ' + seen.get(hashKey) + ' in this selected batch.'
                });
            } else if (hashKey && verified.has(hashKey)) {
                preflightDuplicates++;
                addLog({
                    status: 'duplicate',
                    file: name,
                    message: 'Skipped before upload: matching SHA-1 and byte size are already verified in the selected game.'
                });
            } else {
                plan.push({file: file, selectedIndex: index, hashKey: hashKey});
                if (hashKey) seen.set(hashKey, name);
            }

            overallBar.value = Math.round((index * 100) / Math.max(1, files.length));
            overallCount.textContent = index + ' of ' + files.length + ' preflight checked';
        }

        if (!snapshotComplete) {
            addLog({
                status: 'skipped',
                message: 'Verified-hash snapshot was capped for this very large game. Unknown hashes will upload without per-file database lookups; background import remains authoritative.'
            });
        }
        return plan;
    }

    function updateUploadOverall(done, total, currentPercent) {
        const percent = Math.round(((done + currentPercent / 100) / Math.max(1, total)) * 100);
        overallBar.value = percent;
        overallLabel.textContent = 'Upload staging (' + percent + '%)';
        overallCount.textContent = done + ' of ' + total + ' files staged for background processing';
    }

    function standardUpload(item, uploadIndex, uploadTotal) {
        const file = item.file;
        const name = shownName(file);
        const data = new FormData();
        data.append('action', 'stage');
        data.append('batch_id', activeBatchId);
        data.append('original_name', file.name);
        data.append('relative_path', name);
        data.append('file', file, file.name);

        const started = Date.now();
        currentBar.value = 0;
        currentLabel.textContent = 'Uploading ' + uploadIndex + ' of ' + uploadTotal + ': ' + name;
        return requestForm(batchUrl, data, batchCsrf, function (event) {
            if (!event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            currentBar.value = percent;
            speed.textContent = bytes(event.loaded / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
            currentLabel.textContent = 'Uploading ' + uploadIndex + ' of ' + uploadTotal + ': ' + name + ' (' + percent + '%)';
            updateUploadOverall(uploadIndex - 1, uploadTotal, percent);
        }, 'Upload staging').then(function () {
            stagedItemCount++;
            addLog({
                status: 'staged',
                file: name,
                message: 'Durably staged. No background import job exists yet.'
            });
            updateUploadOverall(uploadIndex, uploadTotal, 0);
            return true;
        }).catch(function (error) {
            if (!cancelRequested) {
                failedItemCount++;
                addLog({status: 'failed', file: name, message: error.message || 'Upload could not be staged.'});
                updateUploadOverall(uploadIndex, uploadTotal, 0);
            }
            return false;
        }).finally(function () {
            speed.textContent = '';
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

    async function chunkedUpload(item, uploadIndex, uploadTotal) {
        const file = item.file;
        const name = shownName(file);
        const container = isPak(file) || isArchive(file);
        if (!chunkCsrf) throw new Error('Chunked upload CSRF token is unavailable.');
        const effectiveLimit = container ? containerLimit : normalUploadLimit;
        if (effectiveLimit > 0 && file.size > effectiveLimit) {
            throw new Error('File is ' + bytes(file.size) + '; configured upload limit is ' + bytes(effectiveLimit) + '.');
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

        currentBar.value = 0;
        currentLabel.textContent = 'Preparing resumable upload ' + uploadIndex + ' of ' + uploadTotal + ': ' + name;
        const initialized = await requestForm(chunkUrl, initData, chunkCsrf, null, 'Chunked upload initialization');
        const upload = initialized.upload || {};
        activeUploadId = String(upload.upload_id || '');
        const chunkBytes = Math.max(1024 * 1024, Number(upload.chunk_bytes || configuredChunkBytes));
        const totalChunks = Math.max(1, Number(upload.total_chunks || Math.ceil(file.size / chunkBytes)));
        const received = new Set((upload.received_chunks || []).map(Number));
        const started = Date.now();
        let completedBytes = 0;

        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * chunkBytes;
            const end = Math.min(file.size, start + chunkBytes);
            const length = end - start;
            if (received.has(chunkIndex)) {
                completedBytes += length;
                const percent = Math.floor((completedBytes * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                currentLabel.textContent = 'Resuming ' + uploadIndex + ' of ' + uploadTotal + ': ' + name + ' (' + percent + '%)';
                updateUploadOverall(uploadIndex - 1, uploadTotal, percent);
                continue;
            }

            const data = new FormData();
            data.append('action', 'chunk');
            data.append('upload_id', activeUploadId);
            data.append('chunk_index', String(chunkIndex));
            data.append('chunk', file.slice(start, end), file.name + '.part-' + chunkIndex);
            const baseBytes = completedBytes;
            await chunkRequestWithRetry(data, function (event) {
                if (!event.lengthComputable) return;
                const currentBytes = Math.min(file.size, baseBytes + event.loaded);
                const percent = Math.floor((currentBytes * 100) / Math.max(1, file.size));
                currentBar.value = percent;
                speed.textContent = bytes(currentBytes / Math.max(0.1, (Date.now() - started) / 1000)) + '/s';
                currentLabel.textContent = 'Uploading ' + uploadIndex + ' of ' + uploadTotal + ': ' + name + ' (' + percent + '%)';
                updateUploadOverall(uploadIndex - 1, uploadTotal, percent);
            }, 4, 'Chunk upload');
            completedBytes += length;
        }

        speed.textContent = '';
        currentBar.value = 100;
        currentLabel.textContent = 'Publishing durable upload ' + uploadIndex + ' of ' + uploadTotal + ': ' + name;
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
            message: 'Durably staged. No background import job exists yet.'
        });
        updateUploadOverall(uploadIndex, uploadTotal, 0);
        return true;
    }

    async function uploadPlan(plan) {
        overallBar.value = 0;
        overallLabel.textContent = 'Upload staging (0%)';
        overallCount.textContent = '0 of ' + plan.length + ' files staged for background processing';

        for (let i = 0; i < plan.length; i++) {
            if (cancelRequested) break;
            const item = plan[i];
            const uploadIndex = i + 1;
            if (!shouldUseChunks(item.file)) {
                await standardUpload(item, uploadIndex, plan.length);
                continue;
            }
            try {
                await chunkedUpload(item, uploadIndex, plan.length);
            } catch (error) {
                activeUploadId = '';
                speed.textContent = '';
                if (!cancelRequested) {
                    failedItemCount++;
                    addLog({status: 'failed', file: shownName(item.file), message: error.message || 'Resumable upload failed.'});
                    updateUploadOverall(uploadIndex, plan.length, 0);
                }
            }
        }
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
                    // The active request may already have completed/removed the chunk upload.
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
        const files = selectedFiles();
        if (!files.length) {
            window.alert('Choose one or more files or a folder first.');
            return;
        }

        batchGameId = String(form.querySelector('[name="game_id"]').value || '');
        batchStrictProfile = String(form.querySelector('[name="strict_profile"]').value || '1');
        submitButton.disabled = true;
        progress.hidden = false;
        log.textContent = '';
        cancelButton.hidden = false;
        cancelRequested = false;
        batchCancelled = false;
        activeBatchId = '';
        activeUploadId = '';
        activeXhr = null;
        batchEngineKey = '';
        allowedExtensions = new Set();
        preflightDuplicates = 0;
        filteredItemCount = 0;
        stagedItemCount = 0;
        failedItemCount = 0;
        overallBar.value = 0;
        currentBar.value = 0;
        speed.textContent = '';

        let initialized;
        try {
            currentLabel.textContent = 'Initializing isolated upload batch...';
            initialized = await initBatch();
            addLog({
                status: 'uploading',
                message: 'Batch initialized. Selected-game extension and size policy is checked locally before hashing or transfer. No background import jobs are created during preflight or upload.'
            });
        } catch (error) {
            addLog({status: 'failed', message: error.message || 'Could not initialize upload batch.'});
            submitButton.disabled = false;
            cancelButton.hidden = true;
            return;
        }

        const plan = await buildUploadPlan(
            files,
            initialized.duplicate_keys || [],
            initialized.duplicate_snapshot_complete === true
        );
        destroyHashWorker();

        if (!cancelRequested) {
            addLog({
                status: 'completed',
                message: 'Preflight complete: ' + plan.length + ' file(s) require upload; '
                    + preflightDuplicates + ' duplicate(s) and ' + filteredItemCount + ' unsupported/oversized file(s) skipped. Starting continuous upload staging.'
            });
            await uploadPlan(plan);
        }

        speed.textContent = '';
        activeXhr = null;
        activeUploadId = '';

        let finalJobId = 0;
        if (!cancelRequested) {
            currentLabel.textContent = 'All file transfers finished. Creating background processing batch...';
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

        if (!cancelRequested) {
            overallBar.value = 100;
            overallLabel.textContent = 'Upload complete (100%)';
            overallCount.textContent = stagedItemCount + ' staged; ' + preflightDuplicates + ' duplicate(s); '
                + filteredItemCount + ' unsupported/oversized skipped; ' + failedItemCount + ' failed';
            currentBar.value = 100;
            currentLabel.textContent = finalJobId > 0
                ? 'Background processing started only after upload completion as batch job #' + finalJobId + '.'
                : 'Upload staging complete.';
        } else {
            currentBar.value = 0;
            currentLabel.textContent = 'Upload batch cancelled. No background import jobs were started.';
            overallCount.textContent = stagedItemCount + ' file(s) staged before cancellation; ' + preflightDuplicates
                + ' duplicate(s); ' + filteredItemCount + ' unsupported/oversized skipped';
        }

        submitButton.disabled = false;
        cancelButton.hidden = true;
        cancelRequested = false;
        batchGameId = '';
        batchStrictProfile = '1';
        batchEngineKey = '';
        activeBatchId = '';
    });

    installStatusStyles();
}());
