(function () {
    'use strict';

    var overlayStyleInstalled = false;

    function installOverlayStyle() {
        if (overlayStyleInstalled) return;
        overlayStyleInstalled = true;
        var style = document.createElement('style');
        style.textContent = [
            'body.unverified-action-overlay-open { overflow: hidden; }',
            '.unverified-action-overlay { position: fixed; inset: 0; z-index: 30000; display: grid; place-items: center; padding: 20px; background: rgba(4, 8, 16, .82); backdrop-filter: blur(4px); }',
            '.unverified-action-dialog { width: min(760px, 96vw); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--line2); border-radius: 12px; background: var(--panel, #111827); box-shadow: 0 28px 80px rgba(0,0,0,.55); }',
            '.unverified-action-dialog.is-object-check { width: min(1500px, 98vw); height: 94vh; }',
            '.unverified-action-dialog__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 18px 20px 12px; border-bottom: 1px solid var(--line2); }',
            '.unverified-action-dialog__header h2 { margin: 0 0 4px; }',
            '.unverified-action-dialog__header p { margin: 0; color: var(--muted); }',
            '.unverified-action-dialog__body { min-height: 0; display: flex; flex: 1; flex-direction: column; gap: 14px; padding: 18px 20px; overflow: auto; }',
            '.unverified-action-progress { height: 12px; overflow: hidden; border: 1px solid var(--line2); border-radius: 999px; background: rgba(255,255,255,.055); }',
            '.unverified-action-progress__bar { width: 0; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--blue), #78a9ff); transition: width .2s ease; }',
            '.unverified-action-progress.is-indeterminate .unverified-action-progress__bar { width: 38%; animation: unverified-progress-slide 1.1s ease-in-out infinite; }',
            '@keyframes unverified-progress-slide { 0% { transform: translateX(-115%); } 100% { transform: translateX(305%); } }',
            '.unverified-action-current { min-height: 42px; padding: 10px 12px; border: 1px solid var(--line2); border-radius: 8px; background: rgba(255,255,255,.025); overflow-wrap: anywhere; }',
            '.unverified-action-log { min-height: 120px; max-height: 340px; margin: 0; padding: 10px 10px 10px 34px; overflow: auto; border: 1px solid var(--line2); border-radius: 8px; background: rgba(0,0,0,.16); }',
            '.unverified-action-log li { margin: 4px 0; overflow-wrap: anywhere; }',
            '.unverified-action-log li.is-success { color: #b8f3cb; }',
            '.unverified-action-log li.is-error { color: #ffb5b5; }',
            '.unverified-action-log li.is-info { color: var(--muted); }',
            '.unverified-action-dialog__footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 20px 18px; border-top: 1px solid var(--line2); }',
            '.unverified-action-frame { display: none; width: 100%; min-height: 0; flex: 1; border: 1px solid var(--line2); border-radius: 8px; background: #0b1020; }',
            '.unverified-action-frame.is-ready { display: block; }',
            '.unverified-action-dialog.is-object-check .unverified-action-dialog__body { overflow: hidden; }',
            '.unverified-action-dialog.is-object-check .unverified-action-log { min-height: 96px; max-height: 180px; }',
            '@media (max-width: 700px) { .unverified-action-overlay { padding: 8px; } .unverified-action-dialog { width: 100%; max-height: 96vh; } .unverified-action-dialog.is-object-check { width: 100%; height: 96vh; } }',
            '@media (prefers-reduced-motion: reduce) { .unverified-action-progress__bar { transition: none; } .unverified-action-progress.is-indeterminate .unverified-action-progress__bar { animation-duration: 2s; } }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function selectedEntries(form) {
        return Array.prototype.slice.call(form.querySelectorAll('.unverified-select:checked')).map(function (box) {
            var label = box.getAttribute('aria-label') || '';
            label = label.replace(/^Select\s+/i, '').trim();
            return { token: box.value, name: label || box.value, box: box };
        });
    }

    function actionTitle(action) {
        if (action === 'move') return 'Moving unverified files';
        if (action === 'import') return 'Importing unverified files';
        if (action === 'delete') return 'Deleting unverified files';
        return 'Processing unverified files';
    }

    function createOverlay(title, objectCheck) {
        installOverlayStyle();
        var overlay = document.createElement('div');
        overlay.className = 'unverified-action-overlay';
        overlay.innerHTML = ''
            + '<section class="unverified-action-dialog' + (objectCheck ? ' is-object-check' : '') + '" role="dialog" aria-modal="true" aria-labelledby="unverified-action-title">'
            + '<header class="unverified-action-dialog__header"><div><h2 id="unverified-action-title"></h2><p data-overlay-summary></p></div></header>'
            + '<div class="unverified-action-dialog__body">'
            + '<div class="unverified-action-progress" data-overlay-progress><div class="unverified-action-progress__bar" data-overlay-bar></div></div>'
            + '<div class="unverified-action-current" data-overlay-current role="status" aria-live="polite"></div>'
            + '<ol class="unverified-action-log" data-overlay-log></ol>'
            + '<iframe class="unverified-action-frame" data-overlay-frame title="Queued package object check results"></iframe>'
            + '</div>'
            + '<footer class="unverified-action-dialog__footer">'
            + '<button type="button" class="button secondary" data-overlay-stop>Stop after current file</button>'
            + '<button type="button" class="button" data-overlay-close disabled>Close and refresh</button>'
            + '</footer></section>';
        overlay.querySelector('#unverified-action-title').textContent = title;
        document.body.appendChild(overlay);
        document.body.classList.add('unverified-action-overlay-open');

        var closeButton = overlay.querySelector('[data-overlay-close]');
        var stopButton = overlay.querySelector('[data-overlay-stop]');
        var closed = false;
        function remove() {
            if (closed) return;
            closed = true;
            overlay.remove();
            document.body.classList.remove('unverified-action-overlay-open');
        }
        return {
            root: overlay,
            summary: overlay.querySelector('[data-overlay-summary]'),
            progress: overlay.querySelector('[data-overlay-progress]'),
            bar: overlay.querySelector('[data-overlay-bar]'),
            current: overlay.querySelector('[data-overlay-current]'),
            log: overlay.querySelector('[data-overlay-log]'),
            frame: overlay.querySelector('[data-overlay-frame]'),
            closeButton: closeButton,
            stopButton: stopButton,
            remove: remove,
            isClosed: function () { return closed; }
        };
    }

    function appendLog(overlay, message, tone) {
        var item = document.createElement('li');
        item.className = tone === 'success' ? 'is-success' : (tone === 'error' ? 'is-error' : 'is-info');
        item.textContent = message;
        overlay.log.appendChild(item);
        overlay.log.scrollTop = overlay.log.scrollHeight;
    }

    function removeCompletedRow(entry) {
        var row = entry.box.closest('tr');
        if (!row) return;
        var note = row.nextElementSibling;
        if (note && note.classList.contains('unverified-rejection-row')) note.remove();
        row.remove();
    }

    async function postAction(form, action, entry) {
        var data = new FormData();
        var csrf = form.querySelector('input[name="csrf"]');
        var target = form.querySelector('[name="target_game_id"]');
        var override = form.querySelector('[name="allow_profile_override"]');
        data.append('csrf', csrf ? csrf.value : '');
        data.append('action', action);
        data.append('token', entry.token);
        if (target) data.append('target_game_id', target.value || '0');
        if (override && override.checked) data.append('allow_profile_override', '1');

        var response = await fetch('unverified-files-action.php', {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        var text = await response.text();
        var payload;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            throw new Error('The server returned an invalid progress response.');
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'The selected action failed.');
        }
        return payload;
    }

    async function runBatch(form, action, entries) {
        var overlay = createOverlay(actionTitle(action), false);
        var stopped = false;
        var successes = 0;
        var failures = 0;
        overlay.summary.textContent = entries.length + ' selected file(s).';
        overlay.stopButton.addEventListener('click', function () {
            stopped = true;
            overlay.stopButton.disabled = true;
            overlay.stopButton.textContent = 'Stopping after current file…';
        });
        overlay.closeButton.addEventListener('click', function () {
            window.location.reload();
        });

        form.setAttribute('aria-busy', 'true');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select')).forEach(function (control) {
            control.disabled = true;
        });

        for (var index = 0; index < entries.length; index++) {
            if (stopped) break;
            var entry = entries[index];
            overlay.current.textContent = (index + 1) + ' of ' + entries.length + ': ' + entry.name;
            try {
                var result = await postAction(form, action, entry);
                successes++;
                appendLog(overlay, result.message || entry.name + ': complete', 'success');
                removeCompletedRow(entry);
            } catch (error) {
                failures++;
                appendLog(overlay, entry.name + ': ' + (error.message || 'failed'), 'error');
            }
            overlay.bar.style.width = Math.round(((index + 1) / entries.length) * 100) + '%';
        }

        var processed = successes + failures;
        overlay.current.textContent = stopped
            ? 'Stopped after ' + processed + ' of ' + entries.length + ' file(s).'
            : 'Completed ' + processed + ' of ' + entries.length + ' file(s).';
        overlay.summary.textContent = successes + ' succeeded, ' + failures + ' failed' + (stopped ? ', remaining files were not processed.' : '.');
        overlay.stopButton.hidden = true;
        overlay.closeButton.disabled = false;
        overlay.closeButton.focus();
    }

    function makeProgressToken() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        var random = Math.random().toString(36).slice(2);
        return 'uvoc' + Date.now().toString(36) + random;
    }

    function objectCheckCurrentText(state, entries) {
        var index = Number.isInteger(state.current_index) ? state.current_index : parseInt(state.current_index, 10);
        var name = index >= 0 && index < entries.length ? entries[index].name : '';
        var message = state.message || 'Waiting for Object Check progress…';
        return name ? name + ' — ' + message : message;
    }

    function startObjectCheckPolling(overlay, progressToken, entries) {
        var stopped = false;
        var timer = null;
        var lastKey = '';
        var staleWarned = false;
        var requestErrors = 0;

        function stop() {
            stopped = true;
            if (timer !== null) window.clearTimeout(timer);
        }

        async function poll() {
            if (stopped || overlay.isClosed()) return;
            try {
                var response = await fetch('unverified-object-check.php?progress=' + encodeURIComponent(progressToken) + '&_=' + Date.now(), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                var state = await response.json();
                if (!response.ok) throw new Error(state.message || 'Progress request failed.');

                requestErrors = 0;
                var percent = Math.max(0, Math.min(100, parseInt(state.percent, 10) || 0));
                overlay.progress.classList.remove('is-indeterminate');
                overlay.bar.style.width = percent + '%';
                overlay.current.textContent = objectCheckCurrentText(state, entries);
                overlay.summary.textContent = (parseInt(state.done, 10) || 0) + ' of ' + (parseInt(state.total, 10) || entries.length) + ' file(s) completed.';

                var key = String(state.stage || '') + '|' + String(state.message || '') + '|' + String(state.current_index || 0);
                if (key !== lastKey && state.stage !== 'waiting') {
                    appendLog(overlay, objectCheckCurrentText(state, entries), state.stage === 'failed' || state.stage === 'file_error' ? 'error' : (state.stage === 'file_complete' || state.stage === 'done' ? 'success' : 'info'));
                    lastKey = key;
                }

                var updatedAt = Number(state.updated_at || 0);
                var age = updatedAt > 0 ? Math.max(0, Math.floor(Date.now() / 1000 - updatedAt)) : 0;
                if (age >= 15) {
                    overlay.current.textContent = objectCheckCurrentText(state, entries) + ' (no new server update for ' + age + ' seconds)';
                    if (!staleWarned) {
                        appendLog(overlay, 'The current package reader has not reported a new stage for 15 seconds. It may still be processing a large or damaged table.', 'error');
                        staleWarned = true;
                    }
                } else {
                    staleWarned = false;
                }

                if (state.stage === 'failed') {
                    overlay.summary.textContent = 'Object Check failed.';
                    overlay.closeButton.disabled = false;
                    stop();
                    return;
                }
                if (state.stage === 'done') {
                    overlay.bar.style.width = '100%';
                }
            } catch (error) {
                requestErrors++;
                if (requestErrors === 1 || requestErrors % 10 === 0) {
                    appendLog(overlay, 'Progress update unavailable: ' + (error.message || 'request failed'), 'error');
                }
            }
            timer = window.setTimeout(poll, 750);
        }

        poll();
        return stop;
    }

    function openObjectCheck(form, entries) {
        var overlay = createOverlay('Queued Package Object Check', true);
        var progressToken = makeProgressToken();
        var loaded = false;
        overlay.summary.textContent = 'Starting check for ' + entries.length + ' selected file(s).';
        overlay.current.textContent = 'Starting queued package Object Check…';
        overlay.progress.classList.add('is-indeterminate');
        overlay.stopButton.hidden = true;
        overlay.closeButton.textContent = 'Close';
        overlay.closeButton.disabled = false;
        appendLog(overlay, 'Object Check request started.', 'info');

        var stopPolling = startObjectCheckPolling(overlay, progressToken, entries);
        overlay.closeButton.addEventListener('click', function () {
            stopPolling();
            overlay.remove();
        });

        var query = new URLSearchParams();
        entries.forEach(function (entry) { query.append('tokens[]', entry.token); });
        query.set('progress_token', progressToken);

        overlay.frame.addEventListener('load', function () {
            if (loaded) return;
            try {
                if (overlay.frame.contentWindow && overlay.frame.contentWindow.location.href === 'about:blank') return;
            } catch (error) {
                // The route is same-origin; continue if the browser temporarily hides the URL.
            }
            loaded = true;
            stopPolling();
            overlay.progress.classList.remove('is-indeterminate');
            overlay.bar.style.width = '100%';
            overlay.current.textContent = 'Object Check complete. Results are shown below.';
            overlay.summary.textContent = entries.length + ' selected file(s) inspected.';
            appendLog(overlay, 'Object Check results finished loading.', 'success');
            overlay.frame.classList.add('is-ready');
            overlay.closeButton.disabled = false;
            try {
                var frameDocument = overlay.frame.contentDocument;
                if (frameDocument) {
                    frameDocument.querySelectorAll('button[onclick*="window.close"]').forEach(function (button) {
                        button.hidden = true;
                    });
                }
            } catch (error) {
                // The result remains usable if same-origin frame access is unavailable.
            }
            overlay.closeButton.focus();
        });
        overlay.frame.src = 'unverified-object-check.php?' + query.toString();
    }

    function initUnverifiedActions() {
        var form = document.getElementById('unverified-bulk-form');
        if (!form || form.dataset.progressOverlayBound === '1') return;
        form.dataset.progressOverlayBound = '1';

        document.addEventListener('submit', function (event) {
            if (event.target !== form) return;
            var submitter = event.submitter || document.activeElement;
            var action = submitter && submitter.name === 'action' ? submitter.value : '';
            if (!['move', 'import', 'delete'].includes(action)) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            var entries = selectedEntries(form);
            if (!entries.length) {
                window.alert('Select at least one queued file first.');
                return;
            }
            var target = form.querySelector('[name="target_game_id"]');
            if ((action === 'move' || action === 'import') && (!target || !target.value)) {
                window.alert('Choose a target game first.');
                if (target) target.focus();
                return;
            }
            if (action === 'delete' && !window.confirm('Delete ' + entries.length + ' selected queued file(s) and their queue notes permanently?')) {
                return;
            }
            runBatch(form, action, entries).catch(function (error) {
                window.alert(error.message || 'The bulk action could not be started.');
            });
        }, true);

        document.addEventListener('click', function (event) {
            var button = event.target.closest('#unverified-object-check');
            if (!button) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            var entries = selectedEntries(form);
            if (!entries.length) {
                window.alert('Select at least one queued file first.');
                return;
            }
            openObjectCheck(form, entries);
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUnverifiedActions, { once: true });
    } else {
        initUnverifiedActions();
    }
})();
