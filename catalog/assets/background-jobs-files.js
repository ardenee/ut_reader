(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    if (!app || !window.fetch) return;

    const queue = String(app.dataset.queue || 'catalog');
    const treeUrl = String(app.dataset.treeUrl || 'api/v1/job-file-tree.php');
    const runUrl = String(app.dataset.runUrl || 'api/v1/job-run.php');
    const workerStatusUrl = String(app.dataset.workerStatusUrl || 'api/v1/job-worker-status.php');
    const workerActionUrl = String(app.dataset.workerActionUrl || 'api/v1/job-worker-action.php');
    const actionUrl = String(app.dataset.actionUrl || 'api/v1/job-action.php');
    const bulkUrl = String(app.dataset.bulkUrl || 'api/v1/job-bulk.php');
    const csrf = String(app.dataset.csrf || '');

    const body = document.getElementById('jobs-file-body');
    const tabs = document.getElementById('jobs-file-tabs');
    const searchInput = document.getElementById('jobs-file-search');
    const perPageSelect = document.getElementById('jobs-file-per-page');
    const notice = document.getElementById('jobs-file-notice');
    const summary = document.getElementById('jobs-file-summary');
    const pageLabel = document.getElementById('jobs-file-page');
    const firstButton = document.getElementById('jobs-file-first');
    const previousButton = document.getElementById('jobs-file-previous');
    const nextButton = document.getElementById('jobs-file-next');
    const lastButton = document.getElementById('jobs-file-last');
    const refreshButton = document.getElementById('jobs-refresh');
    const startButton = document.getElementById('jobs-start');
    const stopWorkerButton = document.getElementById('jobs-stop-worker');
    const workerState = document.getElementById('jobs-worker-state');
    const workerCount = document.getElementById('jobs-worker-count');
    const applyWorkers = document.getElementById('jobs-apply-workers');
    const recoverButton = document.getElementById('jobs-recover');
    const cleanupButton = document.getElementById('jobs-cleanup');
    const cleanupDays = document.getElementById('jobs-cleanup-days');

    if (!body || !tabs || !searchInput || !perPageSelect || !notice) return;

    const validStates = ['all', 'working', 'issue', 'completed', 'stopped'];
    const query = new URLSearchParams(window.location.search);
    const requestedState = String(query.get('state') || 'all').toLowerCase();
    const requestedPerPage = parseInt(query.get('per_page') || '100', 10) || 100;

    const state = {
        filter: validStates.includes(requestedState) ? requestedState : 'all',
        search: String(query.get('search') || ''),
        page: Math.max(1, parseInt(query.get('page') || '1', 10) || 1),
        perPage: [25, 50, 100, 200].includes(requestedPerPage) ? requestedPerPage : 100,
        roots: [],
        meta: {page: 1, pages: 1, total: 0, counts: {}},
        expanded: new Set(),
        children: new Map(),
        loading: false,
        searchTimer: 0,
        noticeUntil: 0,
        worker: null
    };

    searchInput.value = state.search;
    perPageSelect.value = String(state.perPage);

    function setText(element, text) {
        if (element && element.textContent !== String(text)) element.textContent = String(text);
    }

    function escapeSelector(value) {
        return String(value).replace(/[^a-zA-Z0-9_-]/g, '');
    }

    function setNotice(text, milliseconds) {
        setText(notice, text);
        state.noticeUntil = Date.now() + (milliseconds || 5000);
    }

    async function jsonRequest(url, options) {
        const response = await fetch(url, options || {});
        let payload = {};
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned invalid JSON (HTTP ' + response.status + ').');
        }
        if (!response.ok) {
            const text = payload && payload.error && payload.error.message
                ? String(payload.error.message)
                : 'Request failed with HTTP ' + response.status + '.';
            throw new Error(text);
        }
        return payload;
    }

    function postJson(url, payload) {
        return jsonRequest(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(payload)
        });
    }

    function updateUrl() {
        const params = new URLSearchParams(window.location.search);
        params.set('queue', queue);
        if (state.filter !== 'all') params.set('state', state.filter); else params.delete('state');
        if (state.search) params.set('search', state.search); else params.delete('search');
        if (state.page > 1) params.set('page', String(state.page)); else params.delete('page');
        if (state.perPage !== 100) params.set('per_page', String(state.perPage)); else params.delete('per_page');
        const text = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (text ? '?' + text : ''));
    }

    function bytes(value) {
        let number = Math.max(0, Number(value || 0));
        if (!number) return '—';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let index = 0;
        while (number >= 1024 && index < units.length - 1) {
            number /= 1024;
            index++;
        }
        return (index === 0 ? Math.round(number) : number.toFixed(number >= 100 ? 0 : number >= 10 ? 1 : 2)) + ' ' + units[index];
    }

    function create(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text != null) element.textContent = String(text);
        return element;
    }

    function groupMarker(rootGroup) {
        return Math.abs(Number(rootGroup || 0)) % 2 === 0
            ? 'rgba(148,163,184,0.90)'
            : 'rgba(96,165,250,0.95)';
    }

    function rowBackground(depth, rootGroup) {
        const alternate = Math.abs(Number(rootGroup || 0)) % 2;
        if (depth < 1) {
            return alternate === 0
                ? 'rgba(255,255,255,0.030)'
                : 'rgba(59,130,246,0.085)';
        }

        const base = alternate === 0 ? 0.115 : 0.155;
        const alpha = Math.min(0.235, base + (Math.min(depth - 1, 4) * 0.022));
        return alternate === 0
            ? 'rgba(148,163,184,' + alpha.toFixed(3) + ')'
            : 'rgba(59,130,246,' + alpha.toFixed(3) + ')';
    }

    function applyRowBackground(row, depth, rootGroup) {
        const background = rowBackground(depth, rootGroup);
        const marker = groupMarker(rootGroup);
        row.dataset.rootGroup = String(Math.abs(Number(rootGroup || 0)) % 2);
        row.dataset.treeKind = depth > 0 ? 'child' : 'parent';
        row.querySelectorAll('td').forEach(function (cell) {
            cell.style.background = background;
            if (depth === 0) cell.style.borderTop = '2px solid ' + marker;
        });
        const fileCell = row.querySelector('.jobs-file-name-cell');
        if (fileCell && depth > 0) {
            fileCell.style.borderLeft = '5px solid ' + marker;
        }
    }

    function statusClass(file) {
        return 'jobs-file-status jobs-file-status-' + String(file.operator_state || 'issue').replace(/[^a-z0-9_-]+/g, '-');
    }

    function controlFor(file) {
        const queueStatus = String(file.status || '').toLowerCase();
        const operatorState = String(file.operator_state || 'issue');
        if (operatorState === 'working') {
            return {label: queueStatus === 'running' ? 'Stop' : 'Cancel', action: 'stop'};
        }
        if (operatorState === 'issue') {
            if (file.retry_blocked === true) return {label: 'Replace / fix', action: ''};
            return {label: 'Retry', action: 'retry'};
        }
        if (operatorState === 'stopped') {
            return {label: 'Retry', action: 'retry'};
        }
        return null;
    }

    function renderFileRow(file, depth, rootGroup) {
        const row = document.createElement('tr');
        row.className = 'jobs-file-row jobs-file-row-' + String(file.operator_state || 'issue');
        row.dataset.jobId = String(file.id || '');
        row.dataset.depth = String(depth);

        const idCell = create('td', 'jobs-file-id mono', '#' + String(file.id || ''));
        row.appendChild(idCell);

        const fileCell = create('td', 'jobs-file-name-cell');
        const tree = create('div', 'jobs-file-tree');
        tree.style.setProperty('--tree-depth', String(depth));
        if (file.has_children) {
            const toggle = create('button', 'jobs-file-toggle', state.expanded.has(Number(file.id)) ? '▼' : '▶');
            toggle.type = 'button';
            toggle.title = state.expanded.has(Number(file.id)) ? 'Collapse child files/jobs' : 'Show child files/jobs';
            toggle.addEventListener('click', function () { toggleChildren(file); });
            tree.appendChild(toggle);
        } else {
            tree.appendChild(create('span', 'jobs-file-toggle-spacer', ''));
        }
        const identity = create('div', 'jobs-file-identity');
        identity.appendChild(create('strong', '', file.file_name || ('Job #' + file.id)));
        if (file.file_path && file.file_path !== file.file_name) {
            identity.appendChild(create('span', 'mono muted jobs-file-path', file.file_path));
        }
        if (depth > 0 && Number(file.tree_parent_job_id || 0) > 0) {
            identity.appendChild(create('span', 'muted jobs-file-child-count', 'Child of job #' + String(file.tree_parent_job_id)));
        }
        if (file.has_children) {
            identity.appendChild(create('span', 'muted jobs-file-child-count', String(file.child_count || 0) + ' child item(s)'));
        }
        tree.appendChild(identity);
        fileCell.appendChild(tree);
        row.appendChild(fileCell);

        row.appendChild(create('td', 'jobs-file-size mono', bytes(file.size_bytes)));

        const actionCell = create('td', 'jobs-file-action');
        actionCell.appendChild(create('strong', '', file.action_label || 'Processing'));
        if (file.operator_state === 'issue' && file.issue_reason) {
            actionCell.appendChild(create('span', 'jobs-file-issue-text', file.issue_reason));
        } else if (file.activity_detail) {
            actionCell.appendChild(create('span', 'muted jobs-file-activity', file.activity_detail));
        }
        actionCell.appendChild(create('span', 'muted mono jobs-file-type', file.job_type || ''));
        row.appendChild(actionCell);

        const progressCell = create('td', 'jobs-file-progress');
        const progress = document.createElement('progress');
        progress.max = 100;
        progress.value = Math.max(0, Math.min(100, Number(file.progress_percent || 0)));
        progressCell.appendChild(progress);
        progressCell.appendChild(create('span', 'mono', file.progress_text || (progress.value + '%')));
        row.appendChild(progressCell);

        const statusCell = create('td', 'jobs-file-status-cell');
        statusCell.appendChild(create('span', statusClass(file), file.operator_status_label || 'Issue'));
        if (file.result_label) statusCell.appendChild(create('span', 'muted jobs-file-result-label', file.result_label));
        row.appendChild(statusCell);

        const controlCell = create('td', 'jobs-file-control');
        const control = controlFor(file);
        if (control) {
            if (control.action) {
                const button = create('button', 'jobs-file-control-button', control.label);
                button.type = 'button';
                button.addEventListener('click', function () { runFileAction(file, control.action, button); });
                controlCell.appendChild(button);
            } else {
                controlCell.appendChild(create('span', 'jobs-file-replace', control.label));
            }
        }
        row.appendChild(controlCell);
        applyRowBackground(row, depth, rootGroup);
        return row;
    }

    function renderLoadMore(parentId, depth, childState, rootGroup) {
        const row = document.createElement('tr');
        row.className = 'jobs-file-more-row';
        const cell = document.createElement('td');
        cell.colSpan = 7;
        cell.style.paddingLeft = 'calc(18px + (' + depth + ' * 22px))';
        const button = create('button', '', 'Load more children (' + childState.rows.length + ' of ' + childState.total + ')');
        button.type = 'button';
        button.addEventListener('click', function () {
            loadChildren(parentId, childState.page + 1, true);
        });
        cell.appendChild(button);
        row.appendChild(cell);
        applyRowBackground(row, Math.max(1, depth), rootGroup);
        return row;
    }

    function appendBranch(fragment, file, depth, rootGroup) {
        fragment.appendChild(renderFileRow(file, depth, rootGroup));
        const id = Number(file.id || 0);
        if (!id || !state.expanded.has(id)) return;

        const childState = state.children.get(id);
        if (!childState) {
            const loading = document.createElement('tr');
            loading.className = 'jobs-file-loading-row';
            const cell = create('td', 'muted', 'Loading child files/jobs…');
            cell.colSpan = 7;
            cell.style.paddingLeft = 'calc(18px + (' + (depth + 1) + ' * 22px))';
            loading.appendChild(cell);
            applyRowBackground(loading, depth + 1, rootGroup);
            fragment.appendChild(loading);
            return;
        }

        childState.rows.forEach(function (child) { appendBranch(fragment, child, depth + 1, rootGroup); });
        if (childState.page < childState.pages) {
            fragment.appendChild(renderLoadMore(id, depth + 1, childState, rootGroup));
        }
    }

    function renderRows() {
        const fragment = document.createDocumentFragment();
        if (!state.roots.length) {
            const row = document.createElement('tr');
            const cell = create('td', 'jobs-file-empty muted', state.filter === 'issue'
                ? 'No files currently need attention.'
                : 'No files match the current view.');
            cell.colSpan = 7;
            row.appendChild(cell);
            fragment.appendChild(row);
        } else {
            state.roots.forEach(function (file, index) { appendBranch(fragment, file, 0, index % 2); });
        }
        body.replaceChildren(fragment);
    }

    function renderTabs() {
        const counts = state.meta.counts || {};
        tabs.querySelectorAll('button[data-state]').forEach(function (button) {
            const value = String(button.dataset.state || 'all');
            button.setAttribute('aria-selected', value === state.filter ? 'true' : 'false');
            const count = button.querySelector('[data-count]');
            if (count) setText(count, Number(counts[value] || 0));
        });
    }

    function renderPagination() {
        const page = Math.max(1, Number(state.meta.page || 1));
        const pages = Math.max(1, Number(state.meta.pages || 1));
        const total = Math.max(0, Number(state.meta.total || 0));
        setText(summary, total ? total.toLocaleString() + ' file/job root(s)' : 'No matching files');
        setText(pageLabel, 'Page ' + page + ' of ' + pages);
        if (firstButton) firstButton.disabled = page <= 1;
        if (previousButton) previousButton.disabled = page <= 1;
        if (nextButton) nextButton.disabled = page >= pages;
        if (lastButton) lastButton.disabled = page >= pages;
        if (Date.now() >= state.noticeUntil) {
            const issueCount = Number((state.meta.counts || {}).issue || 0);
            setText(notice, issueCount
                ? issueCount.toLocaleString() + ' source/file item(s) currently need attention.'
                : 'No source/file issues are currently recorded in this queue view.');
        }
    }

    async function readRoots() {
        const params = new URLSearchParams({
            queue: queue,
            state: state.filter,
            page: String(state.page),
            per_page: String(state.perPage)
        });
        if (state.search) params.set('search', state.search);
        const payload = await jsonRequest(treeUrl + '?' + params.toString(), {cache: 'no-store', credentials: 'same-origin'});
        state.roots = payload && payload.data && Array.isArray(payload.data.files) ? payload.data.files : [];
        state.meta = payload && payload.meta ? payload.meta : state.meta;
        state.page = Math.max(1, Number(state.meta.page || 1));
    }

    async function loadChildren(parentId, page, append, quiet) {
        parentId = Number(parentId || 0);
        if (!parentId) return;
        const existing = state.children.get(parentId);
        const params = new URLSearchParams({
            queue: queue,
            parent_job_id: String(parentId),
            page: String(page || (existing ? existing.page : 1)),
            per_page: '200'
        });
        try {
            const payload = await jsonRequest(treeUrl + '?' + params.toString(), {cache: 'no-store', credentials: 'same-origin'});
            const files = payload && payload.data && Array.isArray(payload.data.files) ? payload.data.files : [];
            const meta = payload && payload.meta ? payload.meta : {};
            let rows = files;
            if (append && existing) {
                const known = new Set(existing.rows.map(function (row) { return Number(row.id); }));
                rows = existing.rows.concat(files.filter(function (row) { return !known.has(Number(row.id)); }));
            }
            state.children.set(parentId, {
                rows: rows,
                page: Math.max(1, Number(meta.page || 1)),
                pages: Math.max(1, Number(meta.pages || 1)),
                total: Math.max(0, Number(meta.total || rows.length))
            });
            renderRows();
        } catch (error) {
            if (!quiet) setNotice(error.message || 'Could not load child files/jobs.', 8000);
        }
    }

    async function toggleChildren(file) {
        const id = Number(file.id || 0);
        if (!id) return;
        if (state.expanded.has(id)) {
            state.expanded.delete(id);
            renderRows();
            return;
        }
        state.expanded.add(id);
        renderRows();
        if (!state.children.has(id)) await loadChildren(id, 1, false, false);
    }

    async function readWorker() {
        const payload = await jsonRequest(workerStatusUrl + '?' + new URLSearchParams({queue: queue}).toString(), {cache: 'no-store', credentials: 'same-origin'});
        state.worker = payload && payload.data ? payload.data.worker || null : null;
        renderWorker();
    }

    function renderWorker() {
        const worker = state.worker || {};
        const authority = String(worker.authoritative_status || (worker.active ? 'running' : 'stopped'));
        const counts = worker.queue_counts || {};
        const activeWorkers = Math.max(0, Number(worker.active_count || 0));
        const desiredWorkers = Math.max(1, Number(worker.desired_count || (workerCount ? workerCount.value : 1)));
        const runningJobs = Math.max(0, Number(counts.running || 0));
        const queuedJobs = Math.max(0, Number(counts.queued || 0));
        const text = authority === 'running' || authority === 'degraded'
            ? 'Pool ' + activeWorkers + '/' + desiredWorkers + ' · ' + runningJobs + ' running · ' + queuedJobs + ' queued'
            : authority === 'orphaned'
                ? 'Worker stopped · ' + runningJobs + ' orphaned job(s)'
                : 'Worker stopped · ' + queuedJobs + ' queued';
        setText(workerState, text);
        if (workerCount && document.activeElement !== workerCount) workerCount.value = String(Math.max(1, Math.min(8, desiredWorkers)));
        if (startButton) startButton.disabled = authority === 'running' && !worker.stale_code && activeWorkers >= desiredWorkers;
        if (stopWorkerButton) stopWorkerButton.disabled = !(authority === 'running' || authority === 'degraded');
    }

    async function refresh() {
        if (state.loading) return;
        state.loading = true;
        if (refreshButton) refreshButton.disabled = true;
        try {
            await Promise.all([readRoots(), readWorker()]);
            renderTabs();
            renderRows();
            renderPagination();
            updateUrl();

            const expanded = Array.from(state.expanded).slice(0, 20);
            await Promise.all(expanded.map(function (id) {
                const current = state.children.get(id);
                return loadChildren(id, current ? current.page : 1, false, true);
            }));
        } catch (error) {
            setNotice(error.message || 'Could not refresh file jobs.', 10000);
        } finally {
            state.loading = false;
            if (refreshButton) refreshButton.disabled = false;
        }
    }

    async function runFileAction(file, action, button) {
        button.disabled = true;
        try {
            if (action === 'stop') {
                await postJson(actionUrl, {
                    action: 'cancel',
                    queue: queue,
                    job_id: Number(file.id),
                    reason: 'Stopped manually from the file-centric Background Jobs view.'
                });
                setNotice('Stopped job #' + file.id + '.', 5000);
            } else if (action === 'retry') {
                const payload = await postJson(bulkUrl, {
                    action: 'restart',
                    scope: 'selected',
                    queue: queue,
                    status: '',
                    search: '',
                    job_ids: [Number(file.id)]
                });
                const data = payload && payload.data ? payload.data : {};
                if (Number(data.retry_blocked || 0) > 0) {
                    setNotice('This source is structurally invalid and cannot succeed from the same retained bytes. Replace or fix the source file.', 9000);
                } else {
                    setNotice('Restarted job #' + file.id + '.', 5000);
                }
            }
        } catch (error) {
            setNotice(error.message || 'The file action failed.', 9000);
        } finally {
            button.disabled = false;
            await refresh();
        }
    }

    async function startWorkers() {
        if (startButton) startButton.disabled = true;
        try {
            const authority = state.worker ? String(state.worker.authoritative_status || '') : '';
            if (authority === 'orphaned') await postJson(actionUrl, {action: 'recover', queue: queue});
            await postJson(runUrl, {
                queue: queue,
                mode: 'drain',
                workers: Math.max(1, Math.min(8, parseInt(workerCount ? workerCount.value : '4', 10) || 4))
            });
            setNotice('Worker pool started/resumed.', 4000);
        } catch (error) {
            setNotice(error.message || 'Could not start the worker pool.', 9000);
        }
        await refresh();
    }

    async function resizeWorkers() {
        if (!applyWorkers) return;
        applyWorkers.disabled = true;
        try {
            await postJson(runUrl, {
                queue: queue,
                mode: 'drain',
                workers: Math.max(1, Math.min(8, parseInt(workerCount ? workerCount.value : '4', 10) || 4))
            });
            setNotice('Worker pool size applied.', 4000);
        } catch (error) {
            setNotice(error.message || 'Could not resize the worker pool.', 9000);
        } finally {
            applyWorkers.disabled = false;
            await refresh();
        }
    }

    async function stopWorkers() {
        if (!window.confirm('Stop the worker pool and cancel currently running jobs? Queued files will remain queued.')) return;
        stopWorkerButton.disabled = true;
        try {
            await postJson(workerActionUrl, {action: 'stop', queue: queue, cancel_running: true});
            setNotice('Worker pool stopped. Queued files were left in place.', 6000);
        } catch (error) {
            setNotice(error.message || 'Could not stop the worker pool.', 9000);
        }
        await refresh();
    }

    tabs.addEventListener('click', function (event) {
        const button = event.target.closest('button[data-state]');
        if (!button) return;
        state.filter = String(button.dataset.state || 'all');
        state.page = 1;
        state.expanded.clear();
        state.children.clear();
        refresh();
    });

    searchInput.addEventListener('input', function () {
        window.clearTimeout(state.searchTimer);
        state.searchTimer = window.setTimeout(function () {
            state.search = searchInput.value.trim();
            state.page = 1;
            state.expanded.clear();
            state.children.clear();
            refresh();
        }, 350);
    });

    perPageSelect.addEventListener('change', function () {
        state.perPage = parseInt(perPageSelect.value || '100', 10) || 100;
        state.page = 1;
        state.expanded.clear();
        state.children.clear();
        refresh();
    });

    if (firstButton) firstButton.addEventListener('click', function () { state.page = 1; refresh(); });
    if (previousButton) previousButton.addEventListener('click', function () { state.page = Math.max(1, state.page - 1); refresh(); });
    if (nextButton) nextButton.addEventListener('click', function () { state.page = Math.min(Number(state.meta.pages || 1), state.page + 1); refresh(); });
    if (lastButton) lastButton.addEventListener('click', function () { state.page = Math.max(1, Number(state.meta.pages || 1)); refresh(); });
    if (refreshButton) refreshButton.addEventListener('click', refresh);
    if (startButton) startButton.addEventListener('click', startWorkers);
    if (applyWorkers) applyWorkers.addEventListener('click', resizeWorkers);
    if (stopWorkerButton) stopWorkerButton.addEventListener('click', stopWorkers);

    if (recoverButton) recoverButton.addEventListener('click', async function () {
        recoverButton.disabled = true;
        try {
            const payload = await postJson(actionUrl, {action: 'recover', queue: queue});
            const data = payload && payload.data ? payload.data : {};
            setNotice('Recovery: ' + String(data.requeued || 0) + ' requeued, ' + String(data.cancelled || 0) + ' cancelled, ' + String(data.dead_lettered || 0) + ' issues.', 7000);
        } catch (error) {
            setNotice(error.message || 'Recovery failed.', 9000);
        } finally {
            recoverButton.disabled = false;
            await refresh();
        }
    });

    if (cleanupButton) cleanupButton.addEventListener('click', async function () {
        const days = Math.max(1, parseInt(cleanupDays ? cleanupDays.value : '30', 10) || 30);
        if (!window.confirm('Delete completed/stopped historical jobs older than ' + days + ' day(s)?')) return;
        cleanupButton.disabled = true;
        try {
            const payload = await postJson(actionUrl, {action: 'cleanup', queue: queue, retention_days: days});
            const data = payload && payload.data ? payload.data : {};
            setNotice('Removed ' + String(data.deleted_jobs || 0) + ' historical job(s).', 7000);
        } catch (error) {
            setNotice(error.message || 'Cleanup failed.', 9000);
        } finally {
            cleanupButton.disabled = false;
            await refresh();
        }
    });

    refresh();
    window.setInterval(function () {
        if (!document.hidden) refresh();
    }, 3000);
}());