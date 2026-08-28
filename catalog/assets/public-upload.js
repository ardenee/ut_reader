(function () {
    'use strict';

    const form = document.getElementById('public-upload-form');
    const fileInput = document.getElementById('public-upload-files');
    const folderInput = document.getElementById('public-upload-folder');
    const folderButton = document.getElementById('public-upload-folder-button');
    const folderSummary = document.getElementById('public-upload-folder-summary');
    const startButton = document.getElementById('public-upload-start');
    const stopButton = document.getElementById('public-upload-stop');
    const progressBox = document.getElementById('public-upload-progress');
    const progressBar = document.getElementById('public-upload-progress-bar');
    const progressLabel = document.getElementById('public-upload-progress-label');
    const summary = document.getElementById('public-upload-summary');
    const log = document.getElementById('public-upload-log');
    if (!form || !fileInput || !startButton || !stopButton || !progressBox || !progressBar || !progressLabel || !summary || !log
        || !window.fetch || !window.Worker || !window.XMLHttpRequest) return;

    const preflightUrl = String(progressBox.dataset.preflightUrl || 'api/v1/public-upload-preflight.php');
    const uploadUrl = String(progressBox.dataset.uploadUrl || 'api/v1/public-upload.php');
    const workerUrl = String(progressBox.dataset.workerUrl || 'assets/upload-file-inspector-worker-compatible.js');
    const csrf = String(progressBox.dataset.csrf || '');
    const chunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    const maxFileBytes = Math.max(1, Number(progressBox.dataset.maxFileBytes || 0));
    const BATCH_FILES = 100;
    const MAX_LOG_LINES = 500;

    let allowed = [];
    try {
        allowed = JSON.parse(form.dataset.allowedExtensions || '[]');
    } catch (error) {
        allowed = [];
    }
    const allowedExtensions = new Set(allowed.map(function (value) {
        return String(value || '').trim().toLowerCase().replace(/^\.+/, '');
    }).filter(Boolean));
    ['uz2', 'uz3'].forEach(function (extension) { allowedExtensions.add(extension); });

    let operationActive = false;
    let stopRequested = false;
    let folderHandle = null;
    let activeXhr = null;
    let activeController = null;
    let inspector = null;
    let inspectorSequence = 0;
    let inspectorPending = null;
    let processedFiles = 0;
    let pendingValidation = [];
    const counters = {
        checked: 0,
        accepted: 0,
        skipped: 0,
        rejected: 0,
        uploaded: 0,
        unverified: 0,
        duplicates: 0,
        failed: 0
    };

    function stoppedError() {
        const error = new Error('Stopped by user.');
        error.name = 'AbortError';
        return error;
    }

    function ensureRunning() {
        if (stopRequested) throw stoppedError();
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = Math.max(0, Number(bytes || 0));
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return (unit ? value.toFixed(2) : String(Math.round(value))) + ' ' + units[unit];
    }

    function extensionOf(name) {
        const clean = String(name || '').replace(/\\/g, '/').split('/').pop() || '';
        const point = clean.lastIndexOf('.');
        return point >= 0 ? clean.slice(point + 1).trim().toLowerCase() : '';
    }

    function addLog(status, name, message) {
        const row = document.createElement('div');
        row.className = 'public-upload-log-line public-upload-log-' + String(status || 'info').replace(/[^a-z0-9_-]/gi, '');
        row.textContent = String(status || 'INFO').toUpperCase() + ' : ' + String(name || '') + (message ? ' : ' + String(message) : '');
        log.appendChild(row);
        while (log.childNodes.length > MAX_LOG_LINES) {
            log.removeChild(log.firstChild);
        }
        log.scrollTop = log.scrollHeight;
    }

    function renderSummary() {
        summary.textContent = [
            counters.checked + ' checked',
            counters.accepted + ' accepted',
            counters.skipped + ' already held/pending',
            counters.rejected + ' rejected',
            counters.uploaded + ' transferred',
            counters.unverified + ' unverified',
            counters.duplicates + ' post-upload duplicates',
            counters.failed + ' failed'
        ].join(' · ');
    }

    function setProgress(percent, label, indeterminate) {
        if (indeterminate) {
            progressBar.removeAttribute('value');
        } else {
            progressBar.value = Math.max(0, Math.min(100, Number(percent || 0)));
        }
        progressLabel.textContent = String(label || '');
    }

    async function* walkDirectory(handle, prefix) {
        for await (const entry of handle.values()) {
            ensureRunning();
            const relativePath = prefix ? prefix + '/' + entry.name : entry.name;
            if (entry.kind === 'file') {
                yield {file: await entry.getFile(), relativePath: relativePath};
            } else if (entry.kind === 'directory') {
                yield* walkDirectory(entry, relativePath);
            }
        }
    }

    async function chooseDirectory() {
        if (operationActive || typeof window.showDirectoryPicker !== 'function') {
            if (folderInput) folderInput.click();
            return;
        }
        try {
            folderHandle = await window.showDirectoryPicker({mode: 'read'});
            if (folderSummary) folderSummary.textContent = 'Selected: ' + folderHandle.name + ' (scanned in batches when upload starts)';
        } catch (error) {
            if (error && error.name !== 'AbortError') {
                addLog('failed', 'Folder', error.message || 'Could not open folder.');
            }
        }
    }

    if (folderButton) folderButton.addEventListener('click', chooseDirectory);
    if (folderInput) folderInput.addEventListener('change', function () {
        folderHandle = null;
        if (folderSummary) folderSummary.textContent = folderInput.files && folderInput.files.length
            ? folderInput.files.length.toLocaleString() + ' folder file(s) selected'
            : 'No folder selected';
    });

    async function* selectedItems() {
        const seen = new Set();
        const lists = [fileInput.files, folderInput ? folderInput.files : null];
        for (const list of lists) {
            if (!list) continue;
            for (let index = 0; index < list.length; index++) {
                ensureRunning();
                const file = list[index];
                const relativePath = String(file.webkitRelativePath || file.name || '');
                const key = [file.name, file.size, file.lastModified || 0, relativePath].join('|');
                if (seen.has(key)) continue;
                seen.add(key);
                yield {file: file, relativePath: relativePath || file.name};
            }
        }

        if (folderHandle) {
            for await (const item of walkDirectory(folderHandle, folderHandle.name)) {
                const file = item.file;
                const key = [file.name, file.size, file.lastModified || 0, item.relativePath].join('|');
                if (seen.has(key)) continue;
                seen.add(key);
                yield item;
            }
        }
    }

    function beginInspector() {
        inspector = new Worker(workerUrl);
        inspector.onmessage = function (event) {
            const data = event.data || {};
            if (!inspectorPending || Number(data.id) !== inspectorPending.id) return;
            if (data.type === 'progress') {
                const total = Math.max(1, Number(data.total || 1));
                const loaded = Math.max(0, Number(data.loaded || 0));
                const hashing = data.phase === 'hash' || data.phase === 'redirect-hash';
                const percent = hashing ? Math.min(99, Math.floor((loaded * 100) / total)) : 0;
                const detail = data.phase === 'redirect-hash'
                    ? ' · decoding/hash identity ' + formatBytes(loaded) + '/' + formatBytes(total)
                        + (Number(data.output || 0) > 0 ? ' · decoded ' + formatBytes(data.output) : '')
                    : (data.phase === 'hash'
                        ? ' · hashing ' + formatBytes(loaded) + '/' + formatBytes(total)
                        : ' · validating header');
                setProgress(
                    percent,
                    'Batch ' + inspectorPending.batch + ' · checking ' + inspectorPending.position + '/' + inspectorPending.total
                        + ' · ' + inspectorPending.name + detail,
                    false
                );
                return;
            }
            const pending = inspectorPending;
            inspectorPending = null;
            if (data.type === 'result') pending.resolve(data.result || {});
            else pending.reject(new Error(String(data.message || 'Client file inspection failed.')));
        };
        inspector.onerror = function () {
            if (inspectorPending) {
                const pending = inspectorPending;
                inspectorPending = null;
                pending.reject(new Error('Client inspection worker failed.'));
            }
        };
    }

    function inspectFile(file, name, batchNumber, position, total) {
        ensureRunning();
        if (!inspector) beginInspector();
        return new Promise(function (resolve, reject) {
            const id = ++inspectorSequence;
            inspectorPending = {
                id: id,
                resolve: resolve,
                reject: reject,
                name: name,
                batch: batchNumber,
                position: position,
                total: total
            };
            inspector.postMessage({type: 'inspect', id: id, file: file});
        });
    }

    async function legacyGuid(file, inspection) {
        if (!inspection || inspection.redirect || String(inspection.header && inspection.header.kind || '') !== 'package' || file.size < 52) {
            return '';
        }
        try {
            const bytes = new Uint8Array(await file.slice(0, 52).arrayBuffer());
            const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
            const leMagic = view.getUint32(0, true) === 0x9e2a83c1;
            const beMagic = view.getUint32(0, false) === 0x9e2a83c1;
            if (!leMagic && !beMagic) return '';
            const littleEndian = leMagic;
            const packed = view.getUint32(4, littleEndian);
            const version = packed & 0xffff;
            // Mirror CatalogLegacyPackageReader exactly: versions below 68 have
            // HeritageCount/HeritageOffset before FGuid; version 68+ does not.
            // UE3+ uses a different summary and is deliberately left to server parsing.
            if (version < 1 || version >= 200) return '';
            const guidOffset = version < 68 ? 44 : 36;
            if (file.size < guidOffset + 16) return '';
            if (version < 68) {
                const nameOffset = view.getInt32(16, littleEndian);
                if (nameOffset < guidOffset + 16) return '';
            }
            const guidBytes = new Uint8Array(await file.slice(guidOffset, guidOffset + 16).arrayBuffer());
            if (guidBytes.byteLength !== 16) return '';
            const guidView = new DataView(guidBytes.buffer, guidBytes.byteOffset, guidBytes.byteLength);
            const parts = [];
            for (let offset = 0; offset < 16; offset += 4) {
                parts.push(guidView.getUint32(offset, littleEndian).toString(16).toUpperCase().padStart(8, '0'));
            }
            return parts.join('-');
        } catch (error) {
            return '';
        }
    }

    async function inspectBatch(items, batchNumber) {
        const checked = [];
        for (let index = 0; index < items.length; index++) {
            ensureRunning();
            const item = items[index];
            const name = item.relativePath || item.file.name;
            const extension = extensionOf(item.file.name);
            if (!allowedExtensions.has(extension)) {
                counters.rejected++;
                counters.checked++;
                addLog('rejected', name, 'Extension .' + (extension || '(none)') + ' is not accepted.');
                renderSummary();
                continue;
            }
            if (item.file.size < 1 || (maxFileBytes > 0 && item.file.size > maxFileBytes)) {
                counters.rejected++;
                counters.checked++;
                addLog('rejected', name, 'File size is outside the public upload limit.');
                renderSummary();
                continue;
            }

            try {
                const inspection = await inspectFile(item.file, name, batchNumber, index + 1, items.length);
                const guid = String(inspection.guid || '') || await legacyGuid(item.file, inspection);
                const identitySize = Math.max(
                    0,
                    Number(inspection.identity_size || (inspection.redirect ? 0 : item.file.size))
                );
                const clientId = batchNumber + '-' + index + '-' + (++processedFiles);
                checked.push({
                    clientId: clientId,
                    item: item,
                    manifest: {
                        client_id: clientId,
                        name: item.file.name,
                        relative_path: name,
                        size: item.file.size,
                        identity_size: identitySize,
                        md5: String(inspection.md5 || ''),
                        sha1: String(inspection.sha1 || ''),
                        guid: guid
                    }
                });
                counters.checked++;
                addLog('checked', name, 'Header/magic passed' + (guid ? ' · GUID ' + guid : '') + '.');
            } catch (error) {
                counters.checked++;
                counters.rejected++;
                addLog('rejected', name, error.message || 'Client validation failed.');
            }
            renderSummary();
        }
        return checked;
    }

    async function batchPreflight(checked, batchNumber) {
        ensureRunning();
        if (!checked.length) return [];
        setProgress(0, 'Batch ' + batchNumber + ' · checking ' + checked.length + ' file identities with the server…', true);
        activeController = new AbortController();
        try {
            const response = await fetch(preflightUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({files: checked.map(function (entry) { return entry.manifest; })}),
                signal: activeController.signal
            });
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                throw new Error(String(payload && payload.error && payload.error.message || payload.message || ('Preflight HTTP ' + response.status)));
            }
            const rows = payload && payload.data && Array.isArray(payload.data.files) ? payload.data.files : [];
            const byId = new Map(checked.map(function (entry) { return [entry.clientId, entry]; }));
            const accepted = [];
            rows.forEach(function (row) {
                const entry = byId.get(String(row.client_id || ''));
                if (!entry) return;
                const action = String(row.action || '');
                const name = entry.item.relativePath || entry.item.file.name;
                if (action === 'upload' && row.upload_token) {
                    counters.accepted++;
                    accepted.push({entry: entry, token: String(row.upload_token), response: row});
                    addLog('accepted', name, row.message || 'Upload allowed.');
                } else if (action === 'skip') {
                    counters.skipped++;
                    addLog('skipped', name, row.message || 'Already held or pending.');
                } else {
                    counters.rejected++;
                    addLog('rejected', name, row.message || 'Server rejected this file.');
                }
            });
            renderSummary();
            return accepted;
        } finally {
            activeController = null;
        }
    }

    function uploadChunk(token, blob, chunkIndex, label, offset, total) {
        ensureRunning();
        return new Promise(function (resolve, reject) {
            const data = new FormData();
            data.append('action', 'chunk');
            data.append('upload_token', token);
            data.append('chunk_index', String(chunkIndex));
            data.append('chunk', blob, 'chunk.bin');

            const xhr = new XMLHttpRequest();
            activeXhr = xhr;
            xhr.open('POST', uploadUrl, true);
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.responseType = 'json';
            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                const loaded = Math.min(total, offset + event.loaded);
                setProgress(
                    Math.floor((loaded * 100) / Math.max(1, total)),
                    'Uploading ' + label + ' · ' + formatBytes(loaded) + '/' + formatBytes(total),
                    false
                );
            };
            xhr.onload = function () {
                activeXhr = null;
                const body = xhr.response || {};
                if (xhr.status >= 200 && xhr.status < 300) resolve(body);
                else reject(new Error(String(body && body.error && body.error.message || body.message || ('Upload HTTP ' + xhr.status))));
            };
            xhr.onerror = function () {
                activeXhr = null;
                reject(new Error('Network error while uploading chunk.'));
            };
            xhr.onabort = function () {
                activeXhr = null;
                reject(stoppedError());
            };
            xhr.send(data);
        });
    }

    async function postAction(action, token, allowWhenStopped) {
        if (!allowWhenStopped) ensureRunning();
        const controller = new AbortController();
        if (!allowWhenStopped) activeController = controller;
        try {
            const data = new FormData();
            data.append('action', action);
            data.append('upload_token', token);
            const response = await fetch(uploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-CSRF-Token': csrf},
                body: data,
                signal: controller.signal
            });
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                throw new Error(String(payload && payload.error && payload.error.message || payload.message || (action + ' HTTP ' + response.status)));
            }
            return payload;
        } finally {
            if (activeController === controller) activeController = null;
        }
    }

    async function fetchValidationStatuses(entries) {
        const tokens = entries.map(function (entry) { return entry.token; });
        const data = new FormData();
        data.append('action', 'status_batch');
        data.append('upload_tokens', JSON.stringify(tokens));

        const controller = new AbortController();
        activeController = controller;
        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-CSRF-Token': csrf},
                body: data,
                signal: controller.signal
            });
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                throw new Error(String(payload && payload.error && payload.error.message
                    || payload.message || ('Status HTTP ' + response.status)));
            }
            return payload && payload.data && Array.isArray(payload.data.uploads)
                ? payload.data.uploads
                : [];
        } finally {
            if (activeController === controller) activeController = null;
        }
    }

    function sleep(milliseconds) {
        return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
    }

    async function waitForValidationResults(entries) {
        let pending = entries.slice();
        const deadline = Date.now() + 120000;
        let delay = 1000;

        while (pending.length > 0 && Date.now() < deadline) {
            ensureRunning();
            const nextPending = [];
            for (let offset = 0; offset < pending.length; offset += 100) {
                const batch = pending.slice(offset, offset + 100);
                const rows = await fetchValidationStatuses(batch);
                const byToken = new Map(rows.map(function (row) {
                    return [String(row.upload_token || ''), row];
                }));

                batch.forEach(function (entry) {
                    const row = byToken.get(entry.token);
                    if (!row) {
                        nextPending.push(entry);
                        return;
                    }
                    const status = String(row.status || '').toLowerCase();
                    const fileId = Number(row.unverified_file_id || 0);
                    const message = String(row.result_message || '').trim();

                    if (status === 'unverified') {
                        counters.unverified++;
                        addLog(
                            'unverified',
                            entry.label,
                            'Ready for administrator review as unverified file #' + String(fileId || '?')
                                + (message ? ' · ' + message : '')
                        );
                    } else if (status === 'duplicate') {
                        counters.duplicates++;
                        addLog(
                            'duplicate',
                            entry.label,
                            (fileId > 0 ? 'Server validation matched existing file #' + String(fileId) + '.' : 'Server validation found an existing file.')
                                + (message ? ' · ' + message : '')
                        );
                    } else if (status === 'failed') {
                        counters.failed++;
                        addLog(
                            'failed',
                            entry.label,
                            message || 'Background validation failed; the original contribution was retained for diagnosis.'
                        );
                    } else {
                        nextPending.push(entry);
                    }
                });
            }

            pending = nextPending;
            renderSummary();
            if (pending.length > 0) {
                setProgress(
                    100,
                    'Waiting for background validation · ' + pending.length + ' contribution(s) still processing…',
                    true
                );
                await sleep(delay);
                delay = Math.min(5000, delay + 1000);
            }
        }

        pending.forEach(function (entry) {
            addLog(
                'info',
                entry.label,
                'Transfer is complete and background validation is still pending. Check Background Jobs if it remains pending.'
            );
        });
        renderSummary();
    }

    async function wakePublicQueue(batchNumber) {
        const controller = new AbortController();
        activeController = controller;
        try {
            setProgress(100, 'Batch ' + batchNumber + ' · starting background validation…', true);
            const data = new FormData();
            data.append('action', 'wake');
            const response = await fetch(uploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-CSRF-Token': csrf},
                body: data,
                signal: controller.signal
            });
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                throw new Error(String(payload && payload.error && payload.error.message
                    || payload.message || ('Worker wake HTTP ' + response.status)));
            }
            return payload;
        } finally {
            if (activeController === controller) activeController = null;
        }
    }

    async function cancelReservation(token) {
        try {
            await postAction('cancel', token, true);
        } catch (ignore) {
            // Expiry/pruning remains the final safety net if cancellation cannot reach the server.
        }
    }

    async function uploadAccepted(item, ordinal, totalAccepted, batchNumber) {
        const file = item.entry.item.file;
        const label = item.entry.item.relativePath || file.name;
        const token = item.token;
        let offset = 0;
        let chunkIndex = 0;
        try {
            while (offset < file.size) {
                ensureRunning();
                const end = Math.min(file.size, offset + chunkBytes);
                await uploadChunk(token, file.slice(offset, end), chunkIndex, label, offset, file.size);
                offset = end;
                chunkIndex++;
            }
            setProgress(100, 'Batch ' + batchNumber + ' · finalising upload ' + ordinal + '/' + totalAccepted + ' · ' + label, true);
            await postAction('complete', token, false);
            counters.uploaded++;
            addLog('uploaded', label, 'Transfer complete and queued for background validation.');
            renderSummary();
            return true;
        } catch (error) {
            counters.failed++;
            addLog('failed', label, error.message || 'Upload failed.');
            await cancelReservation(token);
            renderSummary();
            if (error && error.name === 'AbortError') throw error;
            return false;
        }
    }

    async function processBatch(items, batchNumber) {
        if (!items.length) return;
        const checked = await inspectBatch(items, batchNumber);
        const accepted = await batchPreflight(checked, batchNumber);
        let index = 0;
        try {
            for (; index < accepted.length; index++) {
                ensureRunning();
                const transferred = await uploadAccepted(accepted[index], index + 1, accepted.length, batchNumber);
                if (transferred) {
                    pendingValidation.push({
                        token: accepted[index].token,
                        label: accepted[index].entry.item.relativePath || accepted[index].entry.item.file.name
                    });
                }
            }
            if (accepted.length > 0) {
                try {
                    const wake = await wakePublicQueue(batchNumber);
                    const workerError = String(wake && wake.data && wake.data.worker_error || '');
                    if (workerError) {
                        addLog(
                            'info',
                            'Background validation',
                            'Uploads are queued. Worker pool status: ' + workerError
                        );
                    }
                } catch (wakeError) {
                    addLog(
                        'info',
                        'Background validation',
                        'Uploads are queued. Worker wake status: '
                            + ((wakeError && wakeError.message) || 'unknown error')
                    );
                }
            }
        } catch (error) {
            // Preflight reserves the whole accepted batch. Release the active and
            // not-yet-started reservations immediately when Stop/fatal aborts it.
            for (let pending = index; pending < accepted.length; pending++) {
                await cancelReservation(accepted[pending].token);
            }
            // Any files completed before the interruption already own durable
            // background jobs. Wake that queue even though the rest of the batch
            // is being abandoned.
            if (index > 0) {
                try {
                    await wakePublicQueue(batchNumber);
                } catch (ignore) {
                }
            }
            throw error;
        }
    }

    function resetCounters() {
        Object.keys(counters).forEach(function (key) { counters[key] = 0; });
        processedFiles = 0;
        pendingValidation = [];
        log.textContent = '';
        renderSummary();
    }

    function stopOperation() {
        if (!operationActive) return;
        stopRequested = true;
        if (activeXhr) activeXhr.abort();
        if (activeController) activeController.abort();
        if (inspector) {
            inspector.terminate();
            inspector = null;
        }
        if (inspectorPending) {
            inspectorPending.reject(stoppedError());
            inspectorPending = null;
        }
        setProgress(0, 'Stopping…', true);
    }

    stopButton.addEventListener('click', stopOperation);

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (operationActive) return;

        const hasFiles = fileInput.files && fileInput.files.length > 0;
        const hasFallbackFolder = folderInput && folderInput.files && folderInput.files.length > 0;
        if (!hasFiles && !hasFallbackFolder && !folderHandle) {
            addLog('rejected', 'Selection', 'Choose files or a folder first.');
            return;
        }

        operationActive = true;
        stopRequested = false;
        startButton.disabled = true;
        stopButton.hidden = false;
        stopButton.disabled = false;
        progressBox.hidden = false;
        resetCounters();

        try {
            let batch = [];
            let batchNumber = 1;
            for await (const item of selectedItems()) {
                ensureRunning();
                batch.push(item);
                if (batch.length >= BATCH_FILES) {
                    await processBatch(batch, batchNumber++);
                    batch = [];
                }
            }
            if (batch.length) {
                await processBatch(batch, batchNumber);
            }
            if (pendingValidation.length > 0) {
                await waitForValidationResults(pendingValidation);
            }
            setProgress(
                100,
                'Contribution upload finished. Terminal validation results are shown below; any remaining pending items continue in Background Jobs.',
                false
            );
        } catch (error) {
            if (error && error.name === 'AbortError') {
                addLog('stopped', 'Upload', 'Stopped by user. Completed files remain queued; the active reservation was cancelled where possible.');
                setProgress(0, 'Stopped.', false);
            } else {
                counters.failed++;
                addLog('failed', 'Upload', error.message || 'Public upload failed.');
                setProgress(0, 'Upload stopped because of an error.', false);
            }
            renderSummary();
        } finally {
            operationActive = false;
            startButton.disabled = false;
            stopButton.disabled = true;
            stopButton.hidden = true;
            if (activeXhr) activeXhr.abort();
            activeXhr = null;
            activeController = null;
            if (inspector) inspector.terminate();
            inspector = null;
            inspectorPending = null;
        }
    });
}());
