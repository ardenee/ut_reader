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
    const actionCsrf = progress.dataset.actionCsrf || '';
    let activeJobId = 0;

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
            '.upload-result-queued,.upload-result-running { border-left-color:#f6c453; background:rgba(246,196,83,.08); }',
            '.upload-result-queued .upload-result-badge,.upload-result-running .upload-result-badge { color:#ffe29a; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function files() {
        return Array.from(fileInput.files || []).concat(folderInput ? Array.from(folderInput.files || []) : []);
    }

    function shownName(file) {
        return file.webkitRelativePath || file.name;
    }

    function bytes(value) {
        const units = ['B', 'KB', 'MB', 'GB'];
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

    async function readJob(jobId) {
        const response = await fetch(statusUrl + '?job_id=' + encodeURIComponent(jobId), {cache: 'no-store', credentials: 'same-origin'});
        const body = await response.json();
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        if (!response.ok || !jobs.length) throw new Error('Job status is unavailable.');
        return jobs[0];
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
            // The server also auto-starts the worker. Polling retries this call if
            // the job remains queued, so a transient launcher error is recoverable.
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

    async function waitForJob(jobId, fileName, index, total) {
        activeJobId = jobId;
        cancelButton.hidden = false;
        let queuedPolls = 0;
        while (true) {
            const current = await readJob(jobId);
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
                activeJobId = 0;
                cancelButton.hidden = true;
                if (current.status === 'completed') {
                    const entries = resultEntries(current.result, fileName);
                    if (entries.length) entries.forEach(addLog);
                    else addLog({status: 'completed', file: fileName, message: 'Background import complete.'});
                } else {
                    addLog({status: current.status, file: fileName, message: current.last_error || state.message || 'Background import did not complete.'});
                }
                return;
            }
            await new Promise(function (resolve) { window.setTimeout(resolve, 750); });
        }
    }

    function uploadOne(file, index, total) {
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
                speed.textContent = '';
                try {
                    const response = JSON.parse(xhr.responseText || '{}');
                    if (!response.ok || !Array.isArray(response.jobs) || !response.jobs.length) {
                        throw new Error(response.error || 'Upload could not be queued.');
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
                speed.textContent = '';
                addLog({status: 'failed', file: name, message: 'Upload connection error.'});
                setOverall(index, total, 0);
                resolve();
            };
            xhr.send(data);
        });
    }

    cancelButton.addEventListener('click', async function () {
        if (!activeJobId || !actionCsrf) return;
        cancelButton.disabled = true;
        try {
            await fetch(actionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': actionCsrf},
                body: JSON.stringify({action: 'cancel', job_id: activeJobId, reason: 'Cancelled from Profiled Upload.'}),
            });
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
        setOverall(0, selected.length, 0);
        for (let index = 0; index < selected.length; index++) {
            await uploadOne(selected[index], index + 1, selected.length);
        }
        currentBar.value = 100;
        overallBar.value = 100;
        overallLabel.textContent = 'Overall batch complete (100%)';
        overallCount.textContent = selected.length + ' of ' + selected.length + ' processed';
        currentLabel.textContent = 'Upload and import batch complete.';
        submitButton.disabled = false;
        cancelButton.hidden = true;
    });

    installStatusStyles();
})();
