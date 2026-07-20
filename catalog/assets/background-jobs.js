(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    if (!app || !window.fetch) return;

    const queue = app.dataset.queue || 'catalog';
    const statusUrl = app.dataset.statusUrl || 'api/v1/job-status.php';
    const actionUrl = app.dataset.actionUrl || 'api/v1/job-action.php';
    const runUrl = app.dataset.runUrl || 'api/v1/job-run.php';
    const workerStatusUrl = app.dataset.workerStatusUrl || 'api/v1/job-worker-status.php';
    const workerActionUrl = app.dataset.workerActionUrl || 'api/v1/job-worker-action.php';
    const csrf = app.dataset.csrf || '';
    const tableBody = document.getElementById('jobs-table-body');
    const message = document.getElementById('jobs-message');
    const workerMessage = document.getElementById('jobs-worker-message');
    const filter = document.getElementById('jobs-status-filter');
    const runNextButton = document.getElementById('jobs-run-next');
    const runAllButton = document.getElementById('jobs-run-all');
    const stopButton = document.getElementById('jobs-stop');
    const recoverButton = document.getElementById('jobs-recover');
    const refreshButton = document.getElementById('jobs-refresh');

    let refreshActive = false;

    function errorMessage(body, fallback) {
        return body && body.error && body.error.message ? String(body.error.message) : fallback;
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
            throw new Error(errorMessage(body, 'The request failed.'));
        }
        return body;
    }

    async function readJobs(statusOverride) {
        const selected = typeof statusOverride === 'string' ? statusOverride : (filter ? filter.value : '');
        const params = new URLSearchParams({queue: queue, limit: '200'});
        if (selected) params.set('status', selected);
        const body = await jsonRequest(statusUrl + '?' + params.toString(), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        return body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
    }

    async function readWorker() {
        const params = new URLSearchParams({queue: queue});
        const body = await jsonRequest(workerStatusUrl + '?' + params.toString(), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        return body && body.data ? body.data.worker || {} : {};
    }

    function appendCell(row, value, className) {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value == null ? '' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function targetLabel(job) {
        const payload = job.payload || {};
        if (payload.source_relative_path) return String(payload.source_relative_path);
        if (payload.original_name) return String(payload.original_name);
        if (payload.file_id) return 'File #' + payload.file_id;
        if (payload.game_id) return 'Game #' + payload.game_id;
        return job.concurrency_key || '';
    }

    function renderProgress(cell, job) {
        const state = job.progress || {};
        const percent = Math.max(0, Math.min(100, parseInt(state.percent || (job.status === 'completed' ? 100 : 0), 10) || 0));
        const bar = document.createElement('progress');
        bar.max = 100;
        bar.value = percent;
        bar.style.width = '140px';
        cell.appendChild(bar);
        const text = document.createElement('div');
        text.className = 'muted';
        text.textContent = percent + '% ' + String(state.message || '');
        cell.appendChild(text);
    }

    async function mutate(action, extra) {
        return jsonRequest(actionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(Object.assign({action: action, queue: queue}, extra || {}))
        });
    }

    async function launch(mode) {
        return jsonRequest(runUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({queue: queue, mode: mode})
        });
    }

    async function stopWorker() {
        return jsonRequest(workerActionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({action: 'stop', queue: queue, cancel_running: true})
        });
    }

    function actionButton(label, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                await handler();
                await refresh();
            } catch (error) {
                message.textContent = error.message || 'Job action failed.';
            } finally {
                button.disabled = false;
            }
        });
        return button;
    }

    function render(jobs) {
        tableBody.textContent = '';
        if (!jobs.length) {
            const row = document.createElement('tr');
            const cell = appendCell(row, 'No jobs match the current filter.', 'muted');
            cell.colSpan = 9;
            tableBody.appendChild(row);
            return;
        }

        jobs.forEach(function (job) {
            const row = document.createElement('tr');
            appendCell(row, job.id, 'mono');
            appendCell(row, job.status);
            appendCell(row, job.job_type, 'mono');
            appendCell(row, targetLabel(job), 'mono path');
            const progressCell = appendCell(row, '');
            renderProgress(progressCell, job);
            appendCell(row, String(job.attempts || 0) + '/' + String(job.max_attempts || 0));
            appendCell(row, job.created_at || '');
            appendCell(row, job.last_error || '', 'path');

            const actions = appendCell(row, '');
            if (job.status === 'queued') {
                actions.appendChild(actionButton('Cancel', function () {
                    return mutate('cancel', {job_id: job.id, reason: 'Cancelled from Background Jobs.'});
                }));
            } else if (job.status === 'running') {
                actions.appendChild(actionButton('Stop', function () {
                    return mutate('cancel', {job_id: job.id, reason: 'Stopped from Background Jobs.'});
                }));
            } else if (job.status === 'dead_letter' || job.status === 'failed') {
                actions.appendChild(actionButton('Retry', function () {
                    return mutate('retry', {job_id: job.id});
                }));
            }
            tableBody.appendChild(row);
        });
    }

    function renderWorker(worker) {
        const state = worker && worker.state ? worker.state : {};
        const active = Boolean(worker && worker.active);
        const processed = parseInt(state.processed || 0, 10) || 0;
        const status = active ? 'running' : String(state.status || 'stopped');
        const detail = active
            ? 'Detached worker is running. Processed ' + processed + ' job(s). Closing this page will not stop it.'
            : 'Detached worker is ' + status + '. ' + (state.exit_reason ? 'Last exit: ' + state.exit_reason + '.' : '');
        workerMessage.textContent = detail;
        runNextButton.disabled = active;
        runAllButton.disabled = active;
        stopButton.disabled = !active;
    }

    async function refresh() {
        if (refreshActive) return;
        refreshActive = true;
        try {
            const results = await Promise.all([readJobs(), readWorker()]);
            const jobs = results[0];
            const worker = results[1];
            render(jobs);
            renderWorker(worker);
            const queued = jobs.filter(function (job) { return job.status === 'queued'; }).length;
            const running = jobs.filter(function (job) { return job.status === 'running'; }).length;
            message.textContent = queued + ' queued, ' + running + ' running. Showing ' + jobs.length + ' job(s).';
        } catch (error) {
            message.textContent = error.message || 'Could not refresh jobs.';
        } finally {
            refreshActive = false;
        }
    }

    runNextButton.addEventListener('click', async function () {
        runNextButton.disabled = true;
        try {
            const body = await launch('next');
            const data = body && body.data ? body.data : {};
            workerMessage.textContent = data.started === false
                ? 'A detached worker is already running.'
                : 'Detached worker launched for the next available job.';
        } catch (error) {
            workerMessage.textContent = error.message || 'Could not start the detached worker.';
        }
        await refresh();
    });

    runAllButton.addEventListener('click', async function () {
        runAllButton.disabled = true;
        try {
            const body = await launch('drain');
            const data = body && body.data ? body.data : {};
            workerMessage.textContent = data.started === false
                ? 'A detached worker is already running and will continue draining available jobs.'
                : 'Detached worker launched to drain the available queue.';
        } catch (error) {
            workerMessage.textContent = error.message || 'Could not start the detached worker.';
        }
        await refresh();
    });

    stopButton.addEventListener('click', async function () {
        stopButton.disabled = true;
        try {
            const body = await stopWorker();
            const data = body && body.data ? body.data : {};
            workerMessage.textContent = 'Stop requested. ' + String(data.running_jobs_notified || 0) + ' running job(s) were notified.';
        } catch (error) {
            workerMessage.textContent = error.message || 'Could not stop the detached worker.';
        }
        await refresh();
    });

    recoverButton.addEventListener('click', async function () {
        recoverButton.disabled = true;
        try {
            const body = await mutate('recover');
            const data = body && body.data ? body.data : {};
            message.textContent = 'Recovery complete: ' + String(data.requeued || 0) + ' requeued, '
                + String(data.cancelled || 0) + ' cancelled, ' + String(data.dead_lettered || 0) + ' dead-lettered.';
        } catch (error) {
            message.textContent = error.message || 'Recovery failed.';
        } finally {
            recoverButton.disabled = false;
            await refresh();
        }
    });

    refreshButton.addEventListener('click', refresh);
    if (filter) filter.addEventListener('change', refresh);

    refresh();
    window.setInterval(function () {
        if (!document.hidden) refresh();
    }, 2000);
})();
