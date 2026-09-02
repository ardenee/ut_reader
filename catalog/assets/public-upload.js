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
    const archiveWorkerUrl = String(progressBox.dataset.archiveWorkerUrl || 'assets/public-upload-archive-worker.js');
    const umodWorkerUrl = String(progressBox.dataset.umodWorkerUrl || 'assets/public-upload-umod-worker.js');
    const archiveEnabled = String(progressBox.dataset.archiveEnabled || '') === '1';
    const umodEnabled = String(progressBox.dataset.umodEnabled || '') === '1';
    const csrf = String(progressBox.dataset.csrf || '');
    const chunkBytes = Math.max(1024 * 1024, Number(progressBox.dataset.chunkBytes || 16 * 1024 * 1024));
    const maxFileBytes = Math.max(1, Number(progressBox.dataset.maxFileBytes || 0));
    const BATCH_FILES = 100;
    const MAX_LOG_LINES = 500;
    const MAX_ARCHIVE_ENTRIES = 50000;
    const SEVENZIP_ARCHIVE_EXTENSIONS = new Set(['zip', 'rar', '7z']);
    const UMOD_ARCHIVE_EXTENSIONS = new Set(['umod', 'ut2mod', 'ut4mod']);
    const ARCHIVE_EXTENSIONS = new Set(['zip', 'rar', '7z', 'umod', 'ut2mod', 'ut4mod']);
    const TRANSPORT_COMPRESSION_MIN_BYTES = 64 * 1024;
    const TRANSPORT_COMPRESSION_RATIO = 0.90;

    let allowed = [];
    try {
        allowed = JSON.parse(form.dataset.allowedExtensions || '[]');
    } catch (error) {
        allowed = [];
    }
    const allowedExtensions = new Set(allowed.map(function (value) {
        return String(value || '').trim().toLowerCase().replace(/^\.+/, '');
    }).filter(Boolean));
    ['uz', 'uz2', 'uz3'].forEach(function (extension) { allowedExtensions.add(extension); });

    let operationActive = false;
    let stopRequested = false;
    let folderHandle = null;
    let activeXhr = null;
    let activeController = null;
    let inspector = null;
    let inspectorSequence = 0;
    let inspectorPending = null;
    let archiveSequence = 0;
    const activeArchiveStops = new Set();
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
            inspector.postMessage({type: 'inspect', id: id, file: file, max_file_bytes: maxFileBytes});
        });
    }


    function archiveDisplayPath(sourcePath, memberPath) {
        const source = String(sourcePath || '').replace(/\\/g, '/').replace(/^\/+/, '');
        const member = String(memberPath || '').replace(/\\/g, '/').replace(/^\/+/, '');
        return source + '!/' + member;
    }

    function archiveWorkerFor(file) {
        const extension = extensionOf(file && file.name);
        if (UMOD_ARCHIVE_EXTENSIONS.has(extension)) {
            if (!umodEnabled) throw new Error('UMOD/UT2MOD/UT4MOD browser processing is not installed on this server.');
            return umodWorkerUrl;
        }
        if (SEVENZIP_ARCHIVE_EXTENSIONS.has(extension)) {
            if (!archiveEnabled) throw new Error('ZIP/RAR/7z browser processing is not installed on this server.');
            return archiveWorkerUrl;
        }
        throw new Error('Unsupported source archive extension .' + (extension || '(none)') + '.');
    }

    function oneShotArchiveList(file, label) {
        ensureRunning();
        let selectedWorkerUrl;
        try {
            selectedWorkerUrl = archiveWorkerFor(file);
        } catch (error) {
            return Promise.reject(error);
        }
        return new Promise(function (resolve, reject) {
            const worker = new Worker(selectedWorkerUrl);
            const id = String(++archiveSequence);
            let settled = false;
            function finish(error, result) {
                if (settled) return;
                settled = true;
                activeArchiveStops.delete(stop);
                worker.terminate();
                if (error) reject(error);
                else resolve(result);
            }
            function stop(reason) {
                finish(reason instanceof Error ? reason : stoppedError());
            }
            activeArchiveStops.add(stop);
            worker.onmessage = function (event) {
                const data = event.data || {};
                if (String(data.id || '') !== id) return;
                if (data.type === 'progress') {
                    setProgress(0, label + ' · ' + String(data.message || 'Reading archive…'), true);
                    return;
                }
                if (data.type === 'result') {
                    finish(null, data.result && Array.isArray(data.result.entries) ? data.result.entries : []);
                } else {
                    finish(new Error(String(data.message || 'Could not read archive directory.')));
                }
            };
            worker.onerror = function () {
                finish(new Error('Browser archive worker failed while reading ' + label + '.'));
            };
            worker.postMessage({type:'list', id:id, file:file, max_entries:MAX_ARCHIVE_ENTRIES});
        });
    }

    function openUmodMember(file, member, label, position, total) {
        ensureRunning();
        return new Promise(function (resolve, reject) {
            const offset = Math.max(0, Number(member.offset || 0));
            const size = Math.max(0, Number(member.size || 0));
            const tableOffset = Math.max(0, Number(member.table_offset || 0));
            if (size < 1 || size > maxFileBytes
                || offset + size > tableOffset
                || offset + size > Number(file.size || 0)) {
                reject(new Error('UMOD member bounds are invalid or exceed the public upload file limit.'));
                return;
            }

            const memberBlob = file.slice(offset, offset + size);
            if (memberBlob.size !== size) {
                reject(new Error('UMOD member slice does not match its declared size.'));
                return;
            }

            let memberFile;
            try {
                memberFile = new File(
                    [memberBlob],
                    String(member.name || 'umod-member'),
                    {type:'application/octet-stream', lastModified:Number(file.lastModified || Date.now())}
                );
            } catch (error) {
                reject(new Error('Browser could not expose the UMOD member for inspection.'));
                return;
            }

            const worker = new Worker(workerUrl);
            const id = String(++archiveSequence);
            let closed = false;
            let resolved = false;

            function close(reason) {
                if (closed) return;
                closed = true;
                activeArchiveStops.delete(close);
                worker.terminate();
                if (!resolved) reject(reason instanceof Error ? reason : stoppedError());
            }
            activeArchiveStops.add(close);

            worker.onmessage = function (event) {
                const data = event.data || {};
                if (String(data.id || '') !== id) return;
                if (data.type === 'progress') {
                    const loaded = Math.max(0, Number(data.loaded || 0));
                    const progressTotal = Math.max(0, Number(data.total || 0));
                    const percent = progressTotal > 0
                        ? Math.min(99, Math.floor((loaded * 100) / progressTotal))
                        : 0;
                    setProgress(
                        percent,
                        'Archive member ' + position + '/' + total + ' · ' + label
                            + ' · ' + (data.phase === 'redirect-hash' ? 'decoding/hash identity' : 'validating/hash identity'),
                        progressTotal <= 0
                    );
                    return;
                }
                if (data.type !== 'result') {
                    close(new Error(String(data.message || 'UMOD member inspection failed.')));
                    return;
                }

                const inspection = data.result || {};
                worker.terminate();
                resolved = true;
                resolve({
                    meta:{
                        name:String(member.name || ''),
                        member_path:String(member.path || ''),
                        size:size,
                        inspection:inspection
                    },
                    read:async function (readOffset, length) {
                        ensureRunning();
                        if (closed) throw stoppedError();
                        const start = Math.max(0, Number(readOffset || 0));
                        const bytes = Math.max(0, Number(length || 0));
                        if (bytes < 1 || start + bytes > size) {
                            throw new Error('UMOD member chunk order is outside the selected member.');
                        }
                        const buffer = await memberBlob.slice(start, start + bytes).arrayBuffer();
                        if (closed) throw stoppedError();
                        if (!(buffer instanceof ArrayBuffer) || buffer.byteLength !== bytes) {
                            throw new Error('UMOD member read returned an incomplete upload chunk.');
                        }
                        return buffer;
                    },
                    close:close
                });
            };
            worker.onerror = function () {
                close(new Error('Browser package inspector failed while checking ' + label + '.'));
            };
            worker.postMessage({type:'inspect', id:id, file:memberFile, max_file_bytes:maxFileBytes});
        });
    }

    function openArchiveMember(file, member, label, position, total) {
        ensureRunning();
        if (UMOD_ARCHIVE_EXTENSIONS.has(extensionOf(file && file.name))) {
            return openUmodMember(file, member, label, position, total);
        }
        return new Promise(function (resolve, reject) {
            const worker = new Worker(archiveWorkerUrl);
            let sequence = 0;
            let closed = false;
            const pending = new Map();

            function close(reason) {
                if (closed) return;
                closed = true;
                activeArchiveStops.delete(close);
                worker.terminate();
                const error = reason instanceof Error ? reason : new Error('Archive member worker was closed.');
                pending.forEach(function (entry) { entry.reject(error); });
                pending.clear();
            }
            activeArchiveStops.add(close);

            function request(type, payload) {
                ensureRunning();
                if (closed) return Promise.reject(new Error('Archive member worker is closed.'));
                return new Promise(function (requestResolve, requestReject) {
                    const id = String(++sequence);
                    pending.set(id, {resolve:requestResolve, reject:requestReject});
                    worker.postMessage(Object.assign({type:type, id:id}, payload || {}));
                });
            }

            worker.onmessage = function (event) {
                const data = event.data || {};
                const id = String(data.id || '');
                const entry = pending.get(id);
                if (!entry) return;
                if (data.type === 'progress') {
                    const loaded = Math.max(0, Number(data.loaded || 0));
                    const progressTotal = Math.max(0, Number(data.total || 0));
                    const percent = progressTotal > 0
                        ? Math.min(99, Math.floor((loaded * 100) / progressTotal))
                        : 0;
                    setProgress(
                        percent,
                        'Archive member ' + position + '/' + total + ' · ' + label
                            + ' · ' + String(data.message || 'processing'),
                        progressTotal <= 0
                    );
                    return;
                }
                pending.delete(id);
                if (data.type === 'result') entry.resolve(data.result || {});
                else entry.reject(new Error(String(data.message || 'Archive member processing failed.')));
            };
            worker.onerror = function () {
                close(new Error('Browser archive worker failed while extracting ' + label + '.'));
            };

            request('extract', {
                file:file,
                member_path:member.path,
                expected_size:member.size,
                max_file_bytes:maxFileBytes
            }).then(function (result) {
                resolve({
                    meta:result,
                    read:async function (offset, length) {
                        const response = await request('read', {offset:offset, length:length});
                        const buffer = response.buffer;
                        if (!(buffer instanceof ArrayBuffer) || Number(response.length || 0) !== length) {
                            throw new Error('Archive member worker returned an incomplete upload chunk.');
                        }
                        return buffer;
                    },
                    close:close
                });
            }).catch(function (error) {
                close(error);
                reject(error);
            });
        });
    }

    async function encodeTransportChunk(rawBlob) {
        if (!(rawBlob instanceof Blob)
            || rawBlob.size < TRANSPORT_COMPRESSION_MIN_BYTES
            || typeof CompressionStream !== 'function') {
            return {blob:rawBlob, encoding:'identity'};
        }
        try {
            const compressed = await new Response(
                rawBlob.stream().pipeThrough(new CompressionStream('gzip'))
            ).blob();
            if (compressed.size > 0
                && compressed.size <= Math.floor(rawBlob.size * TRANSPORT_COMPRESSION_RATIO)) {
                return {blob:compressed, encoding:'gzip'};
            }
        } catch (ignore) {
        }
        return {blob:rawBlob, encoding:'identity'};
    }

    async function legacyGuid(file, inspection) {
        if (!inspection || inspection.redirect || String(inspection.header && inspection.header.kind || '') !== 'package' || file.size < 52) {
            return '';
        }
        try {
            const bytes = new Uint8Array(await file.slice(0, 52).arrayBuffer());
            const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
            const leTag = view.getUint32(0, true);
            const leMagic = leTag === 0x9e2a83c1 || leTag === 0x9e2a83c2;
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

    function uploadChunk(token, blob, chunkIndex, label, offset, total, contentEncoding, logicalChunkBytes) {
        ensureRunning();
        return new Promise(function (resolve, reject) {
            const data = new FormData();
            data.append('action', 'chunk');
            data.append('upload_token', token);
            data.append('chunk_index', String(chunkIndex));
            data.append('content_encoding', contentEncoding || 'identity');
            data.append('chunk', blob, 'chunk.bin');

            const xhr = new XMLHttpRequest();
            activeXhr = xhr;
            xhr.open('POST', uploadUrl, true);
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.responseType = 'json';
            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                const transportTotal = Math.max(1, Number(blob.size || 1));
                const logicalBytes = Math.max(1, Number(logicalChunkBytes || blob.size || 1));
                const logicalLoaded = Math.min(
                    logicalBytes,
                    Math.floor((Math.max(0, Number(event.loaded || 0)) * logicalBytes) / transportTotal)
                );
                const loaded = Math.min(total, offset + logicalLoaded);
                setProgress(
                    Math.floor((loaded * 100) / Math.max(1, total)),
                    'Uploading ' + label + ' · ' + formatBytes(loaded) + '/' + formatBytes(total)
                        + (contentEncoding === 'gzip'
                            ? ' · gzip ' + formatBytes(event.loaded || 0) + '/' + formatBytes(blob.size)
                            : ''),
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
        const archiveSession = item.entry.item.archiveSession || null;
        const label = item.entry.item.relativePath || file.name;
        const token = item.token;
        const totalBytes = Math.max(0, Number(file.size || 0));
        let offset = 0;
        let chunkIndex = 0;
        let networkBytes = 0;
        let gzipChunks = 0;
        try {
            while (offset < totalBytes) {
                ensureRunning();
                const end = Math.min(totalBytes, offset + chunkBytes);
                const logicalChunkBytes = end - offset;
                let rawBlob = null;
                let encoded = null;
                try {
                    if (archiveSession) {
                        const buffer = await archiveSession.read(offset, logicalChunkBytes);
                        rawBlob = new Blob([buffer], {type:'application/octet-stream'});
                    } else {
                        rawBlob = file.slice(offset, end);
                    }
                    encoded = await encodeTransportChunk(rawBlob);
                    await uploadChunk(
                        token, encoded.blob, chunkIndex, label, offset, totalBytes,
                        encoded.encoding, logicalChunkBytes
                    );
                    networkBytes += encoded.blob.size;
                    if (encoded.encoding === 'gzip') gzipChunks++;
                } finally {
                    rawBlob = null;
                    encoded = null;
                }
                offset = end;
                chunkIndex++;
            }
            setProgress(100, 'Batch ' + batchNumber + ' · finalising upload ' + ordinal + '/'
                + totalAccepted + ' · ' + label, true);
            await postAction('complete', token, false);
            counters.uploaded++;
            addLog(
                'uploaded',
                label,
                'Transfer complete and queued for background validation.'
                    + (gzipChunks > 0
                        ? ' Sent ' + formatBytes(networkBytes) + ' for ' + formatBytes(totalBytes)
                            + ' original bytes using ' + gzipChunks + ' gzip chunk(s).'
                        : '')
            );
            renderSummary();
            return true;
        } catch (error) {
            counters.failed++;
            addLog('failed', label, error.message || 'Upload failed.');
            await cancelReservation(token);
            renderSummary();
            if (error && error.name === 'AbortError') throw error;
            return false;
        } finally {
            if (archiveSession) archiveSession.close();
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


    async function processArchive(item, batchNumber) {
        const file = item.file;
        const archiveLabel = item.relativePath || file.name;
        try {
            archiveWorkerFor(file);
        } catch (error) {
            counters.checked++;
            counters.rejected++;
            addLog('rejected', archiveLabel, error.message || 'Browser source-archive processing is unavailable.');
            renderSummary();
            return;
        }
        if (!file || Number(file.size || 0) < 1) {
            counters.checked++;
            counters.rejected++;
            addLog('rejected', archiveLabel, 'Archive is empty.');
            renderSummary();
            return;
        }

        setProgress(0, 'Opening archive ' + archiveLabel + '…', true);
        let entries;
        try {
            entries = await oneShotArchiveList(file, archiveLabel);
        } catch (error) {
            if (error && error.name === 'AbortError') throw error;
            counters.checked++;
            counters.rejected++;
            addLog('rejected', archiveLabel, error.message || 'Could not read archive.');
            renderSummary();
            return;
        }

        const candidates = [];
        let ignored = 0;
        entries.forEach(function (entry) {
            const extension = extensionOf(entry.name || entry.path);
            if (entry.safe === false
                || !allowedExtensions.has(extension)
                || ARCHIVE_EXTENSIONS.has(extension)
                || Number(entry.size || 0) < 1) {
                ignored++;
                return;
            }
            candidates.push(entry);
        });
        addLog(
            'info',
            archiveLabel,
            entries.length + ' file entr' + (entries.length === 1 ? 'y' : 'ies')
                + ' · ' + candidates.length + ' Unreal candidate(s)'
                + (ignored ? ' · ' + ignored + ' non-upload member(s) ignored without extraction' : '')
                + '. Original archive will not be uploaded.'
        );

        let transferred = 0;
        for (let index = 0; index < candidates.length; index++) {
            ensureRunning();
            const member = candidates[index];
            const display = archiveDisplayPath(archiveLabel, member.path);
            if (member.encrypted) {
                counters.checked++;
                counters.rejected++;
                addLog('rejected', display, 'Encrypted archive members are not accepted.');
                renderSummary();
                continue;
            }
            if (member.linked) {
                counters.checked++;
                counters.rejected++;
                addLog('rejected', display, 'Linked archive members are not accepted.');
                renderSummary();
                continue;
            }
            if (Number(member.size || 0) > maxFileBytes) {
                counters.checked++;
                counters.rejected++;
                addLog('rejected', display,
                    'Extracted member size ' + formatBytes(member.size) + ' exceeds the public upload file limit.');
                renderSummary();
                continue;
            }

            let session = null;
            let inspected = false;
            try {
                session = await openArchiveMember(file, member, display, index + 1, candidates.length);
                const meta = session.meta || {};
                const inspection = meta.inspection || {};
                const size = Math.max(0, Number(meta.size || 0));
                if (size !== Number(member.size || 0)) {
                    throw new Error('Archive member size changed during processing.');
                }
                const clientId = batchNumber + '-archive-' + (++processedFiles);
                const checked = [{
                    clientId:clientId,
                    item:{
                        file:{name:String(meta.name || member.name || ''), size:size},
                        relativePath:display,
                        archiveSession:session
                    },
                    manifest:{
                        client_id:clientId,
                        name:String(meta.name || member.name || ''),
                        relative_path:display,
                        size:size,
                        identity_size:Math.max(0, Number(inspection.identity_size || size)),
                        md5:String(inspection.md5 || ''),
                        sha1:String(inspection.sha1 || ''),
                        guid:String(inspection.guid || '')
                    }
                }];

                counters.checked++;
                inspected = true;
                addLog(
                    'checked',
                    display,
                    'Extracted one member · header/magic passed'
                        + (inspection.guid ? ' · GUID ' + inspection.guid : '')
                        + ' · original archive retained only in the browser.'
                );
                renderSummary();

                const accepted = await batchPreflight(checked, batchNumber);
                if (!accepted.length) {
                    session.close();
                    session = null;
                    continue;
                }
                const ok = await uploadAccepted(accepted[0], 1, 1, batchNumber);
                session = null;
                if (ok) {
                    transferred++;
                    pendingValidation.push({token:accepted[0].token, label:display});
                }
            } catch (error) {
                if (error && error.name === 'AbortError') throw error;
                if (!inspected) {
                    counters.checked++;
                    counters.rejected++;
                    addLog('rejected', display, error.message || 'Archive member could not be processed.');
                    renderSummary();
                    continue;
                }
                throw error;
            } finally {
                if (session) session.close();
                session = null;
            }
        }

        if (transferred > 0) {
            try {
                const wake = await wakePublicQueue(batchNumber);
                const workerError = String(wake && wake.data && wake.data.worker_error || '');
                if (workerError) {
                    addLog('info', 'Background validation', 'Uploads are queued. Worker pool status: ' + workerError);
                }
            } catch (wakeError) {
                addLog('info', 'Background validation', 'Uploads are queued. Worker wake status: '
                    + ((wakeError && wakeError.message) || 'unknown error'));
            }
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
        Array.from(activeArchiveStops).forEach(function (stop) {
            try { stop(stoppedError()); } catch (ignore) {}
        });
        activeArchiveStops.clear();
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
                const extension = extensionOf(item.file && item.file.name);
                if (ARCHIVE_EXTENSIONS.has(extension)) {
                    if (batch.length) {
                        await processBatch(batch, batchNumber++);
                        batch = [];
                    }
                    await processArchive(item, batchNumber++);
                    continue;
                }
                batch.push(item);
                if (batch.length >= BATCH_FILES) {
                    await processBatch(batch, batchNumber++);
                    batch = [];
                }
            }
            if (batch.length) {
                await processBatch(batch, batchNumber++);
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
