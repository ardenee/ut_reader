(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const progress = document.getElementById('bucket-progress');
    const label = document.getElementById('bucket-progress-label');
    const currentBar = document.getElementById('bucket-progress-bar');
    const currentSpeed = document.getElementById('bucket-progress-speed');
    const overallBar = document.getElementById('bucket-overall-progress-bar');
    const overallLabel = document.getElementById('bucket-overall-progress-label');
    const overallCount = document.getElementById('bucket-overall-progress-count');
    const log = document.getElementById('bucket-log');
    if (!progress || !label || !currentBar || !currentSpeed || !overallBar || !overallLabel || !overallCount) return;

    const queueUrl = progress.dataset.processingUrl || 'background-jobs.php?queue=catalog%3Abucket-processing';
    const batchUrl = progress.dataset.batchUrl || 'api/v1/upload-bucket-batch.php';
    const FINALIZE_GROUP_SIZE = 50;
    let handled = false;
    let handoffTimer = 0;
    let phaseTimer = 0;
    let phaseStarted = 0;
    let activePhase = '';
    let phaseDetail = '';
    let operationActive = false;

    function sleep(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function elapsedText() {
        if (!phaseStarted) return '0s';
        const seconds = Math.max(0, Math.floor((Date.now() - phaseStarted) / 1000));
        if (seconds < 60) return seconds + 's';
        return Math.floor(seconds / 60) + 'm ' + String(seconds % 60).padStart(2, '0') + 's';
    }

    function appendStatus(status, file, message) {
        if (!log) return;
        const row = document.createElement('div');
        row.className = 'bucket-result bucket-result-' + String(status || 'info').replace(/[^a-z0-9_-]+/gi, '-').toLowerCase();
        const badge = document.createElement('span');
        badge.className = 'bucket-result-badge';
        badge.textContent = String(status || 'info').replace(/_/g, ' ');
        const name = document.createElement('span');
        name.className = 'bucket-result-file';
        name.textContent = String(file || 'Upload batch');
        const detail = document.createElement('span');
        detail.className = 'bucket-result-message';
        detail.textContent = String(message || '');
        row.appendChild(badge);
        row.appendChild(name);
        row.appendChild(detail);
        log.appendChild(row);
        while (log.children.length > 1200) log.removeChild(log.firstElementChild);
        log.scrollTop = log.scrollHeight;
    }

    function stopTimer() {
        if (phaseTimer) window.clearInterval(phaseTimer);
        phaseTimer = 0;
    }

    function renderTimer() {
        const elapsed = elapsedText();
        if (activePhase === 'prepare') {
            overallLabel.textContent = 'Phase 1 of 3 — Pause Upload Bucket processing';
            overallCount.textContent = phaseDetail + ' · ' + elapsed + ' elapsed · no selected-file bytes transferred yet';
            currentSpeed.textContent = elapsed + ' elapsed';
            overallBar.removeAttribute('value');
            currentBar.removeAttribute('value');
        } else if (activePhase === 'finalize') {
            currentSpeed.textContent = elapsed + ' elapsed';
        }
    }

    function beginTimedPhase(name, detail) {
        if (activePhase !== name) {
            stopTimer();
            activePhase = name;
            phaseStarted = Date.now();
            phaseTimer = window.setInterval(renderTimer, 1000);
        }
        phaseDetail = detail;
        renderTimer();
    }

    function beginTransferPhase() {
        if (activePhase === 'transfer') return;
        stopTimer();
        activePhase = 'transfer';
        phaseStarted = 0;
        currentSpeed.textContent = '';
        if (!currentBar.hasAttribute('value')) currentBar.value = 0;
        if (!overallBar.hasAttribute('value')) overallBar.value = 0;
        overallLabel.textContent = 'Phase 2 of 3 — Check identities and upload files';
    }

    function finishPhases(success) {
        stopTimer();
        activePhase = 'complete';
        phaseStarted = 0;
        currentSpeed.textContent = '';
        operationActive = false;
        if (!currentBar.hasAttribute('value')) currentBar.value = success ? 100 : 0;
        if (!overallBar.hasAttribute('value')) overallBar.value = success ? 100 : 0;
        if (success) overallLabel.textContent = 'Phase 3 of 3 complete — processing jobs created';
    }

    function responseError(body, fallback) {
        if (body && typeof body.error === 'string' && body.error) return body.error;
        if (body && body.error && typeof body.error.message === 'string') return body.error.message;
        if (body && typeof body.message === 'string' && body.message) return body.message;
        return fallback;
    }

    function installGroupedFinalization() {
        if (window.__unrealDbUploadBucketGroupedFinalization || !window.fetch || !window.Response) return;
        window.__unrealDbUploadBucketGroupedFinalization = true;
        const nativeFetch = window.fetch.bind(window);

        function isBatchRequest(input, init) {
            if (!init || String(init.method || 'GET').toUpperCase() !== 'POST') return false;
            const url = typeof input === 'string' ? input : String((input && input.url) || '');
            return url === batchUrl || url.endsWith('/' + batchUrl);
        }

        function combinedResponse(data) {
            return new Response(JSON.stringify({ok: true, data: data}), {
                status: 200,
                headers: {'Content-Type': 'application/json; charset=utf-8'}
            });
        }

        async function requestGroup(input, init, payload, number, total) {
            let lastError = null;
            for (let attempt = 1; attempt <= 3; attempt++) {
                const groupInit = Object.assign({}, init, {body: JSON.stringify(payload)});
                // The parent uploader has one five-minute timer for the full batch.
                // Grouped finalisation uses a fresh timeout for each short request.
                delete groupInit.signal;
                const controller = window.AbortController ? new AbortController() : null;
                const timeout = controller ? window.setTimeout(function () { controller.abort(); }, 120000) : 0;
                if (controller) groupInit.signal = controller.signal;
                try {
                    const response = await nativeFetch(input, groupInit);
                    let body;
                    try {
                        body = await response.clone().json();
                    } catch (error) {
                        throw new Error('Finalisation group returned invalid JSON (HTTP ' + response.status + ').');
                    }
                    if (!response.ok || !body || !body.ok) {
                        throw new Error(responseError(body, 'Finalisation group failed with HTTP ' + response.status + '.'));
                    }
                    return body.data || {};
                } catch (error) {
                    lastError = error && error.name === 'AbortError'
                        ? new Error('Finalisation group timed out after 120 seconds.')
                        : error;
                    if (attempt >= 3) break;
                    appendStatus('retrying', 'Finalisation group ' + number + '/' + total,
                        'Retrying finalisation group after attempt ' + attempt + ' failed: ' + (lastError.message || 'Unknown error'));
                    label.textContent = 'Retrying finalisation group ' + number + ' of ' + total
                        + ' after a temporary failure (attempt ' + (attempt + 1) + ' of 3)...';
                    await sleep(attempt * 1000);
                } finally {
                    if (timeout) window.clearTimeout(timeout);
                }
            }
            throw lastError || new Error('Finalisation group failed.');
        }

        async function finalizeGroups(input, init, payload) {
            const idsAll = Array.isArray(payload.upload_ids) ? payload.upload_ids : [];
            if (idsAll.length <= FINALIZE_GROUP_SIZE) return nativeFetch(input, init);

            const groupCount = Math.ceil(idsAll.length / FINALIZE_GROUP_SIZE);
            const totals = {
                queue: '', requested: 0, queued: 0, duplicates: 0, failed: 0,
                legacy_migrated: 0, pending_jobs: 0, job_ids: [], worker: null,
                worker_error: '', orphan_recovery: {}, messages: []
            };
            const jobIds = new Set();
            operationActive = true;
            stopTimer();
            activePhase = 'finalize';
            phaseStarted = Date.now();
            phaseTimer = window.setInterval(renderTimer, 1000);
            overallLabel.textContent = 'Phase 3 of 3 — Finalising uploaded files';
            overallBar.value = 0;
            currentBar.value = 0;

            for (let groupIndex = 0; groupIndex < groupCount; groupIndex++) {
                const start = groupIndex * FINALIZE_GROUP_SIZE;
                const ids = idsAll.slice(start, start + FINALIZE_GROUP_SIZE);
                const last = start + ids.length;
                const isFinalGroup = groupIndex === groupCount - 1;
                const before = Math.floor((start * 100) / Math.max(1, idsAll.length));
                currentBar.value = before;
                overallBar.value = before;
                label.textContent = 'Finalising batch group ' + (groupIndex + 1) + ' of ' + groupCount
                    + ' — uploaded files ' + (start + 1) + '–' + last + ' of ' + idsAll.length + '...';
                overallCount.textContent = start + ' of ' + idsAll.length + ' finalised · '
                    + totals.queued + ' queued so far · ' + totals.duplicates + ' duplicate(s) · '
                    + totals.failed + ' failed · ' + elapsedText() + ' elapsed';

                const groupPayload = Object.assign({}, payload, {
                    upload_ids: ids,
                    prepare_queue: groupIndex === 0,
                    start_worker: isFinalGroup
                });
                const data = await requestGroup(input, init, groupPayload, groupIndex + 1, groupCount);
                totals.queue = String(data.queue || totals.queue);
                totals.requested += Number(data.requested || 0);
                totals.queued += Number(data.queued || 0);
                totals.duplicates += Number(data.duplicates || 0);
                totals.failed += Number(data.failed || 0);
                totals.legacy_migrated += Number(data.legacy_migrated || 0);
                totals.pending_jobs = Number(data.pending_jobs || 0);
                totals.worker = data.worker || totals.worker;
                totals.worker_error = String(data.worker_error || totals.worker_error || '');
                if (data.orphan_recovery && typeof data.orphan_recovery === 'object') {
                    Object.assign(totals.orphan_recovery, data.orphan_recovery);
                }
                if (Array.isArray(data.job_ids)) {
                    data.job_ids.forEach(function (jobId) {
                        const id = Number(jobId || 0);
                        if (id > 0) jobIds.add(id);
                    });
                }
                if (Array.isArray(data.messages)) {
                    data.messages.forEach(function (entry) {
                        const status = String(entry.status || '').toLowerCase();
                        if (status === 'failed' || status === 'duplicate') {
                            appendStatus(status, entry.file || 'Finalisation', entry.message || '');
                        }
                    });
                }
                appendStatus('ready', 'Finalisation group ' + (groupIndex + 1) + '/' + groupCount,
                    ids.length + ' file(s) checked; cumulative: ' + totals.queued + ' queued, '
                    + totals.duplicates + ' duplicate(s), ' + totals.failed + ' failed.');

                const percent = Math.floor((last * 100) / Math.max(1, idsAll.length));
                currentBar.value = percent;
                overallBar.value = percent;
                overallCount.textContent = last + ' of ' + idsAll.length + ' finalised · '
                    + totals.queued + ' queued so far · ' + totals.duplicates + ' duplicate(s) · '
                    + totals.failed + ' failed · ' + elapsedText() + ' elapsed';
                await sleep(25);
            }

            totals.job_ids = Array.from(jobIds);
            totals.messages = [];
            return combinedResponse(totals);
        }

        window.fetch = function (input, init) {
            if (!isBatchRequest(input, init) || typeof init.body !== 'string') return nativeFetch(input, init);
            let payload;
            try {
                payload = JSON.parse(init.body);
            } catch (error) {
                return nativeFetch(input, init);
            }
            if (!payload || !Array.isArray(payload.upload_ids)) return nativeFetch(input, init);
            return finalizeGroups(input, init, payload);
        };
    }

    function showHandoff() {
        if (handled) return;
        handled = true;
        const panel = document.createElement('div');
        panel.className = 'bucket-next-phase';
        panel.innerHTML = '<strong>Upload phase complete.</strong> '
            + '<span data-countdown>Opening the processing queue in 3 seconds…</span> '
            + '<a class="button" href="' + queueUrl.replace(/"/g, '&quot;') + '">Open processing queue now</a> '
            + '<button type="button" class="button secondary" data-stay>Stay on this page</button>';
        progress.appendChild(panel);
        const countdown = panel.querySelector('[data-countdown]');
        const stay = panel.querySelector('[data-stay]');
        let seconds = 3;
        stay.addEventListener('click', function () {
            window.clearInterval(handoffTimer);
            countdown.textContent = 'Automatic handoff cancelled. Processing continues in the background.';
            stay.disabled = true;
        });
        handoffTimer = window.setInterval(function () {
            seconds--;
            if (seconds <= 0) {
                window.clearInterval(handoffTimer);
                window.location.assign(queueUrl);
                return;
            }
            countdown.textContent = 'Opening the processing queue in ' + seconds + ' second' + (seconds === 1 ? '' : 's') + '…';
        }, 1000);
    }

    function inspect() {
        const text = String(label.textContent || '').trim();
        if (/^Preparing durable upload staging/i.test(text)) {
            beginTimedPhase('prepare', 'Sending a short cooperative pause request; stale cleanup runs separately as background maintenance');
            return;
        }
        if (/^Waiting for the current Upload Bucket job/i.test(text)) {
            beginTimedPhase('prepare', text.replace(/\.$/, ''));
            return;
        }
        if (/^Upload Bucket processing paused\. Starting file checks/i.test(text)
            || /^(?:Calculating MD5|Checking physical duplicates|Preparing upload|Uploading chunk|Resuming chunk|Verifying transferred file)/i.test(text)) {
            beginTransferPhase();
            return;
        }
        if (/^Batch ready:/i.test(text)) {
            finishPhases(true);
            showHandoff();
            return;
        }
        if (/Previously queued Upload Bucket processing was resumed\./i.test(text)) {
            finishPhases(true);
            showHandoff();
            return;
        }
        if (/^(?:Upload batch could not start|All required files were transferred, but batch finalisation failed|No files required transfer, but)/i.test(text)) {
            finishPhases(false);
        }
    }

    installGroupedFinalization();
    if (form) {
        form.addEventListener('submit', function () {
            handled = false;
            operationActive = true;
            const existing = progress.querySelector('.bucket-next-phase');
            if (existing) existing.remove();
            beginTimedPhase('prepare', 'Sending the worker pause request');
        });
    }
    window.addEventListener('beforeunload', function (event) {
        if (!operationActive) return;
        event.preventDefault();
        event.returnValue = '';
    });
    new MutationObserver(inspect).observe(label, {childList: true, characterData: true, subtree: true});
    inspect();
}());
