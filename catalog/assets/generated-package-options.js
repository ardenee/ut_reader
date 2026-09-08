(function () {
    'use strict';

    var form = document.getElementById('generated-package-options-form');
    if (!form) return;

    var button = document.getElementById('package-generate-button');
    var status = document.getElementById('package-existing-build-status');
    var endpoint = form.dataset.lookupEndpoint || 'generated-package-job.php';
    var timer = null;
    var lookupSerial = 0;
    var readyDownloadUrl = '';

    function setStatus(text, html) {
        if (!status) return;
        if (html) status.innerHTML = text;
        else status.textContent = text;
    }

    function setButton(state, label) {
        if (!button) return;
        button.disabled = state === 'disabled';
        button.textContent = label;
    }

    function progressUrl(jobId) {
        var url = new URL(form.action, window.location.href);
        var data = new FormData(form);
        data.forEach(function (value, key) {
            if (key === 'file_id') return;
            url.searchParams.set(key, String(value));
        });
        url.searchParams.set('job_id', String(jobId));
        return url.pathname + '?' + url.searchParams.toString();
    }

    async function lookup() {
        var serial = ++lookupSerial;
        readyDownloadUrl = '';
        setButton('disabled', 'Checking existing package…');
        setStatus('Checking whether this exact package is already queued or available…');

        var data = new FormData(form);
        data.set('action', 'lookup');
        data.set('csrf', form.dataset.csrf || '');

        try {
            var response = await fetch(endpoint, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            var payload = await response.json();
            if (serial !== lookupSerial) return;
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Package availability check failed.');
            }

            var jobStatus = String(payload.status || 'none');
            var jobId = Number(payload.job_id || 0);
            if (payload.ready && payload.download_url) {
                readyDownloadUrl = String(payload.download_url);
                setButton('ready', 'Download generated package');
                setStatus(
                    'This package has already been generated and is ready. '
                    + '<a href="' + progressUrl(jobId) + '">View build</a>.',
                    true
                );
                return;
            }

            if (jobStatus === 'queued' || jobStatus === 'running') {
                setButton('disabled', jobStatus === 'running' ? 'Package is being generated…' : 'Package build already queued');
                setStatus(
                    'This exact package is already ' + (jobStatus === 'running' ? 'being generated' : 'queued')
                    + '. <a href="' + progressUrl(jobId) + '">View progress</a>.',
                    true
                );
                return;
            }

            setButton('queue', 'Queue package build');
            setStatus('No matching queued or completed generated package was found.');
        } catch (error) {
            if (serial !== lookupSerial) return;
            setButton('queue', 'Queue package build');
            setStatus(error && error.message ? error.message : 'Could not check existing package status.');
        }
    }

    function scheduleLookup() {
        window.clearTimeout(timer);
        timer = window.setTimeout(lookup, 180);
    }

    form.addEventListener('input', scheduleLookup);
    form.addEventListener('change', scheduleLookup);
    form.addEventListener('submit', function (event) {
        if (readyDownloadUrl !== '') {
            event.preventDefault();
            window.location.href = readyDownloadUrl;
            return;
        }
        if (button && button.disabled) {
            event.preventDefault();
        }
    });

    lookup();
})();
