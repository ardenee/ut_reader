(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    const workerMessage = document.getElementById('jobs-worker-message');
    const runNext = document.getElementById('jobs-run-next');
    const runAll = document.getElementById('jobs-run-all');
    if (!app || !workerMessage || !runNext || !runAll || !window.fetch) return;

    const queue = app.dataset.queue || 'catalog';
    const workerStatusUrl = app.dataset.workerStatusUrl || 'api/v1/job-worker-status.php';

    async function poll() {
        try {
            const params = new URLSearchParams({queue: queue});
            const response = await fetch(workerStatusUrl + '?' + params.toString(), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const body = await response.json();
            if (!response.ok) return;
            const worker = body && body.data ? body.data.worker || {} : {};
            if (!worker.stale_code) {
                runNext.textContent = 'Start next';
                runAll.textContent = 'Start queued';
                return;
            }

            const state = worker.state || {};
            const pid = Number(state.pid || 0);
            const tail = String(worker.log_tail || '').trim();
            const lines = tail ? tail.split(/\r?\n/).filter(Boolean) : [];
            const lastLine = lines.length ? lines[lines.length - 1].slice(0, 1200) : '';
            workerMessage.textContent = 'Detached worker PID ' + (pid || '?')
                + ' is running PHP code from before the latest update. Start queued will terminate it, requeue its active job without consuming an attempt, and launch the current worker.'
                + (lastLine ? ' Last worker log: ' + lastLine : '');
            runNext.disabled = false;
            runAll.disabled = false;
            runNext.textContent = 'Restart + start next';
            runAll.textContent = 'Restart + start queued';
        } catch (error) {
            // The primary background-jobs script continues to display base status.
        }
    }

    window.setInterval(poll, 1500);
    poll();
}());
