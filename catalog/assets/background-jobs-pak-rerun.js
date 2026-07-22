(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    const tableBody = document.getElementById('jobs-table-body');
    const message = document.getElementById('jobs-message');
    if (!app || !tableBody || !window.fetch || !window.MutationObserver) return;

    const queue = app.dataset.queue || 'catalog';
    const rerunUrl = app.dataset.pakRerunUrl || 'api/v1/job-rerun-pak.php';
    const csrf = app.dataset.csrf || '';

    function responseError(body) {
        return body && body.error && body.error.message
            ? String(body.error.message)
            : 'The PAK import could not be queued again.';
    }

    async function rerun(jobId, button) {
        if (!window.confirm(
            'Queue a new full PAK import using the retained source file?\n\n'
            + 'The original completed job will remain unchanged. The PAK will be extracted and cataloged again.'
        )) {
            return;
        }

        button.disabled = true;
        try {
            const response = await fetch(rerunUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({job_id: jobId, queue: queue})
            });
            let body = null;
            try {
                body = await response.json();
            } catch (error) {
                throw new Error('The server returned an invalid response.');
            }
            if (!response.ok) {
                throw new Error(responseError(body));
            }

            const data = body && body.data ? body.data : {};
            const source = data.source === 'retained_pak' ? 'the retained managed PAK' : 'durable staging';
            if (message) {
                message.textContent = 'Queued PAK re-run as job #' + String(data.job_id || '') + ' using ' + source + '.';
            }
            const refresh = document.getElementById('jobs-refresh');
            if (refresh) refresh.click();
        } catch (error) {
            if (message) {
                message.textContent = error && error.message ? error.message : 'The PAK import could not be queued again.';
            }
        } finally {
            button.disabled = false;
        }
    }

    function enhanceRows() {
        tableBody.querySelectorAll('tr').forEach(function (row) {
            const cells = row.cells;
            if (!cells || cells.length < 10) return;
            const jobId = parseInt((cells[1].textContent || '').trim(), 10);
            const jobType = (cells[3].textContent || '').trim();
            const completed = Boolean(cells[2].querySelector('.job-status-completed'));
            const actions = cells[9];
            if (!jobId || jobType !== 'catalog.import_staged_pak' || !completed || !actions) return;
            if (actions.querySelector('[data-pak-rerun-job]')) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'Re-run';
            button.dataset.pakRerunJob = String(jobId);
            button.title = 'Queue a new import using the retained PAK source file.';
            button.addEventListener('click', function () {
                rerun(jobId, button);
            });
            actions.insertBefore(button, actions.firstChild);
        });
    }

    new MutationObserver(enhanceRows).observe(tableBody, {childList: true, subtree: true});
    enhanceRows();
})();
