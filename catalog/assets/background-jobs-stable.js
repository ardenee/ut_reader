(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    if (!app || !window.fetch) return;

    const queue = app.dataset.queue || 'catalog';
    const statusUrl = app.dataset.statusUrl || 'api/v1/job-status.php';
    const bulkUrl = app.dataset.bulkUrl || 'api/v1/job-bulk.php';
    const actionUrl = app.dataset.actionUrl || 'api/v1/job-action.php';
    const runUrl = app.dataset.runUrl || 'api/v1/job-run.php';
    const workerStatusUrl = app.dataset.workerStatusUrl || 'api/v1/job-worker-status.php';
    const workerActionUrl = app.dataset.workerActionUrl || 'api/v1/job-worker-action.php';
    const pakRerunUrl = app.dataset.pakRerunUrl || 'api/v1/job-rerun-pak.php';
    const csrf = app.dataset.csrf || '';

    const tableBody = document.getElementById('jobs-table-body');
    const message = document.getElementById('jobs-message');
    const workerState = document.getElementById('jobs-worker-state');
    const startButton = document.getElementById('jobs-start');
    const stopWorkerButton = document.getElementById('jobs-stop-worker');
    const refreshButton = document.getElementById('jobs-refresh');
    const searchInput = document.getElementById('jobs-search');
    const pageSizeSelect = document.getElementById('jobs-page-size');
    const tabs = document.getElementById('jobs-status-tabs');
    const selectPage = document.getElementById('jobs-select-page');
    const selectionSummary = document.getElementById('jobs-selection-summary');
    const selectMatchingButton = document.getElementById('jobs-select-matching');
    const clearSelectionButton = document.getElementById('jobs-clear-selection');
    const bulkAction = document.getElementById('jobs-bulk-action');
    const applyActionButton = document.getElementById('jobs-apply-action');
    const pageSummary = document.getElementById('jobs-page-summary');
    const pageLabel = document.getElementById('jobs-page-label');
    const firstPageButton = document.getElementById('jobs-first-page');
    const previousPageButton = document.getElementById('jobs-previous-page');
    const nextPageButton = document.getElementById('jobs-next-page');
    const lastPageButton = document.getElementById('jobs-last-page');
    const recoverButton = document.getElementById('jobs-recover');
    const cleanupButton = document.getElementById('jobs-cleanup');
    const cleanupDays = document.getElementById('jobs-cleanup-days');

    if (!tableBody || !message || !tabs || !workerState) return;

    const validStatuses = ['', 'queued', 'running', 'completed', 'failed', 'dead_letter', 'partial_archive', 'cancelled'];
    const terminalQueueStatuses = ['completed', 'failed', 'dead_letter', 'cancelled'];
    const failedDisplayStatuses = ['failed', 'rejected', 'unverified'];
    const query = new URLSearchParams(window.location.search);
    const requestedStatus = String(query.get('status') || '').toLowerCase();
    const requestedPerPage = parseInt(query.get('per_page') || '100', 10) || 100;
    const rowPairs = new Map();

    const state = {
        status: validStatuses.includes(requestedStatus) ? requestedStatus : '',
        search: String(query.get('search') || ''),
        page: Math.max(1, parseInt(query.get('page') || '1', 10) || 1),
        perPage: [50, 100, 250, 500, 1000].includes(requestedPerPage) ? requestedPerPage : 100,
        jobs: [],
        meta: {page: 1, per_page: 100, total: 0, pages: 1, counts: {}},
        worker: {},
        selected: new Map(),
        allMatching: false,
        loading: false,
        noticeUntil: 0,
        searchTimer: 0
    };

    searchInput.value = state.search;
    pageSizeSelect.value = String(state.perPage);

    function requestReference(body) {
        if (!body || typeof body !== 'object') return '';
        if (body.request_id) return String(body.request_id);
        if (body.error && body.error.request_id) return String(body.error.request_id);
        if (body.error && body.error.details && body.error.details.request_id) return String(body.error.details.request_id);
        return '';
    }

    function responseError(body, fallback) {
        let text = fallback;
        if (body && body.error && body.error.message) text = String(body.error.message);
        else if (body && typeof body.error === 'string') text = body.error;
        const reference = requestReference(body);
        return reference && text.indexOf(reference) === -1 ? text + ' | reference: ' + reference : text;
    }

    async function jsonRequest(url, options) {
        const response = await fetch(url, options || {});
        let body;
        try {
            body = await response.json();
        } catch (error) {
            throw new Error('The server returned invalid JSON (HTTP ' + response.status + ').');
        }
        if (!response.ok) throw new Error(responseError(body, 'The request failed with HTTP ' + response.status + '.'));
        return body;
    }

    async function postJson(url, payload) {
        return jsonRequest(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(payload)
        });
    }

    function setNotice(text, milliseconds) {
        message.textContent = text;
        state.noticeUntil = Date.now() + (milliseconds || 5000);
    }

    function setText(element, value) {
        const text = value == null ? '' : String(value);
        if (element.textContent !== text) element.textContent = text;
    }

    function updateUrl() {
        const params = new URLSearchParams(window.location.search);
        params.set('queue', queue);
        if (state.status) params.set('status', state.status); else params.delete('status');
        if (state.search) params.set('search', state.search); else params.delete('search');
        if (state.page > 1) params.set('page', String(state.page)); else params.delete('page');
        if (state.perPage !== 100) params.set('per_page', String(state.perPage)); else params.delete('per_page');
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }

    function parseUtc(value) {
        const text = String(value || '').trim();
        if (!text) return 0;
        const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text) ? text.replace(' ', 'T') + 'Z' : text;
        const timestamp = Date.parse(normalized);
        return Number.isFinite(timestamp) ? timestamp : 0;
    }

    function formatDate(value) {
        const timestamp = parseUtc(value);
        return timestamp ? new Date(timestamp).toLocaleString() : String(value || '');
    }

    function formatDuration(milliseconds) {
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

    function targetLabel(job) {
        const payload = job.payload || {};
        if (payload.source_relative_path) return String(payload.source_relative_path);
        if (payload.original_name) return String(payload.original_name);
        if (payload.file_id) return 'File #' + payload.file_id;
        if (payload.game_id) return 'Game #' + payload.game_id;
        return String(job.concurrency_key || '');
    }

    function displayStatus(job) {
        return String(job.display_status || job.status || 'unknown').toLowerCase();
    }

    function queueStatus(job) {
        return String(job.status || '').toLowerCase();
    }

    function retryBlocked(job) {
        return Boolean(job && job.retry_blocked === true);
    }

    function retryable(job) {
        if (retryBlocked(job)) return false;
        return ['cancelled', 'failed', 'dead_letter'].includes(queueStatus(job)) || failedDisplayStatuses.includes(displayStatus(job));
    }

    function deletable(job) {
        return terminalQueueStatuses.includes(queueStatus(job));
    }

    function runtimeText(job) {
        const started = parseUtc(job.leased_at);
        if (!started) return queueStatus(job) === 'queued' ? 'Waiting' : '—';
        if (queueStatus(job) === 'running') return formatDuration(Date.now() - started);
        const finished = parseUtc(job.completed_at);
        return finished >= started ? formatDuration(finished - started) : '—';
    }

    function jobError(job) {
        if (job.last_error) return String(job.last_error);
        if (job.cancel_reason) return String(job.cancel_reason);
        if (job.result && job.result.message) return String(job.result.message);
        return '';
    }

    function rowAction(job) {
        const status = queueStatus(job);
        if (status === 'queued') return {label: 'Cancel', action: 'cancel'};
        if (status === 'running') return {label: 'Stop job', action: 'stop'};
        if (retryBlocked(job) && (['cancelled', 'failed', 'dead_letter'].includes(status) || failedDisplayStatuses.includes(displayStatus(job)))) {
            return {
                label: 'Non-retryable',
                action: '',
                disabled: true,
                title: String(job.retry_block_reason || 'The same retained bytes cannot succeed if restarted.')
            };
        }
        if (retryable(job)) return {label: 'Restart', action: 'restart'};
        if (status === 'completed' && String(job.job_type || '') === 'catalog.import_staged_pak') return {label: 'Re-run PAK', action: 'rerun_pak'};
        if (deletable(job)) return {label: 'Delete', action: 'delete'};
        return null;
    }

    function appendCell(row, className) {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        row.appendChild(cell);
        return cell;
    }

    function createRowPair(job) {
        const main = document.createElement('tr');
        main.className = 'jobs-main-row';
        main.dataset.jobId = String(job.id);

        const selectCell = appendCell(main, 'jobs-select-cell');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'jobs-row-checkbox';
        checkbox.setAttribute('aria-label', 'Select job #' + job.id);
        selectCell.appendChild(checkbox);

        const idCell = appendCell(main, 'mono jobs-id');
        const statusCell = appendCell(main, 'jobs-status-cell');
        const badge = document.createElement('span');
        statusCell.appendChild(badge);
        const typeCell = appendCell(main, 'mono jobs-type');
        const targetCell = appendCell(main, 'mono path jobs-target');
        const runtimeCell = appendCell(main, 'mono jobs-running-for');
        const attemptsCell = appendCell(main, 'jobs-attempts');
        const createdCell = appendCell(main, 'jobs-created');
        const actionCell = appendCell(main, 'jobs-actions');
        const actionButton = document.createElement('button');
        actionButton.type = 'button';
        actionCell.appendChild(actionButton);

        const detail = document.createElement('tr');
        detail.className = 'jobs-detail-row';
        detail.dataset.jobId = String(job.id);
        const detailCell = document.createElement('td');
        detailCell.colSpan = 9;
        detail.appendChild(detailCell);

        const card = document.createElement('div');
        card.className = 'jobs-detail-card';
        const progressWrap = document.createElement('div');
        progressWrap.className = 'jobs-detail-progress';
        const progressBar = document.createElement('progress');
        progressBar.max = 100;
        progressWrap.appendChild(progressBar);
        const percentText = document.createElement('span');
        percentText.className = 'mono';
        progressWrap.appendChild(percentText);

        const detailText = document.createElement('div');
        detailText.className = 'jobs-detail-text';
        const stageText = document.createElement('strong');
        const messageText = document.createElement('span');
        detailText.appendChild(stageText);
        detailText.appendChild(messageText);

        const detailMeta = document.createElement('div');
        detailMeta.className = 'jobs-detail-meta muted';
        const updateText = document.createElement('span');
        const errorText = document.createElement('span');
        errorText.className = 'jobs-detail-error';
        detailMeta.appendChild(updateText);
        detailMeta.appendChild(errorText);

        card.appendChild(progressWrap);
        card.appendChild(detailText);
        card.appendChild(detailMeta);
        detailCell.appendChild(card);

        const pair = {
            job: job,
            main: main,
            detail: detail,
            checkbox: checkbox,
            idCell: idCell,
            badge: badge,
            typeCell: typeCell,
            targetCell: targetCell,
            runtimeCell: runtimeCell,
            attemptsCell: attemptsCell,
            createdCell: createdCell,
            actionButton: actionButton,
            progressBar: progressBar,
            percentText: percentText,
            stageText: stageText,
            messageText: messageText,
            updateText: updateText,
            errorText: errorText
        };

        checkbox.addEventListener('change', function () {
            const id = Number(pair.job.id);
            if (checkbox.checked) state.selected.set(id, pair.job);
            else state.selected.delete(id);
            updateSelectionUi();
        });
        actionButton.addEventListener('click', function () {
            const action = String(actionButton.dataset.action || '');
            if (action) runRowAction(action, pair.job, actionButton);
        });
        return pair;
    }

    function progressView(job) {
        const progress = job.progress || {};
        const status = queueStatus(job);
        const fallback = status === 'completed' ? 100 : 0;
        const percent = Math.max(0, Math.min(100, parseInt(progress.percent == null ? fallback : progress.percent, 10) || 0));
        let stage = String(progress.stage || '').replace(/_/g, ' ').trim();
        let text = String(progress.message || '').trim();
        if (!stage) {
            if (status === 'queued') stage = 'Queued';
            else if (status === 'running') stage = 'Running';
            else stage = displayStatus(job).replace(/_/g, ' ');
        }
        if (!text) {
            if (status === 'queued') text = 'Waiting for the worker to claim this job.';
            else if (job.result && job.result.message) text = String(job.result.message);
            else text = 'No additional status message has been recorded.';
        }
        return {percent: percent, stage: stage, text: text};
    }

    function updatePair(pair, job) {
        pair.job = job;
        const id = Number(job.id);
        if (state.selected.has(id)) state.selected.set(id, job);
        pair.checkbox.disabled = state.allMatching;
        pair.checkbox.checked = state.allMatching || state.selected.has(id);

        setText(pair.idCell, job.id);
        const status = displayStatus(job);
        pair.badge.className = 'job-status job-status-' + status.replace(/[^a-z0-9_-]+/g, '-');
        setText(pair.badge, status.replace(/_/g, ' '));
        setText(pair.typeCell, job.job_type);
        setText(pair.targetCell, targetLabel(job));
        setText(pair.runtimeCell, runtimeText(job));
        setText(pair.attemptsCell, String(job.attempts || 0) + '/' + String(job.max_attempts || 0));
        setText(pair.createdCell, formatDate(job.created_at));

        const action = rowAction(job);
        if (action) {
            pair.actionButton.hidden = false;
            pair.actionButton.dataset.action = action.action;
            pair.actionButton.disabled = Boolean(action.disabled);
            pair.actionButton.title = String(action.title || '');
            setText(pair.actionButton, action.label);
        } else {
            pair.actionButton.hidden = true;
            pair.actionButton.dataset.action = '';
            pair.actionButton.disabled = false;
            pair.actionButton.title = '';
            setText(pair.actionButton, '');
        }

        const view = progressView(job);
        pair.progressBar.value = view.percent;
        setText(pair.percentText, view.percent + '%');
        setText(pair.stageText, view.stage);
        setText(pair.messageText, view.text);

        const updated = parseUtc(job.progress_updated_at || job.last_heartbeat_at || job.updated_at);
        setText(pair.updateText, updated ? 'Updated ' + formatDuration(Date.now() - updated) + ' ago' : 'No update timestamp');
        const error = jobError(job);
        pair.errorText.hidden = error === '';
        setText(pair.errorText, error ? 'Error/result: ' + error : '');
        pair.main.classList.toggle('is-running', queueStatus(job) === 'running');
        pair.detail.classList.toggle('is-running', queueStatus(job) === 'running');
    }

    function removeAllPairs() {
        rowPairs.forEach(function (pair) {
            pair.main.remove();
            pair.detail.remove();
        });
        rowPairs.clear();
    }

    function renderRows() {
        if (!state.jobs.length) {
            removeAllPairs();
            let empty = tableBody.querySelector('.jobs-empty-row');
            if (!empty) {
                empty = document.createElement('tr');
                empty.className = 'jobs-empty-row';
                const cell = document.createElement('td');
                cell.colSpan = 9;
                cell.className = 'jobs-empty muted';
                empty.appendChild(cell);
                tableBody.replaceChildren(empty);
            }
            setText(empty.firstChild, 'No jobs match the current filters.');
            return;
        }

        const empty = tableBody.querySelector('.jobs-empty-row');
        if (empty) empty.remove();
        const wanted = new Set();
        let cursor = tableBody.firstChild;

        state.jobs.forEach(function (job) {
            const id = Number(job.id);
            let pair = rowPairs.get(id);
            if (!pair) {
                pair = createRowPair(job);
                rowPairs.set(id, pair);
            }
            updatePair(pair, job);
            wanted.add(id);

            if (pair.main !== cursor) tableBody.insertBefore(pair.main, cursor);
            cursor = pair.main.nextSibling;
            if (pair.detail !== cursor) tableBody.insertBefore(pair.detail, cursor);
            cursor = pair.detail.nextSibling;
        });

        rowPairs.forEach(function (pair, id) {
            if (wanted.has(id)) return;
            pair.main.remove();
            pair.detail.remove();
            rowPairs.delete(id);
        });
    }

    function renderTabs() {
        const counts = state.meta.counts || {};
        tabs.querySelectorAll('button[data-status]').forEach(function (tab) {
            const value = String(tab.dataset.status || '');
            tab.setAttribute('aria-selected', value === state.status ? 'true' : 'false');
        });
        tabs.querySelectorAll('[data-status-count]').forEach(function (element) {
            const key = String(element.dataset.statusCount || 'all');
            setText(element, counts[key] || 0);
        });
    }

    function renderPagination() {
        const total = Number(state.meta.total || 0);
        const page = Number(state.meta.page || 1);
        const pages = Math.max(1, Number(state.meta.pages || 1));
        const start = total ? ((page - 1) * state.perPage) + 1 : 0;
        const end = total ? Math.min(total, start + state.jobs.length - 1) : 0;
        setText(pageSummary, total ? 'Showing ' + start + '–' + end + ' of ' + total + ' jobs' : 'No matching jobs');
        setText(pageLabel, 'Page ' + page + ' of ' + pages);
        firstPageButton.disabled = page <= 1;
        previousPageButton.disabled = page <= 1;
        nextPageButton.disabled = page >= pages;
        lastPageButton.disabled = page >= pages;
        if (Date.now() >= state.noticeUntil) setText(message, pageSummary.textContent + '. Live rows update in place every two seconds.');
    }

    function renderWorker() {
        const worker = state.worker || {};
        const authority = String(worker.authoritative_status || (worker.active ? 'running' : 'stopped'));
        const counts = worker.queue_counts || {};
        const stale = Boolean(worker.stale_code);
        const processed = parseInt((worker.state || {}).processed || 0, 10) || 0;
        workerState.dataset.authoritativeStatus = authority;

        if (authority === 'running') {
            setText(workerState, stale
                ? 'Worker running older code · restart required'
                : 'Worker running · ' + processed + ' processed · ' + String(counts.running || 0) + ' active · ' + String(counts.queued || 0) + ' queued');
        } else if (authority === 'orphaned') {
            setText(workerState, 'Worker stopped · ' + String(counts.running || 0) + ' orphaned running job(s)');
        } else if (authority === 'stopped_with_queue') {
            setText(workerState, 'Worker stopped · ' + String(counts.queued || 0) + ' queued job(s) waiting');
        } else {
            setText(workerState, 'Worker stopped · queue empty');
        }

        startButton.disabled = authority === 'running' && !stale;
        setText(startButton, authority === 'orphaned' ? 'Recover and resume' : (stale ? 'Restart worker' : 'Start / resume queue'));
        stopWorkerButton.disabled = authority !== 'running';
    }

    function currentPageFullySelected() {
        return state.jobs.length > 0 && state.jobs.every(function (job) { return state.selected.has(Number(job.id)); });
    }

    function selectionJobs() {
        return Array.from(state.selected.values());
    }

    function allowedBulkActions() {
        const actions = [];
        if (state.allMatching) {
            const counts = state.meta.counts || {};
            if (Number(counts.queued || 0) > 0) actions.push({value: 'cancel', label: 'Cancel matching queued jobs'});
            if (Number(counts.failed || 0) + Number(counts.dead_letter || 0) + Number(counts.cancelled || 0) > 0) actions.push({value: 'restart', label: 'Restart matching retryable jobs'});
            if (Number(counts.completed || 0) + Number(counts.failed || 0) + Number(counts.dead_letter || 0) + Number(counts.cancelled || 0) > 0) actions.push({value: 'delete', label: 'Delete matching terminal jobs'});
            return actions;
        }
        const jobs = selectionJobs();
        if (jobs.some(function (job) { return queueStatus(job) === 'queued'; })) actions.push({value: 'cancel', label: 'Cancel selected queued jobs'});
        if (jobs.some(retryable)) actions.push({value: 'restart', label: 'Restart selected retryable jobs'});
        if (jobs.some(deletable)) actions.push({value: 'delete', label: 'Delete selected terminal jobs'});
        return actions;
    }

    function updateBulkActionOptions() {
        const current = bulkAction.value;
        const actions = allowedBulkActions();
        bulkAction.textContent = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = actions.length ? 'Choose action' : 'No valid bulk action';
        bulkAction.appendChild(placeholder);
        actions.forEach(function (action) {
            const option = document.createElement('option');
            option.value = action.value;
            option.textContent = action.label;
            bulkAction.appendChild(option);
        });
        if (actions.some(function (action) { return action.value === current; })) bulkAction.value = current;
        bulkAction.disabled = actions.length === 0;
        applyActionButton.disabled = !bulkAction.value;
    }

    function updateSelectionUi() {
        const selectedCount = state.allMatching ? Number(state.meta.total || 0) : state.selected.size;
        setText(selectionSummary, state.allMatching
            ? selectedCount + ' matching jobs selected'
            : (selectedCount ? selectedCount + ' job' + (selectedCount === 1 ? '' : 's') + ' selected' : 'Nothing selected'));
        selectPage.disabled = state.allMatching || state.jobs.length === 0;
        selectPage.checked = state.allMatching || currentPageFullySelected();
        selectPage.indeterminate = !state.allMatching && state.jobs.some(function (job) { return state.selected.has(Number(job.id)); }) && !currentPageFullySelected();
        selectMatchingButton.disabled = state.allMatching || Number(state.meta.total || 0) === 0;
        setText(selectMatchingButton, 'Select all ' + String(state.meta.total || 0) + ' matching');
        clearSelectionButton.disabled = !state.allMatching && state.selected.size === 0;
        updateBulkActionOptions();
        renderRows();
    }

    function clearSelection() {
        state.selected.clear();
        state.allMatching = false;
        updateSelectionUi();
    }

    async function readJobs() {
        const params = new URLSearchParams({queue: queue, page: String(state.page), per_page: String(state.perPage)});
        if (state.status) params.set('status', state.status);
        if (state.search) params.set('search', state.search);
        const body = await jsonRequest(statusUrl + '?' + params.toString(), {cache: 'no-store', credentials: 'same-origin'});
        state.jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        state.meta = body && body.meta ? body.meta : state.meta;
        state.page = Number(state.meta.page || 1);
    }

    async function readWorker() {
        const body = await jsonRequest(workerStatusUrl + '?' + new URLSearchParams({queue: queue}).toString(), {cache: 'no-store', credentials: 'same-origin'});
        state.worker = body && body.data ? body.data.worker || {} : {};
    }

    async function refresh() {
        if (state.loading) return;
        state.loading = true;
        refreshButton.disabled = true;
        try {
            await Promise.all([readJobs(), readWorker()]);
            renderTabs();
            renderRows();
            renderPagination();
            renderWorker();
            updateSelectionUi();
            updateUrl();
        } catch (error) {
            setNotice(error.message || 'Could not load background jobs.', 10000);
        } finally {
            state.loading = false;
            refreshButton.disabled = false;
        }
    }

    async function launchQueue() {
        startButton.disabled = true;
        try {
            if (String((state.worker || {}).authoritative_status || '') === 'orphaned') await postJson(actionUrl, {action: 'recover', queue: queue});
            const body = await postJson(runUrl, {queue: queue, mode: 'drain'});
            const data = body && body.data ? body.data : {};
            setNotice(data.started === false ? 'The queue worker is already running.' : 'The queue worker was started.', 5000);
        } catch (error) {
            setNotice(error.message || 'Could not start the queue worker.', 10000);
        }
        await refresh();
    }

    async function stopWorker() {
        if (!window.confirm('Stop the queue worker and cancel its currently running job? Queued jobs will remain queued.')) return;
        stopWorkerButton.disabled = true;
        try {
            await postJson(workerActionUrl, {action: 'stop', queue: queue, cancel_running: true});
            setNotice('The worker and its current job were stopped. Queued jobs were left unchanged.', 6000);
        } catch (error) {
            setNotice(error.message || 'Could not stop the queue worker.', 10000);
        }
        await refresh();
    }

    async function launchAfterStop() {
        for (let attempt = 0; attempt < 40; attempt++) {
            const body = await postJson(runUrl, {queue: queue, mode: 'drain'});
            const data = body && body.data ? body.data : {};
            if (data.started !== false) return true;
            await new Promise(function (resolve) { window.setTimeout(resolve, 500); });
        }
        return false;
    }

    async function stopJob(job, actionButton) {
        actionButton.disabled = true;
        try {
            await postJson(actionUrl, {action: 'cancel', queue: queue, job_id: Number(job.id), reason: 'Stopped manually from Background Jobs so the queue could continue.'});
            const continued = await launchAfterStop();
            setNotice(continued
                ? 'Job #' + job.id + ' was stopped and the next queued job was started.'
                : 'Job #' + job.id + ' was stopped. Use Start / resume if the next job does not begin.', 8000);
        } catch (error) {
            setNotice(error.message || 'Could not stop the selected job.', 10000);
        } finally {
            actionButton.disabled = false;
            await refresh();
        }
    }

    async function runBulk(action, scope, jobs, actionButton) {
        const selectedIds = jobs.map(function (job) { return Number(job.id); });
        const scopeLabel = scope === 'matching' ? 'all matching eligible jobs' : selectedIds.length + ' selected job' + (selectedIds.length === 1 ? '' : 's');
        const verb = action === 'restart' ? 'Restart' : action === 'cancel' ? 'Cancel' : 'Delete';
        const warning = action === 'delete' ? ' Retained staged upload files will also be removed.' : '';
        if (!window.confirm(verb + ' ' + scopeLabel + '?' + warning)) return;
        actionButton.disabled = true;
        try {
            const body = await postJson(bulkUrl, {action: action, scope: scope, queue: queue, status: state.status, search: state.search, job_ids: selectedIds});
            const data = body && body.data ? body.data : {};
            let text = verb + ' affected ' + String(data.affected || 0) + ' job(s).';
            const blocked = Number(data.retry_blocked || 0);
            const skipped = Number(data.skipped || 0);
            if (blocked > 0) text += ' ' + blocked + ' deterministic job(s) were not restarted.';
            if (skipped > blocked) text += ' ' + String(skipped - blocked) + ' other ineligible job(s) were skipped.';
            if (data.deleted_staged_files) text += ' Removed ' + String(data.deleted_staged_files) + ' retained upload(s).';
            if (data.limited) text += ' The 10,000-job safety limit was reached; apply again for the remainder.';
            if (data.worker_error) text += ' Jobs were queued, but the worker could not start: ' + String(data.worker_error);
            setNotice(text, 10000);
            state.selected.clear();
            state.allMatching = false;
        } catch (error) {
            setNotice(error.message || 'The bulk action failed.', 10000);
        } finally {
            actionButton.disabled = false;
            await refresh();
        }
    }

    async function rerunPak(job, actionButton) {
        if (!window.confirm('Queue a new full PAK import using the retained source file?')) return;
        actionButton.disabled = true;
        try {
            const body = await postJson(pakRerunUrl, {job_id: Number(job.id), queue: queue});
            const data = body && body.data ? body.data : {};
            setNotice('Queued PAK re-run as job #' + String(data.job_id || '') + '.', 6000);
        } catch (error) {
            setNotice(error.message || 'The PAK import could not be queued again.', 10000);
        } finally {
            actionButton.disabled = false;
            await refresh();
        }
    }

    function runRowAction(action, job, actionButton) {
        if (action === 'stop') return void stopJob(job, actionButton);
        if (action === 'rerun_pak') return void rerunPak(job, actionButton);
        runBulk(action, 'selected', [job], actionButton);
    }

    tabs.addEventListener('click', function (event) {
        const tab = event.target && event.target.closest ? event.target.closest('button[data-status]') : null;
        if (!tab) return;
        state.status = String(tab.dataset.status || '');
        state.page = 1;
        clearSelection();
        refresh();
    });

    searchInput.addEventListener('input', function () {
        window.clearTimeout(state.searchTimer);
        state.searchTimer = window.setTimeout(function () {
            state.search = searchInput.value.trim();
            state.page = 1;
            clearSelection();
            refresh();
        }, 350);
    });

    pageSizeSelect.addEventListener('change', function () {
        state.perPage = parseInt(pageSizeSelect.value || '100', 10) || 100;
        state.page = 1;
        clearSelection();
        refresh();
    });

    selectPage.addEventListener('change', function () {
        if (state.allMatching) return;
        state.jobs.forEach(function (job) {
            const id = Number(job.id);
            if (selectPage.checked) state.selected.set(id, job); else state.selected.delete(id);
        });
        updateSelectionUi();
    });

    selectMatchingButton.addEventListener('click', function () {
        if (!Number(state.meta.total || 0)) return;
        state.selected.clear();
        state.allMatching = true;
        updateSelectionUi();
    });

    clearSelectionButton.addEventListener('click', clearSelection);
    bulkAction.addEventListener('change', function () { applyActionButton.disabled = !bulkAction.value; });
    applyActionButton.addEventListener('click', function () {
        if (!bulkAction.value) return;
        runBulk(bulkAction.value, state.allMatching ? 'matching' : 'selected', selectionJobs(), applyActionButton);
    });

    firstPageButton.addEventListener('click', function () { state.page = 1; refresh(); });
    previousPageButton.addEventListener('click', function () { state.page = Math.max(1, state.page - 1); refresh(); });
    nextPageButton.addEventListener('click', function () { state.page = Math.min(Number(state.meta.pages || 1), state.page + 1); refresh(); });
    lastPageButton.addEventListener('click', function () { state.page = Math.max(1, Number(state.meta.pages || 1)); refresh(); });
    startButton.addEventListener('click', launchQueue);
    stopWorkerButton.addEventListener('click', stopWorker);
    refreshButton.addEventListener('click', refresh);

    recoverButton.addEventListener('click', async function () {
        recoverButton.disabled = true;
        try {
            const body = await postJson(actionUrl, {action: 'recover', queue: queue});
            const data = body && body.data ? body.data : {};
            setNotice('Recovery complete: ' + String(data.requeued || 0) + ' requeued, ' + String(data.cancelled || 0) + ' cancelled, ' + String(data.dead_lettered || 0) + ' marked as issues.', 8000);
        } catch (error) {
            setNotice(error.message || 'Recovery failed.', 10000);
        } finally {
            recoverButton.disabled = false;
            await refresh();
        }
    });

    cleanupButton.addEventListener('click', async function () {
        const days = Math.max(1, parseInt(cleanupDays.value || '30', 10) || 30);
        if (!window.confirm('Delete terminal jobs older than ' + days + ' day(s) and their retained staged uploads?')) return;
        cleanupButton.disabled = true;
        try {
            const body = await postJson(actionUrl, {action: 'cleanup', queue: queue, retention_days: days});
            const data = body && body.data ? body.data : {};
            setNotice('Removed ' + String(data.deleted_jobs || 0) + ' old job(s) and ' + String(data.deleted_staged_files || 0) + ' staged upload(s).', 8000);
        } catch (error) {
            setNotice(error.message || 'Cleanup failed.', 10000);
        } finally {
            cleanupButton.disabled = false;
            await refresh();
        }
    });

    refresh();
    window.setInterval(function () {
        rowPairs.forEach(function (pair) {
            setText(pair.runtimeCell, runtimeText(pair.job));
            const updated = parseUtc(pair.job.progress_updated_at || pair.job.last_heartbeat_at || pair.job.updated_at);
            setText(pair.updateText, updated ? 'Updated ' + formatDuration(Date.now() - updated) + ' ago' : 'No update timestamp');
        });
    }, 1000);
    window.setInterval(function () {
        if (!document.hidden) refresh();
    }, 2000);
}());
