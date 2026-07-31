(function () {
    'use strict';

    if (!window.fetch) return;
    const originalFetch = window.fetch.bind(window);
    const pageState = new Map();
    const app = document.getElementById('background-jobs-app');
    const queue = app ? String(app.dataset.queue || 'catalog') : 'catalog';
    const csrf = app ? String(app.dataset.csrf || '') : '';
    const workerStorageKey = 'unrealdb.backgroundJobs.workerCount.' + queue;
    let workerCountSelect = null;
    let applyWorkersButton = null;
    let poolState = null;

    function requestUrl(input) {
        try {
            return new URL(typeof input === 'string' ? input : input.url, window.location.href);
        } catch (error) {
            return null;
        }
    }

    function clampWorkers(value) {
        return Math.max(1, Math.min(8, parseInt(value || '4', 10) || 4));
    }

    function selectedWorkers() {
        return clampWorkers(workerCountSelect ? workerCountSelect.value : localStorage.getItem(workerStorageKey));
    }

    function setPoolState(worker) {
        if (!poolState || !worker || typeof worker !== 'object') return;
        const active = Math.max(0, Number(worker.active_count || 0));
        const desired = clampWorkers(worker.desired_count || selectedWorkers());
        const maximum = Math.max(1, Number(worker.max_workers || 8));
        const stale = Boolean(worker.stale_code);
        poolState.textContent = 'Pool ' + active + '/' + desired + ' active · max ' + maximum + (stale ? ' · old code' : '');
        poolState.dataset.activeWorkers = String(active);
        poolState.dataset.desiredWorkers = String(desired);
    }

    function installWorkerPoolControls() {
        if (!app || document.getElementById('jobs-worker-count')) return;
        const toolbar = app.querySelector('.jobs-toolbar');
        const startButton = document.getElementById('jobs-start');
        if (!toolbar || !startButton) return;

        const label = document.createElement('label');
        label.className = 'jobs-worker-count-label';
        label.appendChild(document.createTextNode('Workers '));

        workerCountSelect = document.createElement('select');
        workerCountSelect.id = 'jobs-worker-count';
        workerCountSelect.setAttribute('aria-label', 'Detached PHP worker processes');
        for (let count = 1; count <= 8; count++) {
            const option = document.createElement('option');
            option.value = String(count);
            option.textContent = String(count);
            workerCountSelect.appendChild(option);
        }
        let saved = 4;
        try {
            saved = clampWorkers(localStorage.getItem(workerStorageKey));
        } catch (error) {
            saved = 4;
        }
        workerCountSelect.value = String(saved);
        workerCountSelect.addEventListener('change', function () {
            try {
                localStorage.setItem(workerStorageKey, String(selectedWorkers()));
            } catch (error) {
                // The selected value still applies to the current page session.
            }
        });
        label.appendChild(workerCountSelect);

        applyWorkersButton = document.createElement('button');
        applyWorkersButton.id = 'jobs-apply-workers';
        applyWorkersButton.type = 'button';
        applyWorkersButton.textContent = 'Apply workers';
        applyWorkersButton.addEventListener('click', async function () {
            applyWorkersButton.disabled = true;
            try {
                const response = await originalFetch(app.dataset.runUrl || 'api/v1/job-run.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify({queue: queue, mode: 'drain', workers: selectedWorkers()})
                });
                let body = {};
                try {
                    body = await response.json();
                } catch (error) {
                    throw new Error('The worker-pool endpoint returned invalid JSON (HTTP ' + response.status + ').');
                }
                if (!response.ok) {
                    const message = body && body.error && body.error.message
                        ? String(body.error.message)
                        : 'Could not resize the worker pool (HTTP ' + response.status + ').';
                    throw new Error(message);
                }
                const data = body && body.data ? body.data : {};
                if (data.worker) setPoolState(data.worker);
                const refresh = document.getElementById('jobs-refresh');
                if (refresh) refresh.click();
            } catch (error) {
                window.alert(error && error.message ? error.message : 'Could not resize the worker pool.');
            } finally {
                applyWorkersButton.disabled = false;
            }
        });

        poolState = document.createElement('span');
        poolState.id = 'jobs-worker-pool-state';
        poolState.className = 'muted';
        poolState.textContent = 'Pool 0/' + saved + ' active · max 8';

        toolbar.insertBefore(label, startButton);
        toolbar.insertBefore(applyWorkersButton, startButton);
        toolbar.appendChild(poolState);
    }

    function isBackgroundJobList(url, options) {
        if (!url || !/\/api\/v1\/job-status\.php$/i.test(url.pathname)) return false;
        const method = String((options && options.method) || 'GET').toUpperCase();
        return method === 'GET' && !url.searchParams.has('job_id');
    }

    function isJobRun(url, options) {
        if (!url || !/\/api\/v1\/job-run\.php$/i.test(url.pathname)) return false;
        return String((options && options.method) || 'GET').toUpperCase() === 'POST';
    }

    function isWorkerStatus(url, options) {
        if (!url || !/\/api\/v1\/job-worker-status\.php$/i.test(url.pathname)) return false;
        return String((options && options.method) || 'GET').toUpperCase() === 'GET';
    }

    function withWorkerCount(options) {
        const next = Object.assign({}, options || {});
        if (typeof next.body !== 'string' || next.body === '') return next;
        try {
            const body = JSON.parse(next.body);
            if (body && typeof body === 'object' && !Object.prototype.hasOwnProperty.call(body, 'workers')) {
                body.workers = selectedWorkers();
                next.body = JSON.stringify(body);
            }
        } catch (error) {
            // The normal client owns invalid-body handling.
        }
        return next;
    }

    function keyFor(url) {
        return [
            url.searchParams.get('queue') || '',
            url.searchParams.get('status') || '',
            url.searchParams.get('search') || '',
            url.searchParams.get('per_page') || url.searchParams.get('limit') || '100'
        ].join('\u0000');
    }

    function currentUrlDescriptor(requestedPage) {
        const params = new URLSearchParams(window.location.search);
        const page = Math.max(1, parseInt(params.get('page') || '1', 10) || 1);
        if (page !== requestedPage) return null;
        const move = String(params.get('job_move') || '');
        const cursor = String(params.get('job_cursor') || '');
        if (!['next', 'previous', 'last'].includes(move)) return null;
        if (move !== 'last' && !cursor) return null;
        return {move: move, cursor: cursor};
    }

    function persistDescriptor(page, descriptor) {
        const params = new URLSearchParams(window.location.search);
        if (page <= 1 || !descriptor || descriptor.move === 'first') {
            params.delete('job_move');
            params.delete('job_cursor');
        } else {
            params.set('job_move', descriptor.move);
            if (descriptor.cursor) params.set('job_cursor', descriptor.cursor);
            else params.delete('job_cursor');
        }
        const query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
    }

    async function backgroundJobListRequest(url, options) {
        const requestedPage = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1);
        const key = keyFor(url);
        let state = pageState.get(key);
        if (!state) {
            state = {pages: 1, descriptors: new Map()};
            pageState.set(key, state);
        }

        let descriptor = requestedPage === 1 ? {move: 'first', cursor: ''} : currentUrlDescriptor(requestedPage);
        if (!descriptor && state.descriptors.has(requestedPage)) {
            descriptor = state.descriptors.get(requestedPage);
        }
        if (!descriptor && state.pages > 1 && requestedPage === state.pages) {
            descriptor = {move: 'last', cursor: ''};
        }
        if (!descriptor) {
            descriptor = {move: 'first', cursor: ''};
            url.searchParams.set('page', '1');
        }

        url.pathname = url.pathname.replace(/job-status\.php$/i, 'job-status-cursor.php');
        url.searchParams.set('move', descriptor.move);
        if (descriptor.cursor) url.searchParams.set('cursor', descriptor.cursor);
        else url.searchParams.delete('cursor');

        const response = await originalFetch(url.toString(), options);
        if (!response.ok) return response;

        try {
            const body = await response.clone().json();
            const meta = body && body.meta ? body.meta : {};
            const page = Math.max(1, Number(meta.page || 1));
            state.pages = Math.max(1, Number(meta.pages || 1));
            state.descriptors.clear();
            if (meta.has_previous && meta.previous_cursor && page > 1) {
                state.descriptors.set(page - 1, {move: 'previous', cursor: String(meta.previous_cursor)});
            }
            if (meta.has_next && meta.next_cursor && page < state.pages) {
                state.descriptors.set(page + 1, {move: 'next', cursor: String(meta.next_cursor)});
            }
            persistDescriptor(page, page > 1 ? descriptor : {move: 'first', cursor: ''});
        } catch (error) {
            // The normal Background Jobs client owns response/error display.
        }

        return response;
    }

    window.fetch = async function (input, options) {
        const url = requestUrl(input);
        let requestOptions = options || {};
        if (isJobRun(url, requestOptions)) {
            requestOptions = withWorkerCount(requestOptions);
        }
        if (isBackgroundJobList(url, requestOptions)) {
            return backgroundJobListRequest(url, requestOptions);
        }

        const response = await originalFetch(input, requestOptions);
        if (isWorkerStatus(url, requestOptions) && response.ok) {
            response.clone().json().then(function (body) {
                const worker = body && body.data ? body.data.worker : null;
                if (worker) setPoolState(worker);
            }).catch(function () {
                // The normal Background Jobs client owns status errors.
            });
        }
        return response;
    };

    installWorkerPoolControls();

    const exportScript = document.createElement('script');
    const currentScript = document.currentScript;
    const version = currentScript && currentScript.src
        ? new URL(currentScript.src, window.location.href).search
        : '';
    exportScript.src = 'assets/background-jobs-failure-export.js' + version;
    exportScript.defer = true;
    document.head.appendChild(exportScript);
}());
