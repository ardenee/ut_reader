(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    const tableBody = document.getElementById('jobs-table-body');
    const message = document.getElementById('jobs-message');
    if (!app || !tableBody || !window.fetch) return;

    const queue = app.dataset.queue || 'catalog';
    const statusUrl = app.dataset.statusUrl || 'api/v1/job-status.php';
    const actionUrl = app.dataset.actionUrl || 'api/v1/job-action.php';
    const runUrl = app.dataset.runUrl || 'api/v1/job-run.php';
    const csrf = app.dataset.csrf || '';
    const jobs = new Map();
    let reading = false;

    function sleep(milliseconds) {
        return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
    }

    function utcTimestamp(value) {
        const text = String(value || '').trim();
        if (!text) return 0;
        const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)
            ? text.replace(' ', 'T') + 'Z'
            : text;
        const timestamp = Date.parse(normalized);
        return Number.isFinite(timestamp) ? timestamp : 0;
    }

    function durationText(milliseconds) {
        let seconds = Math.max(0, Math.floor(milliseconds / 1000));
        const days = Math.floor(seconds / 86400);
        seconds -= days * 86400;
        const hours = Math.floor(seconds / 3600);
        seconds -= hours * 3600;
        const minutes = Math.floor(seconds / 60);
        seconds -= minutes * 60;

        const parts = [];
        if (days) parts.push(days + 'd');
        if (days || hours) parts.push(hours + 'h');
        if (days || hours || minutes) parts.push(minutes + 'm');
        parts.push(seconds + 's');
        return parts.join(' ');
    }

    function jobIdForRow(row) {
        if (!row || !row.cells || row.cells.length < 2) return 0;
        return Math.max(0, parseInt(row.cells[1].textContent || '0', 10) || 0);
    }

    function ensureRuntimeHeader() {
        const table = tableBody.closest('table');
        const row = table ? table.querySelector('thead tr') : null;
        if (!row || row.querySelector('[data-running-for-column]')) return;
        const cell = document.createElement('th');
        cell.textContent = 'Running for';
        cell.setAttribute('data-running-for-column', '1');
        const attempts = row.cells[6] || null;
        row.insertBefore(cell, attempts);
    }

    function setText(element, value) {
        if (element.textContent !== value) element.textContent = value;
    }

    function decorateRows() {
        ensureRuntimeHeader();
        Array.from(tableBody.rows || []).forEach(function (row) {
            const jobId = jobIdForRow(row);
            if (!jobId) {
                if (row.cells.length === 1) row.cells[0].colSpan = 11;
                return;
            }

            const job = jobs.get(jobId);
            if (!job) return;

            let runtimeCell = row.querySelector('[data-running-for-cell]');
            if (!runtimeCell) {
                runtimeCell = document.createElement('td');
                runtimeCell.className = 'mono job-running-for';
                runtimeCell.setAttribute('data-running-for-cell', '1');
                row.insertBefore(runtimeCell, row.cells[6] || null);
            }

            const startedAt = utcTimestamp(job.leased_at);
            const finishedAt = utcTimestamp(job.completed_at);
            if (String(job.status || '') === 'running' && startedAt > 0) {
                setText(runtimeCell, durationText(Date.now() - startedAt));
                runtimeCell.title = 'Running since ' + String(job.leased_at || '');
            } else if (startedAt > 0 && finishedAt >= startedAt) {
                setText(runtimeCell, durationText(finishedAt - startedAt));
                runtimeCell.title = 'Total execution time';
            } else if (String(job.status || '') === 'queued') {
                setText(runtimeCell, 'Not started');
                runtimeCell.title = '';
            } else {
                setText(runtimeCell, '—');
                runtimeCell.title = '';
            }

            if (String(job.status || '') === 'running') {
                const actions = row.cells[row.cells.length - 1];
                Array.from(actions ? actions.querySelectorAll('button') : []).forEach(function (button) {
                    if (button.textContent.trim() === 'Stop') {
                        button.textContent = 'Stop job';
                        button.title = 'Stop this job and immediately continue with the next queued job.';
                        button.setAttribute('data-stop-job', String(jobId));
                    }
                });
            }
        });
    }

    async function jsonRequest(url, options) {
        const response = await fetch(url, options || {});
        let body = null;
        try {
            body = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }
        if (!response.ok) {
            const detail = body && body.error && body.error.message ? String(body.error.message) : 'The request failed.';
            throw new Error(detail);
        }
        return body;
    }

    async function readJobs() {
        if (reading) return;
        reading = true;
        try {
            const params = new URLSearchParams({queue: queue, limit: '200'});
            const body = await jsonRequest(statusUrl + '?' + params.toString(), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const rows = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
            jobs.clear();
            rows.forEach(function (job) { jobs.set(Number(job.id), job); });
            decorateRows();
        } catch (error) {
            // The main Background Jobs client continues to report service errors.
        } finally {
            reading = false;
        }
    }

    async function launchQueueAfterStop() {
        let lastBody = null;
        for (let attempt = 0; attempt < 60; attempt++) {
            lastBody = await jsonRequest(runUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({queue: queue, mode: 'drain'})
            });
            const data = lastBody && lastBody.data ? lastBody.data : {};
            if (data.started !== false) return {started: true, body: lastBody};
            await sleep(500);
        }
        return {started: false, body: lastBody};
    }

    async function stopJobAndContinue(jobId, button) {
        button.disabled = true;
        if (message) message.textContent = 'Stopping job #' + jobId + ' and continuing the queue...';
        try {
            await jsonRequest(actionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({
                    action: 'cancel',
                    queue: queue,
                    job_id: jobId,
                    reason: 'Stopped manually from Background Jobs so the queue could continue.'
                })
            });

            const continuation = await launchQueueAfterStop();
            if (message) {
                message.textContent = continuation.started
                    ? 'Job #' + jobId + ' was stopped. A worker was started for the next queued job.'
                    : 'Job #' + jobId + ' was stopped, but the previous worker is still shutting down. Press Start queued if it remains inactive.';
            }
        } catch (error) {
            if (message) message.textContent = error.message || 'Could not stop the job and continue the queue.';
        } finally {
            button.disabled = false;
            window.setTimeout(readJobs, 500);
        }
    }

    tableBody.addEventListener('click', function (event) {
        const button = event.target && event.target.closest ? event.target.closest('button') : null;
        if (!button) return;
        const label = button.textContent.trim();
        if (!button.hasAttribute('data-stop-job') && label !== 'Stop' && label !== 'Stop job') return;
        const row = button.closest('tr');
        const jobId = Math.max(
            0,
            parseInt(button.getAttribute('data-stop-job') || '0', 10) || jobIdForRow(row)
        );
        if (!jobId) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        stopJobAndContinue(jobId, button);
    }, true);

    const observer = new MutationObserver(decorateRows);
    observer.observe(tableBody, {childList: true});

    ensureRuntimeHeader();
    readJobs();
    window.setInterval(function () {
        decorateRows();
        if (!document.hidden) readJobs();
    }, 1000);
}());
