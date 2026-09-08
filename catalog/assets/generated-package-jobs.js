(function () {
    'use strict';

    var form = document.getElementById('package-job-form');
    if (!form) return;

    var endpoint = form.dataset.endpoint || 'generated-package-job.php';
    var downloadEndpoint = form.dataset.downloadEndpoint || 'generated-package-download.php';
    var title = document.getElementById('package-job-title');
    var message = document.getElementById('package-job-message');
    var statusBox = document.getElementById('package-job-status');
    var summary = document.getElementById('package-job-summary');
    var filesBox = document.getElementById('package-job-files');
    var bar = document.getElementById('package-job-bar');
    var cancel = document.getElementById('package-job-cancel');
    var download = document.getElementById('package-job-download');
    var back = document.getElementById('package-job-back');
    var jobId = Number(form.dataset.resumeJobId || 0);
    var pollTimer = null;
    var workerWarning = '';
    var currentState = 'queued';
    var terminal = ['completed', 'cancelled', 'failed', 'dead_letter'];

    function fmt(value) {
        return Number(value || 0).toLocaleString();
    }

    function fmtBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var value = Number(bytes || 0);
        var index = 0;
        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index++;
        }
        return (index ? value.toFixed(2) : String(value)) + ' ' + units[index];
    }

    function esc(value) {
        var node = document.createElement('span');
        node.textContent = String(value == null ? '' : value);
        return node.innerHTML;
    }

    async function parse(response) {
        var text = await response.text();
        var payload;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            throw new Error('The server returned an invalid package-job response.');
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Package-job request failed.');
        }
        return payload;
    }

    async function post(fields) {
        var data = new FormData(form);
        Object.keys(fields).forEach(function (key) { data.set(key, String(fields[key])); });
        return parse(await fetch(endpoint, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }));
    }

    async function readStatus() {
        return parse(await fetch(endpoint + '?job_id=' + encodeURIComponent(String(jobId)), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }));
    }

    function setJobUrl() {
        var url = new URL(window.location.href);
        url.searchParams.set('job_id', String(jobId));
        window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString() + url.hash);
    }

    function setPercent(percent) {
        percent = Math.max(0, Math.min(100, Number(percent) || 0));
        bar.style.width = percent + '%';
        return percent;
    }

    function stopPolling() {
        window.clearTimeout(pollTimer);
        cancel.hidden = true;
    }

    function disableDownload() {
        download.removeAttribute('href');
        download.removeAttribute('download');
        download.setAttribute('aria-disabled', 'true');
        download.style.pointerEvents = 'none';
        download.style.opacity = '0.55';
        download.textContent = 'Download generated package';
    }

    function enableDownload(job, result) {
        download.href = downloadEndpoint + '?job_id=' + encodeURIComponent(String(job.id));
        download.download = String(result.download_name || '');
        download.removeAttribute('aria-disabled');
        download.style.pointerEvents = '';
        download.style.opacity = '';
        download.textContent = 'Download generated package';
        title.textContent = 'Generated package ready';
        message.textContent = 'The generated package is ready to download.';
    }

    function renderFiles(progress) {
        if (!filesBox) return;
        var files = Array.isArray(progress.files) ? progress.files : [];
        var omitted = Number(progress.files_omitted || 0);
        if (!files.length) {
            filesBox.innerHTML = '';
            return;
        }

        var html = '<h3>Files in generated package (' + fmt(progress.file_count || files.length) + ')</h3>'
            + '<table><thead><tr><th>File</th><th>Install path</th><th>Size</th></tr></thead><tbody>';
        files.forEach(function (file) {
            html += '<tr><td>' + esc(file.original_name || file.package_name || ('File #' + file.file_id)) + '</td>'
                + '<td class="mono small">' + esc(file.install_path || '') + '</td>'
                + '<td>' + esc(fmtBytes(file.file_size || 0)) + '</td></tr>';
        });
        html += '</tbody></table>';
        if (omitted > 0) {
            html += '<p class="muted small">+' + fmt(omitted) + ' additional package file'
                + (omitted === 1 ? '' : 's') + ' not shown.</p>';
        }
        filesBox.innerHTML = html;
    }

    function render(job) {
        var state = String(job.status || 'queued');
        currentState = state;
        var progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
        var result = job.result && typeof job.result === 'object' ? job.result : {};
        var percent = setPercent(state === 'completed' ? 100 : progress.percent);

        title.textContent = state === 'completed' ? 'Generated package ready' : 'Generating package';
        message.textContent = state === 'queued'
            ? (workerWarning !== ''
                ? 'Queued, but the dedicated package worker could not be started automatically: ' + workerWarning
                : 'Queued for the dedicated package worker.')
            : String(progress.message || 'The background worker is processing the package.');
        statusBox.textContent = 'Status: ' + state.replace('_', ' ') + ' · Job #' + job.id
            + (progress.file_count !== undefined ? ' · Files ' + fmt(progress.file_count) : '')
            + (progress.total_bytes !== undefined ? ' · Source payload ' + fmtBytes(progress.total_bytes) : '')
            + ' · ' + Math.round(percent) + '%';

        renderFiles(progress);

        if (state === 'completed') {
            stopPolling();
            if (result.expired) {
                disableDownload();
                message.textContent = 'The generated package expired. Return to the download options and build it again.';
                summary.textContent = '';
                return state;
            }
            summary.textContent = 'Format=' + String(result.format || '')
                + '\nFiles=' + fmt(result.file_count)
                + ' · Source payload=' + fmtBytes(result.total_source_bytes)
                + ' · Artifact=' + fmtBytes(result.artifact_size)
                + '\nBase-game excluded=' + fmt(result.base_game_files_excluded)
                + ' · Missing=' + fmt(result.missing_dependencies)
                + ' · Package-only=' + fmt(result.package_only_dependencies)
                + '\nExpires=' + String(result.expires_at || '');
            enableDownload(job, result);
            return state;
        }

        disableDownload();
        if (state === 'cancelled') {
            stopPolling();
            message.textContent = 'Package generation was cancelled. Temporary output was not published.';
            summary.textContent = '';
            return state;
        }
        if (state === 'failed' || state === 'dead_letter') {
            stopPolling();
            message.textContent = 'Package generation failed.';
            summary.textContent = String(job.last_error || 'The worker did not provide an error message.');
            return state;
        }
        return state;
    }

    function poll() {
        readStatus().then(function (payload) {
            if (payload.worker_error) workerWarning = String(payload.worker_error);
            var state = render(payload.job || {});
            if (terminal.indexOf(state) === -1) {
                pollTimer = window.setTimeout(poll, 1000);
            }
        }).catch(function (error) {
            message.textContent = 'Status check failed; retrying…';
            statusBox.textContent = error.message || 'Could not read package-job status.';
            pollTimer = window.setTimeout(poll, 2500);
        });
    }

    function enqueue() {
        cancel.disabled = true;
        disableDownload();
        message.textContent = 'Checking for an existing generated package or queued build…';
        post({ action: 'enqueue' }).then(function (payload) {
            jobId = Number(payload.job_id || 0);
            if (jobId < 1) throw new Error('The server did not return a valid package-job ID.');
            if (payload.worker_error) workerWarning = String(payload.worker_error);
            setJobUrl();
            cancel.disabled = false;
            poll();
        }).catch(function (error) {
            stopPolling();
            disableDownload();
            title.textContent = 'Package generation unavailable';
            message.textContent = error.message || 'The package could not be queued.';
            statusBox.textContent = '';
        });
    }

    cancel.addEventListener('click', function () {
        if (jobId < 1) return;
        cancel.disabled = true;
        message.textContent = 'Requesting cancellation…';
        post({ action: 'cancel', job_id: jobId }).then(function () {
            message.textContent = 'Cancellation requested. A package already being written will be discarded before publication.';
        }).catch(function (error) {
            cancel.disabled = false;
            summary.textContent = error.message || 'Cancellation request failed.';
        });
    });

    download.addEventListener('click', function (event) {
        if (download.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
        }
    });

    if (back) {
        back.addEventListener('click', function (event) {
            if (terminal.indexOf(currentState) !== -1) return;
            event.preventDefault();
            var proceed = window.confirm(
                'This package is still being generated and should be ready soon. '
                + 'The build will continue if you return to Download options, and the Generate button there '
                + 'will remain disabled while this same package is queued or running. Return to Download options?'
            );
            if (proceed) {
                window.location.href = back.href;
            }
        });
    }

    disableDownload();
    if (jobId > 0) {
        setJobUrl();
        poll();
    } else {
        enqueue();
    }
})();
