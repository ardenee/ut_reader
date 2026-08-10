(function () {
    'use strict';

    var form = document.getElementById('full-sync-form');
    var resultBox = document.getElementById('full-sync-result');
    if (!form || !resultBox) return;

    var submitButton = form.querySelector('button[type="submit"]');
    var actionUrl = form.dataset.actionUrl || 'api/v1/full-sync-job.php';
    var backgroundUrl = form.dataset.backgroundUrl || 'background-jobs.php';
    var csrf = form.dataset.csrf || '';

    function parseResponse(response) {
        return response.text().then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                throw new Error('Server returned an invalid Full Sync queue response (HTTP ' + response.status + ').');
            }
            if (!response.ok || (payload && payload.error)) {
                var message = payload && payload.error && payload.error.message
                    ? payload.error.message
                    : 'Full Sync could not be queued (HTTP ' + response.status + ').';
                throw new Error(message);
            }
            return payload;
        });
    }

    function renderError(message) {
        resultBox.innerHTML = '';
        var alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.textContent = message;
        resultBox.appendChild(alert);
    }

    function renderQueued(data) {
        resultBox.innerHTML = '';
        var alert = document.createElement('div');
        alert.className = 'alert alert-success';

        var text = document.createElement('div');
        text.textContent = 'Full Sync queued as background job #' + Number(data.job_id || 0)
            + ' for ' + String(data.game_name || 'the selected game')
            + '. You can leave this page; the worker owns the sync now.';
        alert.appendChild(text);

        var actions = document.createElement('p');
        actions.className = 'button-row';
        var jobs = document.createElement('a');
        jobs.className = 'button';
        jobs.href = backgroundUrl;
        jobs.textContent = 'Open Background Jobs';
        actions.appendChild(jobs);

        var errors = document.createElement('a');
        errors.className = 'button secondary';
        errors.href = 'system-errors.php';
        errors.textContent = 'Open System Errors';
        actions.appendChild(errors);

        alert.appendChild(actions);
        resultBox.appendChild(alert);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var gameInput = form.querySelector('[name="game_id"]');
        var gameId = Number(gameInput ? gameInput.value : 0);
        if (gameId < 1) {
            renderError('Select a valid game before starting Full Sync.');
            return;
        }
        if (!window.confirm('Queue a full game rescan and dependency rebuild? This may run for many hours, but you can leave the page once it is queued.')) {
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent || '';
            submitButton.textContent = 'Queueing Full Sync…';
        }
        resultBox.textContent = 'Queueing durable Full Sync job…';

        fetch(actionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ game_id: gameId })
        }).then(parseResponse).then(function (payload) {
            var data = payload && payload.data ? payload.data : {};
            if (Number(data.job_id || 0) < 1) {
                throw new Error('The queue did not return a valid Full Sync job ID.');
            }
            renderQueued(data);
        }).catch(function (error) {
            renderError(error.message || 'Full Sync could not be queued.');
        }).finally(function () {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText || 'Queue Full Sync';
            }
        });
    });
})();
