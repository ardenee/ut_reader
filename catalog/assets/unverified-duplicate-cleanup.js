(function () {
    'use strict';

    var endpoint = 'unverified-duplicates-action.php';
    var pollTimer = null;
    var activeJobId = 0;

    function formatNumber(value) {
        return Number(value || 0).toLocaleString();
    }

    function createOverlay() {
        var root = document.createElement('div');
        root.className = 'unverified-action-overlay';
        root.innerHTML = ''
            + '<section class="unverified-action-dialog" role="dialog" aria-modal="true" aria-labelledby="unverified-duplicate-title">'
            + '<header class="unverified-action-dialog__header"><div><h2 id="unverified-duplicate-title">Deleting duplicate unverified files</h2><p data-summary>Queueing duplicate cleanup.</p></div></header>'
            + '<div class="unverified-action-dialog__body">'
            + '<div class="unverified-action-progress"><div class="unverified-action-progress__bar" data-bar></div></div>'
            + '<div class="unverified-action-current" data-current role="status" aria-live="polite">Waiting for an available storage-work slot…</div>'
            + '<ol class="unverified-action-log" data-log></ol>'
            + '</div><footer class="unverified-action-dialog__footer">'
            + '<button type="button" class="button secondary" data-cancel>Cancel</button>'
            + '<button type="button" class="button" data-close hidden>Close and refresh</button>'
            + '</footer></section>';
        document.body.appendChild(root);
        document.body.classList.add('unverified-action-overlay-open');
        return {
            root: root,
            summary: root.querySelector('[data-summary]'),
            bar: root.querySelector('[data-bar]'),
            current: root.querySelector('[data-current]'),
            log: root.querySelector('[data-log]'),
            cancel: root.querySelector('[data-cancel]'),
            close: root.querySelector('[data-close]')
        };
    }

    function appendLog(overlay, message, tone) {
        var item = document.createElement('li');
        item.className = tone === 'error' ? 'is-error' : (tone === 'success' ? 'is-success' : 'is-info');
        item.textContent = message;
        overlay.log.appendChild(item);
    }

    async function readJson(response) {
        var text = await response.text();
        var payload;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            throw new Error('The server returned an invalid duplicate-cleanup response.');
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Duplicate cleanup request failed.');
        }
        return payload;
    }

    async function post(form, fields) {
        var csrf = form.querySelector('input[name="csrf"]');
        var data = new FormData();
        data.append('csrf', csrf ? csrf.value : '');
        Object.keys(fields).forEach(function (key) { data.append(key, String(fields[key])); });
        return readJson(await fetch(endpoint, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }));
    }

    async function status(jobId) {
        return readJson(await fetch(endpoint + '?job_id=' + encodeURIComponent(String(jobId)), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }));
    }

    function finish(overlay, button) {
        window.clearTimeout(pollTimer);
        overlay.cancel.hidden = true;
        overlay.close.hidden = false;
        overlay.close.disabled = false;
        button.disabled = false;
        overlay.close.addEventListener('click', function () { window.location.reload(); }, { once: true });
        overlay.close.focus();
    }

    function renderResult(overlay, result) {
        overlay.summary.textContent = formatNumber(result.deleted_files) + ' duplicate file(s) deleted from '
            + formatNumber(result.duplicate_groups) + ' duplicate group(s).';
        overlay.current.textContent = 'Kept one copy of each size + MD5 group and freed '
            + (result.deleted_bytes_text || '0 B') + '.';
        if (!Number(result.duplicate_groups || 0)) {
            appendLog(overlay, 'No duplicate unverified files were found.', 'info');
        }
        (result.deleted || []).forEach(function (entry) {
            appendLog(
                overlay,
                'Deleted ' + entry.name + ' from ' + entry.queue
                    + '; kept ' + entry.kept_name + ' in ' + entry.kept_queue + '.',
                'success'
            );
        });
        if (result.deleted_list_truncated) {
            appendLog(overlay, 'The deletion list is truncated; all matching duplicates were still processed.', 'info');
        }
        (result.errors || []).forEach(function (message) { appendLog(overlay, message, 'error'); });
        if (result.errors_truncated) {
            appendLog(
                overlay,
                'Only the first 200 of ' + formatNumber(result.error_count) + ' errors are shown; all candidates were still processed.',
                'info'
            );
        }
    }

    function poll(form, button, overlay) {
        status(activeJobId).then(function (payload) {
            var job = payload.job || {};
            var progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
            var state = String(job.status || 'queued');
            var percent = state === 'completed' ? 100 : Math.max(0, Math.min(100, Number(progress.percent || 0)));
            overlay.bar.style.width = percent + '%';
            overlay.summary.textContent = state === 'queued'
                ? 'Duplicate cleanup is queued.'
                : (String(progress.message || 'Duplicate cleanup is running.'));
            overlay.current.textContent = 'Status: ' + state.replace('_', ' ') + '. Job #' + job.id
                + (progress.hashed_files !== undefined ? ' · Hashed ' + formatNumber(progress.hashed_files) : '')
                + (progress.deleted_files !== undefined ? ' · Deleted ' + formatNumber(progress.deleted_files) : '')
                + (progress.errors !== undefined ? ' · Errors ' + formatNumber(progress.errors) : '');

            if (state === 'completed') {
                renderResult(overlay, job.result || {});
                finish(overlay, button);
                return;
            }
            if (state === 'cancelled') {
                overlay.summary.textContent = 'Duplicate cleanup cancelled.';
                overlay.current.textContent = 'Files already deleted before the cancellation checkpoint remain deleted; the job stopped before processing additional files.';
                finish(overlay, button);
                return;
            }
            if (state === 'failed' || state === 'dead_letter') {
                overlay.summary.textContent = 'Duplicate cleanup failed.';
                overlay.current.textContent = String(job.last_error || 'The worker did not provide an error message.');
                appendLog(overlay, overlay.current.textContent, 'error');
                finish(overlay, button);
                return;
            }
            pollTimer = window.setTimeout(function () { poll(form, button, overlay); }, 1000);
        }).catch(function (error) {
            overlay.current.textContent = (error.message || 'Status check failed.') + ' Retrying…';
            pollTimer = window.setTimeout(function () { poll(form, button, overlay); }, 2500);
        });
    }

    async function runCleanup(form, button) {
        var overlay = createOverlay();
        button.disabled = true;
        overlay.cancel.addEventListener('click', function () {
            if (activeJobId < 1) return;
            overlay.cancel.disabled = true;
            overlay.current.textContent = 'Requesting cancellation at the next safe hash/delete checkpoint…';
            post(form, { action: 'cancel', job_id: activeJobId }).catch(function (error) {
                overlay.cancel.disabled = false;
                appendLog(overlay, error.message || 'Cancellation request failed.', 'error');
            });
        });

        try {
            var payload = await post(form, { action: 'enqueue' });
            activeJobId = Number(payload.job_id || 0);
            if (activeJobId < 1) throw new Error('The server did not return a valid cleanup job ID.');
            poll(form, button, overlay);
        } catch (error) {
            overlay.summary.textContent = 'Duplicate cleanup could not be queued.';
            overlay.current.textContent = error.message || 'The duplicate cleanup request failed.';
            appendLog(overlay, overlay.current.textContent, 'error');
            finish(overlay, button);
        }
    }

    function init() {
        var form = document.getElementById('unverified-bulk-form');
        if (!form || document.getElementById('unverified-delete-duplicates')) return;
        var actions = form.querySelector('.uv-actions');
        if (!actions) return;

        var button = document.createElement('button');
        button.type = 'button';
        button.id = 'unverified-delete-duplicates';
        button.className = 'danger';
        button.textContent = 'Delete duplicate files';
        button.title = 'Queue an exact size and MD5 cleanup across every unverified queue, leaving one copy of each';

        var deleteSelected = actions.querySelector('button[name="action"][value="delete"]');
        if (deleteSelected && deleteSelected.nextSibling) {
            actions.insertBefore(button, deleteSelected.nextSibling);
        } else {
            actions.appendChild(button);
        }

        button.addEventListener('click', function () {
            var confirmed = window.confirm(
                'Queue a scan of every unverified queue and permanently delete files with identical size and MD5?\n\n'
                + 'One copy of each duplicate group will be kept. Verified game files will not be touched.'
            );
            if (confirmed) runCleanup(form, button);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
