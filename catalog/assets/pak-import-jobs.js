(function () {
    'use strict';
    const root = document.getElementById('pak-import-job');
    if (!root || !window.fetch) return;
    const queue = root.dataset.queue || 'catalog';
    const jobId = parseInt(root.dataset.jobId || '0', 10);
    const statusUrl = root.dataset.statusUrl || 'api/v1/job-status.php';
    const actionUrl = root.dataset.actionUrl || 'api/v1/job-action.php';
    const runUrl = root.dataset.runUrl || 'api/v1/job-run.php';
    const csrf = root.dataset.actionCsrf || '';
    const status = document.getElementById('pak-import-status');
    const progress = document.getElementById('pak-import-progress');
    const result = document.getElementById('pak-import-result');
    const cancel = document.getElementById('pak-import-cancel');
    let queuedPolls = 0;

    function escape(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function renderSummary(data) {
        const labels = {
            extracted_files: 'Extracted files', imported: 'Imported', aliases: 'Aliases',
            duplicates: 'Duplicates', failed: 'Moved to unverified/rejected', skipped: 'Skipped sidecar or unsupported files'
        };
        let html = '<table>';
        Object.keys(labels).forEach(function (key) {
            html += '<tr><th>' + escape(labels[key]) + '</th><td>' + escape(data[key] || 0) + '</td></tr>';
        });
        html += '</table>';
        if (Array.isArray(data.messages) && data.messages.length) {
            html += '<h3>Results</h3><table><tr><th>Status</th><th>Path</th><th>Message</th></tr>';
            data.messages.forEach(function (entry) {
                html += '<tr><td>' + escape(entry.status) + '</td><td class="mono path">' + escape(entry.file) + '</td><td>' + escape(entry.message) + '</td></tr>';
            });
            html += '</table>';
        }
        if (data.messages_truncated) html += '<p class="muted">Result details were truncated; totals above are complete.</p>';
        result.innerHTML = html;
    }

    async function readJob() {
        const response = await fetch(statusUrl + '?job_id=' + encodeURIComponent(jobId), {cache: 'no-store', credentials: 'same-origin'});
        const body = await response.json();
        const jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        if (!response.ok || !jobs.length) throw new Error('Job status is unavailable.');
        return jobs[0];
    }

    async function ensureWorker() {
        if (!csrf) return;
        try {
            await fetch(runUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({queue: queue, mode: 'drain'})
            });
        } catch (error) {
            // The server starts the worker when it queues the job. Polling retries
            // this only as a fallback when the job remains queued.
        }
    }

    async function poll() {
        try {
            const job = await readJob();
            const state = job.progress || {};
            const percent = Math.max(0, Math.min(100, parseInt(state.percent || (job.status === 'completed' ? 100 : 0), 10)));
            progress.value = percent;
            status.textContent = 'Job #' + jobId + ': ' + job.status + ' — ' + (state.message || 'waiting') + ' (' + percent + '%)';
            if (job.status === 'queued') {
                queuedPolls++;
                if (queuedPolls === 1 || queuedPolls % 4 === 0) await ensureWorker();
            } else {
                queuedPolls = 0;
            }
            if (job.status === 'completed') {
                cancel.hidden = true;
                renderSummary(job.result || {});
                return;
            }
            if (['failed', 'dead_letter', 'cancelled'].includes(job.status)) {
                cancel.hidden = true;
                result.textContent = job.last_error || state.message || 'The PAK import did not complete.';
                return;
            }
        } catch (error) {
            status.textContent = error.message || 'Could not read job status. Retrying...';
        }
        window.setTimeout(poll, 900);
    }

    cancel.addEventListener('click', async function () {
        if (!csrf) return;
        cancel.disabled = true;
        try {
            await fetch(actionUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({action: 'cancel', job_id: jobId, reason: 'Cancelled from PAK Import.'})
            });
        } finally {
            cancel.disabled = false;
        }
    });
    ensureWorker().finally(poll);
})();
