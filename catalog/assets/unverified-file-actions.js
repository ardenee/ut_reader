(function () {
    'use strict';

    var styleInstalled = false;

    function installStyle() {
        if (styleInstalled) return;
        styleInstalled = true;

        var style = document.createElement('style');
        style.textContent = [
            'body.unverified-action-overlay-open { overflow:hidden; }',
            '.unverified-action-overlay { position:fixed; inset:0; z-index:30000; display:grid; place-items:center; padding:20px; background:rgba(4,8,16,.82); backdrop-filter:blur(4px); }',
            '.unverified-action-dialog { width:min(800px,96vw); max-height:90vh; display:flex; flex-direction:column; overflow:hidden; border:1px solid var(--line2); border-radius:12px; background:var(--panel,#111827); box-shadow:0 28px 80px rgba(0,0,0,.55); }',
            '.unverified-action-dialog [hidden] { display:none !important; }',
            '.unverified-action-dialog__header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:18px 20px 12px; border-bottom:1px solid var(--line2); }',
            '.unverified-action-dialog__header h2 { margin:0 0 4px; }',
            '.unverified-action-dialog__header p { margin:0; color:var(--muted); }',
            '.unverified-action-dialog__body { min-height:0; display:flex; flex:1; flex-direction:column; gap:12px; padding:18px 20px; overflow:auto; }',
            '.unverified-action-progress-line { display:grid; grid-template-columns:auto auto minmax(0,1fr); gap:14px; align-items:center; min-height:24px; }',
            '.unverified-action-progress-line strong { white-space:nowrap; }',
            '.unverified-action-progress-line [data-status] { min-width:0; overflow-wrap:anywhere; color:var(--muted); }',
            '.unverified-action-progress-pair { display:grid; grid-template-columns:1fr 1fr; gap:10px; }',
            '.unverified-action-progress-wrap { display:flex; flex-direction:column; gap:4px; }',
            '.unverified-action-progress-wrap small { color:var(--muted); }',
            '.unverified-action-progress { height:12px; overflow:hidden; border:1px solid var(--line2); border-radius:999px; background:rgba(255,255,255,.055); }',
            '.unverified-action-progress__bar { width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,var(--blue),#78a9ff); transition:width .2s ease; }',
            '.unverified-action-current { min-height:42px; padding:10px 12px; border:1px solid var(--line2); border-radius:8px; background:rgba(255,255,255,.025); overflow-wrap:anywhere; }',
            '.unverified-action-log { min-height:120px; max-height:340px; margin:0; padding:10px 10px 10px 34px; overflow:auto; border:1px solid var(--line2); border-radius:8px; background:rgba(0,0,0,.16); }',
            '.unverified-action-log li { margin:4px 0; overflow-wrap:anywhere; }',
            '.unverified-action-log li.is-success { color:#b8f3cb; }',
            '.unverified-action-log li.is-error { color:#ffb5b5; }',
            '.unverified-action-log li.is-info { color:var(--muted); }',
            '.unverified-action-dialog__footer { display:flex; align-items:center; justify-content:flex-end; gap:8px; padding:12px 20px 18px; border-top:1px solid var(--line2); }',
            '.unverified-select-all-label { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }',
            '.uv-table { min-width:1250px !important; }',
            '.uv-table td:nth-child(4), .uv-table th:nth-child(4), .uv-table td:nth-child(6), .uv-table th:nth-child(6), .uv-table td:nth-child(7), .uv-table th:nth-child(7) { white-space:nowrap; }',
            '.uv-table td:nth-child(2) small, .uv-file > small, .uv-database-counts { display:block; margin-top:3px; }',
            '.uv-file > strong, .uv-file > a.uv-file-link { display:block; }',
            '.uv-file a.uv-package-link { white-space:nowrap; }',
            '.uv-id-list a { display:grid !important; grid-template-columns:minmax(0,1fr) auto; column-gap:10px; align-items:baseline; }',
            '.uv-id-list a strong { grid-column:1; grid-row:1; min-width:0; }',
            '.uv-id-list a small { grid-column:2; grid-row:1; margin:0; text-align:right; white-space:nowrap; font-size:11px; }',
            '.uv-id-list a span { grid-column:1 / -1; grid-row:2; }',
            '.uv-match { grid-template-columns:minmax(145px,1fr) auto !important; }',
            '.uv-match-count { min-width:68px; white-space:nowrap; }',
            '.uv-match-count strong, .uv-match-count small { display:block; }',
            '.uv-note-row td { padding-top:0; }',
            '.uv-note-details { border-left:3px solid #f6c453; padding:6px 10px; }',
            '.uv-note-details summary { cursor:pointer; font-weight:700; color:var(--text); }',
            '.uv-note-details__content { margin-top:8px; white-space:normal; }',
            '@media (max-width:700px) { .unverified-action-overlay { padding:8px; } .unverified-action-dialog { width:100%; max-height:96vh; } .unverified-action-progress-line { grid-template-columns:1fr 1fr; } .unverified-action-progress-line [data-status] { grid-column:1 / -1; } .unverified-action-progress-pair { grid-template-columns:1fr; } }',
            '@media (prefers-reduced-motion:reduce) { .unverified-action-progress__bar { transition:none; } }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function selectedEntries(form) {
        return Array.prototype.slice.call(form.querySelectorAll('.unverified-select:checked')).map(function (box) {
            var label = (box.getAttribute('aria-label') || '').replace(/^Select\s+/i, '').trim();
            return { token: box.value, name: label || box.value, box: box };
        });
    }

    function createOverlay(title) {
        installStyle();
        var root = document.createElement('div');
        root.className = 'unverified-action-overlay';
        root.innerHTML = ''
            + '<section class="unverified-action-dialog" role="dialog" aria-modal="true" aria-labelledby="unverified-action-title">'
            + '<header class="unverified-action-dialog__header"><div><h2 id="unverified-action-title"></h2><p data-summary></p></div></header>'
            + '<div class="unverified-action-dialog__body">'
            + '<div class="unverified-action-progress-line"><strong data-overall-text>Overall 0%</strong><strong data-file-text>File 0%</strong><span data-status>Waiting to start</span></div>'
            + '<div class="unverified-action-progress-pair">'
            + '<div class="unverified-action-progress-wrap"><small>Overall progress</small><div class="unverified-action-progress"><div class="unverified-action-progress__bar" data-overall-bar></div></div></div>'
            + '<div class="unverified-action-progress-wrap"><small>Current file progress</small><div class="unverified-action-progress"><div class="unverified-action-progress__bar" data-file-bar></div></div></div>'
            + '</div>'
            + '<div class="unverified-action-current" data-current role="status" aria-live="polite"></div>'
            + '<ol class="unverified-action-log" data-log></ol>'
            + '</div><footer class="unverified-action-dialog__footer">'
            + '<button type="button" class="button secondary" data-stop>Stop after current file</button>'
            + '<button type="button" class="button" data-close disabled>Close and refresh</button>'
            + '</footer></section>';
        root.querySelector('#unverified-action-title').textContent = title;
        document.body.appendChild(root);
        document.body.classList.add('unverified-action-overlay-open');

        return {
            summary: root.querySelector('[data-summary]'),
            overallText: root.querySelector('[data-overall-text]'),
            fileText: root.querySelector('[data-file-text]'),
            status: root.querySelector('[data-status]'),
            overallBar: root.querySelector('[data-overall-bar]'),
            fileBar: root.querySelector('[data-file-bar]'),
            current: root.querySelector('[data-current]'),
            log: root.querySelector('[data-log]'),
            stop: root.querySelector('[data-stop]'),
            close: root.querySelector('[data-close]')
        };
    }

    function appendLog(overlay, message, tone) {
        var item = document.createElement('li');
        item.className = tone === 'success' ? 'is-success' : (tone === 'error' ? 'is-error' : 'is-info');
        item.textContent = message;
        overlay.log.appendChild(item);
        overlay.log.scrollTop = overlay.log.scrollHeight;
    }

    function actionTitle(action) {
        if (action === 'move') return 'Moving unverified files';
        if (action === 'import') return 'Importing unverified files';
        return action === 'delete' ? 'Deleting unverified files' : 'Processing unverified files';
    }

    function removeCompletedRow(entry) {
        var row = entry.box.closest('tr');
        if (!row) return;
        var note = row.nextElementSibling;
        if (note && (note.classList.contains('uv-note-row') || note.classList.contains('unverified-rejection-row'))) note.remove();
        row.remove();
    }

    function compactServerText(text) {
        return String(text || '')
            .replace(/<script[\s\S]*?<\/script>/gi, ' ')
            .replace(/<style[\s\S]*?<\/style>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 700);
    }

    function invalidResponseMessage(response, text) {
        var status = 'HTTP ' + (response.status || 0) + (response.statusText ? ' ' + response.statusText : '');
        var body = compactServerText(text);
        var message = status + '; the server returned a non-JSON progress response';
        if ([502, 503, 504].indexOf(response.status) !== -1) {
            message += '. The web server or proxy timed out; refresh before retrying because the import may already have completed';
        } else if (response.status === 500) {
            message += '. The server stopped while processing the file';
        }
        message += body ? ': ' + body : '; the response body was empty';
        return message;
    }

    function clampPercent(value) {
        value = parseInt(value, 10);
        return isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
    }

    function makeProgressToken() {
        var random = '';
        if (window.crypto && window.crypto.getRandomValues) {
            var bytes = new Uint32Array(4);
            window.crypto.getRandomValues(bytes);
            random = Array.prototype.map.call(bytes, function (value) { return value.toString(16); }).join('');
        } else {
            random = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        }
        return 'uv_' + Date.now().toString(36) + '_' + random;
    }

    function delay(milliseconds) {
        return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
    }

    async function pollProgress(token, onProgress, isFinished) {
        while (!isFinished()) {
            try {
                var response = await fetch('unverified-files-action.php?progress=' + encodeURIComponent(token), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (response.ok) {
                    var state = await response.json();
                    if (state && typeof state === 'object') onProgress(state);
                }
            } catch (error) {
                // The action request remains authoritative; a missed poll should not fail the import.
            }
            await delay(250);
        }
    }

    async function postAction(form, action, entry, onProgress) {
        var data = new FormData();
        var csrf = form.querySelector('input[name="csrf"]');
        var target = form.querySelector('[name="target_game_id"]');
        var override = form.querySelector('[name="allow_profile_override"]');
        var progressToken = makeProgressToken();
        data.append('csrf', csrf ? csrf.value : '');
        data.append('action', action);
        data.append('token', entry.token);
        data.append('progress_token', progressToken);
        if (target) data.append('target_game_id', target.value || '0');
        if (override && override.checked) data.append('allow_profile_override', '1');

        var finished = false;
        var polling = pollProgress(progressToken, onProgress, function () { return finished; });
        var response;
        try {
            response = await fetch('unverified-files-action.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
        } catch (error) {
            finished = true;
            await polling;
            throw new Error('The connection ended before the server returned a result. Refresh before retrying because the import may have completed. ' + (error.message || 'Network error.'));
        }

        var text = await response.text();
        finished = true;
        await polling;
        var payload;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            throw new Error(invalidResponseMessage(response, text));
        }
        if (!response.ok || !payload.ok) {
            var message = payload.error || 'The selected action failed.';
            if (payload.request_id) message += ' Reference: ' + payload.request_id + '.';
            throw new Error(message);
        }
        return payload;
    }

    function updateProgressDisplay(overlay, fileIndex, fileCount, state) {
        var filePercent = clampPercent(state.percent);
        var overallPercent = Math.round(((fileIndex + (filePercent / 100)) / Math.max(1, fileCount)) * 100);
        overlay.overallText.textContent = 'Overall ' + overallPercent + '%';
        overlay.fileText.textContent = 'File ' + filePercent + '%';
        overlay.status.textContent = String(state.message || state.stage || 'Processing');
        overlay.overallBar.style.width = overallPercent + '%';
        overlay.fileBar.style.width = filePercent + '%';
    }

    async function runBatch(form, action, entries) {
        var overlay = createOverlay(actionTitle(action));
        var stopped = false;
        var successes = 0;
        var failures = 0;
        overlay.summary.textContent = entries.length + ' selected file(s).';
        overlay.stop.addEventListener('click', function () {
            stopped = true;
            overlay.stop.disabled = true;
            overlay.stop.textContent = 'Stopping after current file…';
        });
        overlay.close.addEventListener('click', function () { window.location.reload(); });
        form.setAttribute('aria-busy', 'true');
        Array.prototype.slice.call(form.querySelectorAll('button,input,select')).forEach(function (control) { control.disabled = true; });

        for (var index = 0; index < entries.length; index++) {
            if (stopped) break;
            var entry = entries[index];
            overlay.current.textContent = (index + 1) + ' of ' + entries.length + ': ' + entry.name;
            updateProgressDisplay(overlay, index, entries.length, { percent: 0, message: 'Starting ' + entry.name });
            try {
                var result = await postAction(form, action, entry, function (state) {
                    updateProgressDisplay(overlay, index, entries.length, state);
                });
                successes++;
                updateProgressDisplay(overlay, index, entries.length, { percent: 100, message: result.message || 'Complete' });
                appendLog(overlay, result.message || entry.name + ': complete', result.warning ? 'info' : 'success');
                removeCompletedRow(entry);
            } catch (error) {
                failures++;
                updateProgressDisplay(overlay, index, entries.length, { percent: 100, message: error.message || 'Failed' });
                appendLog(overlay, entry.name + ': ' + (error.message || 'failed'), 'error');
            }
        }

        var processed = successes + failures;
        var finalOverall = Math.round((processed / Math.max(1, entries.length)) * 100);
        overlay.overallText.textContent = 'Overall ' + finalOverall + '%';
        overlay.overallBar.style.width = finalOverall + '%';
        overlay.fileText.textContent = stopped ? 'File stopped' : 'File 100%';
        overlay.status.textContent = stopped ? 'Stopped after current file' : 'Batch complete';
        overlay.current.textContent = stopped
            ? 'Stopped after ' + processed + ' of ' + entries.length + ' file(s).'
            : 'Completed ' + processed + ' of ' + entries.length + ' file(s).';
        overlay.summary.textContent = successes + ' succeeded, ' + failures + ' failed' + (stopped ? ', remaining files were not processed.' : '.');
        overlay.stop.hidden = true;
        overlay.close.disabled = false;
        overlay.close.focus();
    }

    function addSelectAll(form) {
        var actions = form.querySelector('.uv-actions');
        if (!actions || document.getElementById('unverified-select-all')) return;

        var label = document.createElement('label');
        label.className = 'unverified-select-all-label';
        label.innerHTML = '<input id="unverified-select-all" type="checkbox"> Select all shown';
        actions.insertBefore(label, actions.firstChild);

        var selectAll = label.querySelector('input');
        var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('.unverified-select')); };
        var sync = function () {
            var current = boxes();
            var checked = current.filter(function (box) { return box.checked; }).length;
            selectAll.checked = current.length > 0 && checked === current.length;
            selectAll.indeterminate = checked > 0 && checked < current.length;
        };

        selectAll.addEventListener('change', function () {
            boxes().forEach(function (box) { box.checked = selectAll.checked; });
            sync();
        });
        form.addEventListener('change', function (event) {
            if (event.target.classList && event.target.classList.contains('unverified-select')) sync();
        });
        sync();
    }

    function fileIdFromDatabaseCell(cell) {
        var link = cell ? cell.querySelector('a[href*="?id="]') : null;
        if (!link) return 0;
        try {
            return parseInt(new URL(link.href, window.location.href).searchParams.get('id') || '0', 10) || 0;
        } catch (error) {
            var match = link.getAttribute('href').match(/[?&]id=(\d+)/);
            return match ? parseInt(match[1], 10) || 0 : 0;
        }
    }

    function wrapElement(element, href, title, className) {
        if (!element || element.closest('a')) return;
        var link = document.createElement('a');
        link.href = href;
        link.title = title;
        if (className) link.className = className;
        element.parentNode.insertBefore(link, element);
        link.appendChild(element);
    }

    function compactGameTarget(card) {
        var columns = card.children;
        if (columns.length < 2) return;
        var profile = columns[0].querySelector('small');
        if (profile) profile.remove();

        var countStrong = columns[1].querySelector('strong');
        var usedBySmall = columns[1].querySelector('small');
        if (countStrong) {
            var exact = countStrong.textContent.match(/\d+\s*\/\s*(\d+)\s+exact/i);
            var simple = countStrong.textContent.match(/(\d+)/);
            var packageReferences = exact ? parseInt(exact[1], 10) : (/no package references/i.test(countStrong.textContent) ? 0 : (simple ? parseInt(simple[1], 10) : 0));
            countStrong.textContent = 'PF: ' + packageReferences;
            countStrong.title = 'Package references: ' + packageReferences;
        }
        if (usedBySmall) {
            var used = usedBySmall.textContent.match(/(\d+)/);
            var usedBy = used ? parseInt(used[1], 10) : 0;
            usedBySmall.textContent = 'UB: ' + usedBy;
            usedBySmall.title = 'Used by ' + usedBy + ' verified file(s)';
        }
    }

    function collapseQueueNote(noteRow) {
        if (!noteRow || noteRow.dataset.collapsedNote === '1') return;
        var note = noteRow.querySelector('.uv-note');
        if (!note) return;
        noteRow.dataset.collapsedNote = '1';

        var heading = note.querySelector('strong');
        if (heading && heading.textContent.trim() === 'Queue note') heading.remove();
        while (note.firstChild && note.firstChild.nodeType === 1 && note.firstChild.tagName === 'BR') note.firstChild.remove();

        var details = document.createElement('details');
        details.className = 'uv-note-details';
        var summary = document.createElement('summary');
        summary.textContent = 'Queue note';
        var content = document.createElement('div');
        content.className = 'uv-note-details__content';
        while (note.firstChild) content.appendChild(note.firstChild);
        details.appendChild(summary);
        details.appendChild(content);

        var cell = document.createElement('td');
        cell.colSpan = 8;
        cell.appendChild(details);
        noteRow.innerHTML = '';
        noteRow.appendChild(cell);
    }

    function enhanceQueueTable(form) {
        var table = form.querySelector('.uv-table');
        if (!table) return;

        Array.prototype.slice.call(table.querySelectorAll('tbody > tr:not(.uv-note-row)')).forEach(function (row) {
            if (!row.cells || row.cells.length < 8) return;
            var fileCell = row.cells[2];
            var databaseCell = row.cells[4];
            var fileId = fileIdFromDatabaseCell(databaseCell);

            if (fileId > 0) {
                wrapElement(fileCell.querySelector(':scope > strong'), 'file-examine.php?id=' + fileId, 'Examine this file', 'uv-file-link');
                wrapElement(fileCell.querySelector('.mono'), 'file-info.php?id=' + fileId, 'Open file information', 'uv-package-link');
            }

            var counts = '';
            Array.prototype.slice.call(databaseCell.querySelectorAll('small')).some(function (small) {
                if (/N\/I\/E/i.test(small.textContent)) {
                    counts = small.textContent.trim().replace(/\s*N\/I\/E\s*$/i, '');
                    return true;
                }
                return false;
            });
            databaseCell.innerHTML = counts !== ''
                ? '<span class="mono uv-database-counts" title="Names / Imports / Exports">' + counts + '</span>'
                : '<span class="muted">Not indexed</span>';

            Array.prototype.slice.call(row.querySelectorAll('.uv-match')).forEach(compactGameTarget);
            var noteRow = row.nextElementSibling;
            if (noteRow && noteRow.classList.contains('uv-note-row')) collapseQueueNote(noteRow);
        });
    }

    function init() {
        var form = document.getElementById('unverified-bulk-form');
        if (!form || form.dataset.progressOverlayBound === '1') return;
        form.dataset.progressOverlayBound = '1';

        installStyle();
        addSelectAll(form);
        enhanceQueueTable(form);

        document.addEventListener('submit', function (event) {
            if (event.target !== form) return;
            var submitter = event.submitter || document.activeElement;
            var action = submitter && submitter.name === 'action' ? submitter.value : '';
            if (['move', 'import', 'delete'].indexOf(action) === -1) return;
            event.preventDefault();
            event.stopImmediatePropagation();

            var entries = selectedEntries(form);
            if (!entries.length) return window.alert('Select at least one queued file first.');
            var target = form.querySelector('[name="target_game_id"]');
            if ((action === 'move' || action === 'import') && (!target || !target.value)) {
                window.alert('Choose a target game first.');
                if (target) target.focus();
                return;
            }
            if (action === 'delete' && !window.confirm('Delete ' + entries.length + ' selected queued file(s) and their queue notes permanently?')) return;
            runBatch(form, action, entries).catch(function (error) {
                window.alert(error.message || 'The bulk action could not be started.');
            });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
