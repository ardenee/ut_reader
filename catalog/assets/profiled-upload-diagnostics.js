(function () {
    'use strict';

    const progress = document.getElementById('profiled-upload-progress');
    const currentLabel = document.getElementById('upload-progress-label');
    const log = document.getElementById('upload-progress-log');
    if (!progress || !currentLabel || !log || !window.fetch) return;

    const queue = progress.dataset.queue || 'catalog';
    const jobStatusUrl = progress.dataset.statusUrl || 'api/v1/job-status.php';
    const workerStatusUrl = progress.dataset.workerStatusUrl || 'api/v1/job-worker-status.php';
    const runUrl = progress.dataset.runUrl || 'api/v1/job-run.php';
    const csrf = progress.dataset.actionCsrf || '';
    let currentJobId = 0;
    let lastSignature = '';
    let lastDiagnosticAt = 0;
    let restartAttemptedFor = 0;
    let lastLogLine = '';

    function addDiagnostic(status, message) {
        const row = document.createElement('div');
        row.className = 'upload-result upload-result-' + String(status || 'running').replace(/[^a-z0-9_-]+/gi, '-').toLowerCase();
        const badge = document.createElement('span');
        badge.className = 'upload-result-badge';
        badge.textContent = String(status || 'diagnostic').replace(/_/g, ' ');
        row.appendChild(badge);
        const text = document.createElement('span');
        text.className = 'upload-result-message';
        text.textContent = String(message || '');
        row.appendChild(text);
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function parseUtc(value) {
        if (!value) return 0;
        const text = String(value).trim();
        const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)
            ? text.replace(' ', 'T') + 'Z'
            : text;
        const timestamp = Date.parse(normalized);
        return Number.isFinite(timestamp) ? timestamp : 0;
    }

    function ageText(value) {
        const timestamp = parseUtc(value);
        if (!timestamp) return 'unknown';
        const seconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));
        if (seconds < 60) return seconds + 's';
        const minutes = Math.floor(seconds / 60);
        const remainder = seconds % 60;
        return minutes + 'm ' + remainder + 's';
    }

    async function readJson(url, options) {
        const response = await fetch(url, options || {});
        const text = await response.text();
        let body = null;
        try {
            body = JSON.parse(text || '{}');
        } catch (error) {
            throw new Error('Diagnostic endpoint returned invalid JSON (HTTP ' + response.status + ').');
        }
        if (!response.ok) {
            const message = body && body.error && body.error.message ? body.error.message : 'Diagnostic request failed.';
            throw new Error(message);
        }
        return body;
    }

    async function readJob(jobId) {
        const params = new URLSearchParams({job_id: String(jobId), event_offset: '0', event_limit: '1'});
        const body = await readJson(jobStatusUrl + '?' + params.toString(), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        return jobs.length ? jobs[0] : null;
    }

    async function readWorker() {
        const params = new URLSearchParams({queue: queue});
        const body = await readJson(workerStatusUrl + '?' + params.toString(), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        return body && body.data ? body.data.worker || {} : {};
    }

    async function restartCurrentWorker(jobId) {
        if (!csrf || restartAttemptedFor === jobId) return;
        restartAttemptedFor = jobId;
        addDiagnostic('running', 'The detached worker loaded an older code revision. Restarting it and requeueing the active import without consuming an attempt.');
        try {
            const body = await readJson(runUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({queue: queue, mode: 'drain'})
            });
            const data = body && body.data ? body.data : {};
            const restart = data.stale_restart || {};
            addDiagnostic(
                'queued',
                restart && restart.restarted
                    ? 'Stale worker replaced; ' + String(restart.requeued_jobs || 0) + ' running job(s) requeued.'
                    : 'Worker launch request completed.'
            );
        } catch (error) {
            addDiagnostic('failed', 'Automatic worker restart failed: ' + (error.message || 'unknown error'));
        }
    }

    async function poll() {
        if (progress.hidden) return;
        const match = currentLabel.textContent.match(/Worker job\s+#(\d+)/i);
        const jobId = match ? Number(match[1]) : 0;
        if (!jobId) return;
        if (jobId !== currentJobId) {
            currentJobId = jobId;
            lastSignature = '';
            lastDiagnosticAt = 0;
            lastLogLine = '';
            restartAttemptedFor = 0;
        }

        try {
            const results = await Promise.all([readJob(jobId), readWorker()]);
            const job = results[0];
            const worker = results[1] || {};
            if (!job || !['queued', 'running'].includes(String(job.status || ''))) return;

            const state = job.progress || {};
            const stage = String(state.stage || (job.status === 'queued' ? 'queued' : 'no checkpoint'));
            const percent = Math.max(0, Math.min(100, Number(state.percent || 0)));
            const workerState = worker.state || {};
            const active = Boolean(worker.active);
            const stale = Boolean(worker.stale_code);
            const pid = Number(workerState.pid || 0);
            const heartbeatAge = ageText(job.last_heartbeat_at || job.leased_at);
            const progressAge = ageText(job.progress_updated_at || job.last_heartbeat_at || job.leased_at);
            const signature = [job.status, stage, percent, active, stale, pid, job.last_heartbeat_at, job.progress_updated_at].join('|');
            const now = Date.now();

            if (signature !== lastSignature || now - lastDiagnosticAt >= 10000) {
                const codeState = stale ? 'STALE CODE' : (active ? 'current code' : 'not active');
                addDiagnostic(
                    stale ? 'failed' : 'running',
                    'Job #' + jobId + ': ' + stage + ' at ' + percent + '%. Worker ' + codeState
                        + (pid ? ', PID ' + pid : '') + '. Last DB heartbeat ' + heartbeatAge
                        + ' ago; last progress update ' + progressAge + ' ago.'
                );
                lastSignature = signature;
                lastDiagnosticAt = now;
            }

            const tail = String(worker.log_tail || '').trim();
            if (tail) {
                const lines = tail.split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean);
                const line = lines.length ? lines[lines.length - 1] : '';
                if (line && line !== lastLogLine) {
                    lastLogLine = line;
                    addDiagnostic('running', 'Worker log: ' + line.slice(0, 1800));
                }
            }

            if (stale) await restartCurrentWorker(jobId);
        } catch (error) {
            const message = error && error.message ? error.message : 'Worker diagnostics unavailable.';
            if (message !== lastSignature) {
                lastSignature = message;
                addDiagnostic('failed', message);
            }
        }
    }

    window.setInterval(poll, 2000);
}());
