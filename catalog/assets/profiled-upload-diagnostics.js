(function () {
    'use strict';

    const progress = document.getElementById('profiled-upload-progress');
    const currentLabel = document.getElementById('upload-progress-label');
    const log = document.getElementById('upload-progress-log');
    if (!progress || !currentLabel || !log || !window.fetch || !window.MutationObserver) return;

    const queue = progress.dataset.queue || 'catalog';
    const jobStatusUrl = progress.dataset.statusUrl || 'api/v1/job-status.php';
    const workerStatusUrl = progress.dataset.workerStatusUrl || 'api/v1/job-worker-status.php';
    const runUrl = progress.dataset.runUrl || 'api/v1/job-run.php';
    const csrf = progress.dataset.actionCsrf || '';
    let currentJobId = 0;
    let restartAttemptedFor = 0;
    let compacting = false;

    /**
     * The main uploader receives useful stage events, but the operator-facing
     * feedback is a file summary, not a raw worker trace. Keep only the newest
     * row for each source file. Full diagnostics remain available on Background
     * Jobs and in the detached worker log.
     */
    function compactFeedback() {
        if (compacting) return;
        compacting = true;
        try {
            const latestByFile = new Map();
            Array.from(log.querySelectorAll('.upload-result')).forEach(function (row) {
                const file = row.querySelector('.upload-result-file');
                if (!file) {
                    row.remove();
                    return;
                }
                const key = String(file.textContent || '').trim().toLowerCase();
                if (!key) {
                    row.remove();
                    return;
                }
                const previous = latestByFile.get(key);
                if (previous && previous !== row) previous.remove();
                latestByFile.set(key, row);
            });
        } finally {
            compacting = false;
        }
    }

    new MutationObserver(compactFeedback).observe(log, {childList: true});
    compactFeedback();

    async function readJson(url, options) {
        const response = await fetch(url, options || {});
        const text = await response.text();
        let body = null;
        try {
            body = JSON.parse(text || '{}');
        } catch (error) {
            throw new Error('Worker status returned invalid JSON (HTTP ' + response.status + ').');
        }
        if (!response.ok) {
            const message = body && body.error && body.error.message ? body.error.message : 'Worker status request failed.';
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

    async function restartStaleWorker(jobId) {
        if (!csrf || restartAttemptedFor === jobId) return;
        restartAttemptedFor = jobId;
        currentLabel.textContent = 'Worker job #' + jobId + ' is using old code; restarting worker and preserving the queued import...';
        await readJson(runUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({queue: queue, mode: 'drain'})
        });
    }

    async function poll() {
        if (progress.hidden) return;
        const match = currentLabel.textContent.match(/Worker job\s+#(\d+)/i);
        const jobId = match ? Number(match[1]) : 0;
        if (!jobId) return;
        if (jobId !== currentJobId) {
            currentJobId = jobId;
            restartAttemptedFor = 0;
        }

        try {
            const results = await Promise.all([readJob(jobId), readWorker()]);
            const job = results[0];
            const worker = results[1] || {};
            if (!job || !['queued', 'running'].includes(String(job.status || ''))) return;

            const state = job.progress || {};
            const stage = String(state.stage || (job.status === 'queued' ? 'queued' : 'starting'));
            const percent = Math.max(0, Math.min(100, Number(state.percent || 0)));
            const workerState = worker.state || {};
            const pid = Number(workerState.pid || 0);
            currentLabel.title = 'Stage: ' + stage + '; progress: ' + percent + '%; worker PID: ' + (pid || 'unknown') + '.';

            if (worker.stale_code) await restartStaleWorker(jobId);
        } catch (error) {
            // Keep the normal one-row-per-file feedback uncluttered. The detailed
            // failure remains visible on Background Jobs and in server logs.
            currentLabel.title = error && error.message ? error.message : 'Worker diagnostics unavailable.';
        }
    }

    window.setInterval(poll, 2000);
}());
