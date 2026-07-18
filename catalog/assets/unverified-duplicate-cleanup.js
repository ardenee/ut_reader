(function () {
    'use strict';

    function formatNumber(value) {
        return Number(value || 0).toLocaleString();
    }

    function createOverlay() {
        var root = document.createElement('div');
        root.className = 'unverified-action-overlay';
        root.innerHTML = ''
            + '<section class="unverified-action-dialog" role="dialog" aria-modal="true" aria-labelledby="unverified-duplicate-title">'
            + '<header class="unverified-action-dialog__header"><div><h2 id="unverified-duplicate-title">Deleting duplicate unverified files</h2><p data-summary>Scanning all unverified queues.</p></div></header>'
            + '<div class="unverified-action-dialog__body">'
            + '<div class="unverified-action-progress"><div class="unverified-action-progress__bar" data-bar></div></div>'
            + '<div class="unverified-action-current" data-current role="status" aria-live="polite">Comparing file sizes. MD5 will be calculated only for same-size candidates…</div>'
            + '<ol class="unverified-action-log" data-log></ol>'
            + '</div><footer class="unverified-action-dialog__footer">'
            + '<button type="button" class="button" data-close disabled>Close and refresh</button>'
            + '</footer></section>';
        document.body.appendChild(root);
        document.body.classList.add('unverified-action-overlay-open');
        return {
            root: root,
            summary: root.querySelector('[data-summary]'),
            bar: root.querySelector('[data-bar]'),
            current: root.querySelector('[data-current]'),
            log: root.querySelector('[data-log]'),
            close: root.querySelector('[data-close]')
        };
    }

    function appendLog(overlay, message, tone) {
        var item = document.createElement('li');
        item.className = tone === 'error' ? 'is-error' : (tone === 'success' ? 'is-success' : 'is-info');
        item.textContent = message;
        overlay.log.appendChild(item);
    }

    async function runCleanup(form, button) {
        var overlay = createOverlay();
        var csrf = form.querySelector('input[name="csrf"]');
        var data = new FormData();
        data.append('csrf', csrf ? csrf.value : '');
        button.disabled = true;
        overlay.bar.style.width = '25%';

        try {
            var response = await fetch('unverified-duplicates-action.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            overlay.bar.style.width = '80%';
            var text = await response.text();
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                throw new Error('The server returned an invalid duplicate-cleanup response.');
            }
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Duplicate cleanup failed.');
            }

            overlay.bar.style.width = '100%';
            overlay.summary.textContent = formatNumber(payload.deleted_files) + ' duplicate file(s) deleted from '
                + formatNumber(payload.duplicate_groups) + ' duplicate group(s).';
            overlay.current.textContent = 'Kept one copy of each size + MD5 group and freed '
                + (payload.deleted_bytes_text || '0 B') + '.';

            if (!payload.duplicate_groups) {
                appendLog(overlay, 'No duplicate unverified files were found.', 'info');
            }
            (payload.deleted || []).forEach(function (entry) {
                appendLog(
                    overlay,
                    'Deleted ' + entry.name + ' from ' + entry.queue
                        + '; kept ' + entry.kept_name + ' in ' + entry.kept_queue + '.',
                    'success'
                );
            });
            if (payload.deleted_list_truncated) {
                appendLog(overlay, 'The deletion list is truncated; all matching duplicates were still processed.', 'info');
            }
            (payload.errors || []).forEach(function (message) {
                appendLog(overlay, message, 'error');
            });
        } catch (error) {
            overlay.bar.style.width = '100%';
            overlay.summary.textContent = 'Duplicate cleanup failed.';
            overlay.current.textContent = error.message || 'The duplicate cleanup request failed.';
            appendLog(overlay, overlay.current.textContent, 'error');
        }

        overlay.close.disabled = false;
        overlay.close.addEventListener('click', function () { window.location.reload(); });
        overlay.close.focus();
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
        button.title = 'Delete duplicate unverified queue files with identical size and MD5, leaving one copy of each';

        var deleteSelected = actions.querySelector('button[name="action"][value="delete"]');
        if (deleteSelected && deleteSelected.nextSibling) {
            actions.insertBefore(button, deleteSelected.nextSibling);
        } else {
            actions.appendChild(button);
        }

        button.addEventListener('click', function () {
            var confirmed = window.confirm(
                'Scan every unverified queue and permanently delete files with identical size and MD5?\n\n'
                + 'One copy of each duplicate group will be kept. Verified game files will not be touched.'
            );
            if (!confirmed) return;
            runCleanup(form, button);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
