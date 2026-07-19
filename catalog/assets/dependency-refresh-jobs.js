(function () {
    'use strict';

    var form = document.getElementById('dependency-refresh-form');
    if (!form) return;

    var actionUrl = form.dataset.actionUrl || 'api/v1/job-action.php';
    var statusUrl = form.dataset.statusUrl || 'api/v1/job-status.php';
    var csrf = form.dataset.csrf || '';
    var activeOverlay = null;
    var pollTimer = null;
    var activeJobId = 0;
    var terminalStatuses = ['completed', 'cancelled', 'failed', 'dead_letter'];
    var dependencyTypes = [
        'catalog.rebuild_game_dependencies',
        'catalog.rebuild_file_dependencies'
    ];

    function delay(callback, milliseconds) {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(callback, milliseconds);
    }

    function parseResponse(response) {
        return response.text().then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                throw new Error('Server returned an invalid response (HTTP ' + response.status + ').');
            }
            if (!response.ok || payload.error) {
                throw new Error(payload && payload.error && payload.error.message
                    ? payload.error.message
                    : 'Request failed (HTTP ' + response.status + ').');
            }
            return payload;
        });
    }

    function postAction(payload) {
        return fetch(actionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify(payload)
        }).then(parseResponse);
    }

    function readJob(jobId) {
        var url = statusUrl + (statusUrl.indexOf('?') === -1 ? '?' : '&') + 'job_id=' + encodeURIComponent(String(jobId));
        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(parseResponse).then(function (payload) {
            var jobs = payload && payload.data && Array.isArray(payload.data.jobs) ? payload.data.jobs : [];
            if (!jobs.length) throw new Error('The queued dependency job could not be found.');
            return jobs[0];
        });
    }

    function createOverlay(title) {
        if (activeOverlay) activeOverlay.remove();
        var overlay = document.createElement('div');
        overlay.className = 'dependency-refresh-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = ''
            + '<div class="dependency-refresh-dialog">'
            + '<h2></h2>'
            + '<p class="dependency-refresh-message">Preparing…</p>'
            + '<div class="dependency-refresh-progress"><span></span></div>'
            + '<div class="dependency-refresh-count">Waiting for the queue…</div>'
            + '<div class="dependency-refresh-totals"></div>'
            + '<div class="dependency-refresh-failures" hidden></div>'
            + '<div class="dependency-refresh-actions">'
            + '<button type="button" data-action="cancel">Cancel</button>'
            + '<button type="button" data-action="close" hidden>Close</button>'
            + '</div>'
            + '</div>';
        overlay.querySelector('h2').textContent = title;
        overlay.querySelector('[data-action="close"]').addEventListener('click', function () {
            window.clearTimeout(pollTimer);
            overlay.remove();
            activeOverlay = null;
            activeJobId = 0;
        });
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', function () {
            requestCancellation(overlay);
        });
        document.body.appendChild(overlay);
        activeOverlay = overlay;
        return overlay;
    }

    function setProgress(overlay, percent) {
        percent = Math.max(0, Math.min(100, Number(percent) || 0));
        overlay.querySelector('.dependency-refresh-progress > span').style.width = percent + '%';
    }

    function showFailure(overlay, message) {
        var failure = overlay.querySelector('.dependency-refresh-failures');
        failure.hidden = false;
        failure.textContent = message;
    }

    function formatStats(stats) {
        if (!stats || typeof stats !== 'object') return '';
        return 'Dependencies=' + Number(stats.total || 0)
            + '\nResolved=' + Number(stats.resolved || 0)
            + ' | Package only=' + Number(stats.package_only || 0)
            + ' | Common=' + Number(stats.common || 0)
            + ' | Missing=' + Number(stats.missing || 0);
    }

    function terminalUi(overlay) {
        overlay.querySelector('[data-action="cancel"]').hidden = true;
        overlay.querySelector('[data-action="close"]').hidden = false;
        form.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
    }

    function clearJobFromUrl() {
        var url = new URL(window.location.href);
        url.searchParams.delete('job_id');
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
    }

    function renderJob(overlay, job) {
        if (dependencyTypes.indexOf(String(job.job_type || '')) === -1) {
            throw new Error('Job #' + job.id + ' is not a dependency refresh job.');
        }

        var status = String(job.status || 'queued');
        var progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
        var result = job.result && typeof job.result === 'object' ? job.result : {};
        var percent = status === 'completed' ? 100 : Number(progress.percent || 0);
        setProgress(overlay, percent);

        var message = String(progress.message || '');
        var done = Number(progress.done || 0);
        var total = Number(progress.total || 0);
        if (status === 'queued') {
            message = 'Queued and waiting for an available dependency-work slot.';
        } else if (status === 'running' && message === '') {
            message = 'Dependency refresh is running.';
        }
        overlay.querySelector('.dependency-refresh-message').textContent = message;
        overlay.querySelector('.dependency-refresh-count').textContent = status === 'running' && total > 0
            ? done + ' of ' + total + ' processed (' + Math.round(percent) + '%). Job #' + job.id + '.'
            : 'Status: ' + status.replace('_', ' ') + '. Job #' + job.id + '.';

        var statsText = formatStats(result.stats);
        if (statsText) overlay.querySelector('.dependency-refresh-totals').textContent = statsText;

        if (status === 'completed') {
            overlay.querySelector('.dependency-refresh-message').textContent = 'Dependency refresh complete.';
            terminalUi(overlay);
            clearJobFromUrl();
        } else if (status === 'cancelled') {
            overlay.querySelector('.dependency-refresh-message').textContent = 'Dependency refresh cancelled.';
            terminalUi(overlay);
            clearJobFromUrl();
        } else if (status === 'dead_letter' || status === 'failed') {
            overlay.querySelector('.dependency-refresh-message').textContent = 'Dependency refresh failed.';
            showFailure(overlay, String(job.last_error || 'The worker did not provide an error message.'));
            terminalUi(overlay);
            clearJobFromUrl();
        }

        return status;
    }

    function pollJob(overlay, jobId) {
        readJob(jobId).then(function (job) {
            var status = renderJob(overlay, job);
            if (terminalStatuses.indexOf(status) === -1) {
                delay(function () { pollJob(overlay, jobId); }, 1000);
            }
        }).catch(function (error) {
            overlay.querySelector('.dependency-refresh-message').textContent = 'Status check failed; retrying…';
            overlay.querySelector('.dependency-refresh-count').textContent = error.message || 'Could not read job status.';
            delay(function () { pollJob(overlay, jobId); }, 2500);
        });
    }

    function trackJob(jobId, title) {
        activeJobId = Number(jobId) || 0;
        if (activeJobId < 1) throw new Error('The server did not return a valid job ID.');
        var overlay = createOverlay(title);
        var url = new URL(window.location.href);
        url.searchParams.set('job_id', String(activeJobId));
        window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString() + url.hash);
        pollJob(overlay, activeJobId);
    }

    function requestCancellation(overlay) {
        if (activeJobId < 1) return;
        var button = overlay.querySelector('[data-action="cancel"]');
        button.disabled = true;
        overlay.querySelector('.dependency-refresh-message').textContent = 'Requesting cancellation…';
        postAction({
            action: 'cancel',
            job_id: activeJobId,
            reason: 'Cancelled from Dependency Refresh.'
        }).then(function () {
            overlay.querySelector('.dependency-refresh-message').textContent = 'Cancellation requested; waiting for a safe worker checkpoint…';
        }).catch(function (error) {
            button.disabled = false;
            showFailure(overlay, error.message || 'Cancellation request failed.');
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var fileId = Number(form.querySelector('[name="file_id"]').value || 0);
        var gameId = Number(form.querySelector('[name="game_id"]').value || 0);
        if (fileId < 1 && gameId < 1) {
            window.alert('Choose a game or enter a file ID.');
            return;
        }

        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        var title = fileId > 0 ? 'Single file dependency refresh' : 'Full game dependency refresh';
        var overlay = createOverlay(title);
        var payload = fileId > 0
            ? { action: 'enqueue_rebuild_file', file_id: fileId }
            : { action: 'enqueue_rebuild_game', game_id: gameId };

        postAction(payload).then(function (response) {
            var data = response && response.data ? response.data : {};
            activeJobId = Number(data.job_id || 0);
            if (activeJobId < 1) throw new Error('The server did not return a valid job ID.');
            var url = new URL(window.location.href);
            url.searchParams.set('job_id', String(activeJobId));
            window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString() + url.hash);
            pollJob(overlay, activeJobId);
        }).catch(function (error) {
            overlay.remove();
            activeOverlay = null;
            activeJobId = 0;
            form.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
            window.alert(error.message || 'Dependency refresh could not be queued.');
        });
    });

    var resumeJobId = Number(new URL(window.location.href).searchParams.get('job_id') || 0);
    if (resumeJobId > 0) {
        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        trackJob(resumeJobId, 'Dependency refresh');
    }
})();
