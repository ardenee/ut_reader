(function () {
    'use strict';

    var root = document.getElementById('source-identity-job-root');
    if (!root) return;

    var fileForm = document.getElementById('source-identity-file-form');
    var gameForm = document.getElementById('source-identity-game-form');
    var actionUrl = root.dataset.actionUrl || 'api/v1/job-action.php';
    var statusUrl = root.dataset.statusUrl || 'api/v1/job-status.php';
    var csrf = root.dataset.csrf || '';
    var activeOverlay = null;
    var activeJobId = 0;
    var pollTimer = null;
    var terminalStatuses = ['completed', 'cancelled', 'failed', 'dead_letter'];
    var supportedTypes = [
        'catalog.repair_source_identity_file',
        'catalog.repair_source_identity_game'
    ];

    function setFormsDisabled(disabled) {
        [fileForm, gameForm].forEach(function (form) {
            if (!form) return;
            form.querySelectorAll('button,input,select').forEach(function (element) {
                element.disabled = disabled;
            });
        });
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
        var separator = statusUrl.indexOf('?') === -1 ? '?' : '&';
        return fetch(statusUrl + separator + 'job_id=' + encodeURIComponent(String(jobId)), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(parseResponse).then(function (payload) {
            var jobs = payload && payload.data && Array.isArray(payload.data.jobs) ? payload.data.jobs : [];
            if (!jobs.length) throw new Error('The queued source identity job could not be found.');
            return jobs[0];
        });
    }

    function createOverlay(title) {
        if (activeOverlay) activeOverlay.remove();
        var overlay = document.createElement('div');
        overlay.className = 'source-identity-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = ''
            + '<div class="source-identity-dialog">'
            + '<h2></h2>'
            + '<p class="source-identity-message">Preparing…</p>'
            + '<div class="source-identity-progress"><span></span></div>'
            + '<div class="source-identity-count">Waiting for the queue…</div>'
            + '<div class="source-identity-summary"></div>'
            + '<div class="source-identity-failures" hidden></div>'
            + '<div class="source-identity-dialog-actions">'
            + '<button type="button" data-action="cancel">Cancel</button>'
            + '<button type="button" data-action="close" hidden>Close</button>'
            + '</div>'
            + '</div>';
        overlay.querySelector('h2').textContent = title;
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', function () {
            requestCancellation(overlay);
        });
        overlay.querySelector('[data-action="close"]').addEventListener('click', function () {
            window.clearTimeout(pollTimer);
            var refreshAudit = overlay.dataset.refreshAudit === '1';
            overlay.remove();
            activeOverlay = null;
            activeJobId = 0;
            if (refreshAudit) window.location.reload();
        });
        document.body.appendChild(overlay);
        activeOverlay = overlay;
        return overlay;
    }

    function clearJobFromUrl() {
        var url = new URL(window.location.href);
        url.searchParams.delete('job_id');
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
    }

    function setProgress(overlay, percent) {
        percent = Math.max(0, Math.min(100, Number(percent) || 0));
        overlay.querySelector('.source-identity-progress > span').style.width = percent + '%';
        return percent;
    }

    function showFailures(overlay, failures, truncated) {
        var box = overlay.querySelector('.source-identity-failures');
        if (!Array.isArray(failures) || !failures.length) {
            box.hidden = true;
            box.textContent = '';
            return;
        }
        box.hidden = false;
        box.textContent = failures.join('\n') + (truncated ? '\nAdditional failures were omitted from the stored result.' : '');
    }

    function terminalUi(overlay, refreshAudit) {
        overlay.querySelector('[data-action="cancel"]').hidden = true;
        var close = overlay.querySelector('[data-action="close"]');
        close.hidden = false;
        close.textContent = refreshAudit ? 'Close and refresh audit' : 'Close';
        overlay.dataset.refreshAudit = refreshAudit ? '1' : '0';
        setFormsDisabled(false);
        clearJobFromUrl();
    }

    function renderJob(overlay, job) {
        var type = String(job.job_type || '');
        if (supportedTypes.indexOf(type) === -1) {
            throw new Error('Job #' + job.id + ' is not a source identity repair job.');
        }
        overlay.querySelector('h2').textContent = type === 'catalog.repair_source_identity_game'
            ? 'Game source identity repair'
            : 'File source identity repair';

        var status = String(job.status || 'queued');
        var progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
        var result = job.result && typeof job.result === 'object' ? job.result : {};
        var percent = setProgress(overlay, status === 'completed' ? 100 : progress.percent);
        var message = String(progress.message || '');
        var done = Number(progress.done || 0);
        var total = Number(progress.total || 0);

        if (status === 'queued') {
            message = 'Queued and waiting for the exclusive dependency/identity maintenance slot.';
        } else if (status === 'running' && message === '') {
            message = 'Canonical source identity repair is running.';
        }
        overlay.querySelector('.source-identity-message').textContent = message;
        overlay.querySelector('.source-identity-count').textContent = status === 'running' && total > 0
            ? done + ' of ' + total + ' processed (' + Math.round(percent) + '%). Job #' + job.id + '.'
            : 'Status: ' + status.replace('_', ' ') + '. Job #' + job.id + '.';

        var summary = '';
        if (status === 'running') {
            summary = 'Changed=' + Number(progress.changed || 0)
                + ' | Aliases=' + Number(progress.aliases || 0)
                + ' | Failures=' + Number(progress.failures || 0);
        } else if (status === 'completed' && type === 'catalog.repair_source_identity_file') {
            summary = (result.changed ? 'Changed canonical identity.' : 'Identity already matched the mounted source path.')
                + '\nOld=' + String(result.old_package_name || '')
                + '\nNew=' + String(result.new_package_name || '')
                + '\nAliases=' + Number(result.alias_count || 0)
                + ' | Dependency files refreshed=' + Number(result.dependency_files_refreshed || 0);
        } else if (status === 'completed') {
            summary = 'Files=' + Number(result.total || 0)
                + ' | Changed=' + Number(result.changed || 0)
                + ' | Aliases=' + Number(result.aliases || 0)
                + ' | Failures=' + Number(result.failure_count || 0)
                + '\nDependencies rebuilt once=' + (result.dependencies_rebuilt ? 'yes' : 'no');
            showFailures(overlay, result.failures, Boolean(result.failures_truncated));
        }
        overlay.querySelector('.source-identity-summary').textContent = summary;

        if (status === 'completed') {
            overlay.querySelector('.source-identity-message').textContent = 'Source identity repair complete.';
            terminalUi(overlay, true);
        } else if (status === 'cancelled') {
            overlay.querySelector('.source-identity-message').textContent = 'Source identity repair cancelled.';
            terminalUi(overlay, true);
        } else if (status === 'failed' || status === 'dead_letter') {
            overlay.querySelector('.source-identity-message').textContent = 'Source identity repair failed.';
            showFailures(overlay, [String(job.last_error || 'The worker did not provide an error message.')], false);
            terminalUi(overlay, false);
        }

        return status;
    }

    function pollJob(overlay, jobId) {
        readJob(jobId).then(function (job) {
            var status = renderJob(overlay, job);
            if (terminalStatuses.indexOf(status) === -1) {
                window.clearTimeout(pollTimer);
                pollTimer = window.setTimeout(function () { pollJob(overlay, jobId); }, 1000);
            }
        }).catch(function (error) {
            overlay.querySelector('.source-identity-message').textContent = 'Status check failed; retrying…';
            overlay.querySelector('.source-identity-count').textContent = error.message || 'Could not read job status.';
            window.clearTimeout(pollTimer);
            pollTimer = window.setTimeout(function () { pollJob(overlay, jobId); }, 2500);
        });
    }

    function trackJob(jobId, title) {
        activeJobId = Number(jobId) || 0;
        if (activeJobId < 1) throw new Error('The server did not return a valid job ID.');
        setFormsDisabled(true);
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
        overlay.querySelector('.source-identity-message').textContent = 'Requesting cancellation…';
        postAction({
            action: 'cancel',
            job_id: activeJobId,
            reason: 'Cancelled from Source Identity Repair.'
        }).then(function () {
            overlay.querySelector('.source-identity-message').textContent = 'Cancellation requested; waiting for a safe worker checkpoint…';
        }).catch(function (error) {
            button.disabled = false;
            showFailures(overlay, [error.message || 'Cancellation request failed.'], false);
        });
    }

    function enqueue(payload, title) {
        setFormsDisabled(true);
        var overlay = createOverlay(title);
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
            setFormsDisabled(false);
            window.alert(error.message || 'Source identity repair could not be queued.');
        });
    }

    if (fileForm) {
        fileForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var fileId = Number(fileForm.querySelector('[name="file_id"]').value || 0);
            if (fileId < 1) {
                window.alert('Enter a valid file ID.');
                return;
            }
            enqueue({ action: 'enqueue_source_identity_file', file_id: fileId }, 'File source identity repair');
        });
    }

    if (gameForm) {
        gameForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var gameId = Number(gameForm.querySelector('[name="game_id"]').value || 0);
            if (gameId < 1) return;
            if (!window.confirm('Rewrite canonical identity fields for every verified UE4/UE5 file in this game and rebuild dependencies once?')) return;
            enqueue({ action: 'enqueue_source_identity_game', game_id: gameId }, 'Game source identity repair');
        });
    }

    var resumeJobId = Number(new URL(window.location.href).searchParams.get('job_id') || 0);
    if (resumeJobId > 0) {
        trackJob(resumeJobId, 'Source identity repair');
    }
})();
