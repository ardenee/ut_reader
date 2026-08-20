(function () {
    'use strict';

    function errorMessage(payload, fallback) {
        if (payload && payload.error && typeof payload.error === 'object' && payload.error.message) {
            return String(payload.error.message);
        }
        if (payload && typeof payload.error === 'string') return payload.error;
        if (payload && payload.message) return String(payload.message);
        return fallback;
    }

    async function getJson(url) {
        var response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });
        var payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response (HTTP ' + response.status + ').');
        }
        if (!response.ok) throw new Error(errorMessage(payload, 'Could not load file destinations.'));
        return payload && payload.data ? payload.data : payload;
    }

    async function postJson(url, csrf, body) {
        var response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify(body)
        });
        var payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response (HTTP ' + response.status + ').');
        }
        if (!response.ok) throw new Error(errorMessage(payload, 'Could not queue file reassignment.'));
        return payload && payload.data ? payload.data : payload;
    }

    function queryInt(name) {
        var value = new URL(window.location.href).searchParams.get(name);
        return parseInt(value || '0', 10) || 0;
    }

    function queryText(name) {
        return new URL(window.location.href).searchParams.get(name) || '';
    }

    function matchingTotal() {
        var paragraphs = document.querySelectorAll('.ui-section__header p');
        for (var i = 0; i < paragraphs.length; i++) {
            var text = String(paragraphs[i].textContent || '');
            var match = text.match(/([0-9][0-9,]*)\s+matching files/i);
            if (match) return parseInt(match[1].replace(/,/g, ''), 10) || 0;
        }
        return 0;
    }

    function fileIdForRow(row) {
        var link = row.querySelector('a[href*="file-info.php?id="],a[href*="file-examine.php?id="]');
        if (!link) return 0;
        try {
            return parseInt(new URL(link.href, window.location.href).searchParams.get('id') || '0', 10) || 0;
        } catch (error) {
            return 0;
        }
    }

    function addStyle() {
        if (document.getElementById('game-file-reassignment-style')) return;
        var style = document.createElement('style');
        style.id = 'game-file-reassignment-style';
        style.textContent = [
            '.game-file-reassign-toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:12px 0;padding:10px 12px;border:1px solid var(--line2);border-radius:10px;background:rgba(255,255,255,.025)}',
            '.game-file-reassign-toolbar .game-file-reassign-title{font-weight:700;margin-right:2px}',
            '.game-file-reassign-toolbar label{display:inline-flex;align-items:center;gap:6px;margin:0}',
            '.game-file-reassign-toolbar select{min-width:230px}',
            '.game-file-reassign-status{flex:1 1 280px;color:var(--muted);font-size:12px}',
            '.game-file-reassign-toolbar.is-busy{opacity:.72;pointer-events:none}',
            '.game-file-reassign-select{width:18px;height:18px}',
            '#game-files-table .game-file-reassign-col{width:42px!important;min-width:42px!important;text-align:center;white-space:nowrap}',
            '.game-file-reassign-note{width:100%;font-size:12px;color:var(--muted)}',
            '@media(max-width:800px){.game-file-reassign-toolbar select{min-width:180px}.game-file-reassign-status{flex-basis:100%}}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function currentFilters() {
        return {
            file_filter: queryText('file_filter'),
            dep_filter: queryText('dep_filter'),
            type_filter: queryText('type_filter'),
            compression_filter: queryText('compression_filter')
        };
    }

    function confirmMove(scope, count, sourceName, targetName, targetId) {
        var amount = scope === 'matching'
            ? (count > 0 ? count.toLocaleString() + ' matching file(s)' : 'all matching files')
            : count.toLocaleString() + ' selected file(s)';
        if (targetId === 0) {
            return window.confirm(
                'Return ' + amount + ' from ' + sourceName + ' to Unverified Files?\n\n'
                + 'The package bytes are preserved and can be assigned again later.'
            );
        }
        return window.confirm(
            'Move ' + amount + ' from ' + sourceName + ' to ' + targetName + '?\n\n'
            + 'Each destination copy is verified first. A source file is removed only after the destination is confirmed.'
        );
    }

    function setBusy(toolbar, busy, message) {
        toolbar.classList.toggle('is-busy', busy);
        var output = toolbar.querySelector('.game-file-reassign-status');
        if (output) output.textContent = message || '';
    }

    async function init() {
        var table = document.getElementById('game-files-table');
        if (!table || document.getElementById('game-file-reassign-toolbar')) return;

        var csrfInput = document.querySelector('form[action="file-maintenance.php"] input[name="csrf"]');
        if (!csrfInput || !csrfInput.value) return; // Non-admin page.

        var sourceGameId = queryInt('id');
        if (sourceGameId < 1) return;

        addStyle();

        var headerRow = table.querySelector('thead tr');
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        if (!headerRow || !rows.length) return;

        var selectAllHead = document.createElement('th');
        selectAllHead.className = 'game-file-reassign-col';
        selectAllHead.scope = 'col';
        selectAllHead.innerHTML = '<input type="checkbox" id="game-file-reassign-page" class="game-file-reassign-select" aria-label="Select all files on this page">';
        headerRow.insertBefore(selectAllHead, headerRow.firstChild);

        var checkboxes = [];
        rows.forEach(function (row) {
            var fileId = fileIdForRow(row);
            var cell = document.createElement('td');
            cell.className = 'game-file-reassign-col';
            if (fileId > 0) {
                cell.innerHTML = '<input type="checkbox" class="game-file-reassign-check game-file-reassign-select" value="'
                    + fileId + '" aria-label="Select file #' + fileId + '">';
                checkboxes.push(cell.firstChild);
            }
            row.insertBefore(cell, row.firstChild);
        });

        var toolbar = document.createElement('div');
        toolbar.id = 'game-file-reassign-toolbar';
        toolbar.className = 'game-file-reassign-toolbar';
        toolbar.innerHTML = ''
            + '<span class="game-file-reassign-title">Move files</span>'
            + '<span class="game-file-reassign-selection">0 selected</span>'
            + '<label>Destination <select id="game-file-reassign-target" disabled><option>Loading…</option></select></label>'
            + '<button type="button" id="game-file-reassign-selected" disabled>Move selected</button>'
            + '<button type="button" id="game-file-reassign-matching" disabled>Move all matching</button>'
            + '<span class="game-file-reassign-status" aria-live="polite">Loading destinations…</span>'
            + '<span class="game-file-reassign-note">Move is separate from the × action, which permanently deletes a package from storage and the catalog.</span>';

        var firstPagination = table.closest('.ui-table-region');
        var sectionBody = table.closest('.ui-section__body');
        if (sectionBody) {
            var tableRegion = table.closest('.ui-table-region');
            sectionBody.insertBefore(toolbar, tableRegion || firstPagination || table);
        } else {
            table.parentNode.insertBefore(toolbar, table);
        }

        var selectPage = document.getElementById('game-file-reassign-page');
        var selectionText = toolbar.querySelector('.game-file-reassign-selection');
        var targetSelect = document.getElementById('game-file-reassign-target');
        var selectedButton = document.getElementById('game-file-reassign-selected');
        var matchingButton = document.getElementById('game-file-reassign-matching');
        var total = matchingTotal();
        var sourceName = 'this game';
        var destinationNames = {};

        function selectedIds() {
            return checkboxes.filter(function (box) { return box.checked; }).map(function (box) {
                return parseInt(box.value, 10) || 0;
            }).filter(function (id) { return id > 0; });
        }

        function updateSelection() {
            var count = selectedIds().length;
            selectionText.textContent = count.toLocaleString() + ' selected';
            selectedButton.disabled = count < 1 || targetSelect.disabled;
            if (selectPage) {
                selectPage.checked = count > 0 && count === checkboxes.length;
                selectPage.indeterminate = count > 0 && count < checkboxes.length;
            }
        }

        checkboxes.forEach(function (box) { box.addEventListener('change', updateSelection); });
        selectPage.addEventListener('change', function () {
            checkboxes.forEach(function (box) { box.checked = selectPage.checked; });
            updateSelection();
        });

        try {
            var setup = await getJson('api/v1/game-files-reassign.php?source_game_id=' + encodeURIComponent(sourceGameId));
            sourceName = setup.source_game && setup.source_game.name ? String(setup.source_game.name) : sourceName;
            targetSelect.innerHTML = '';
            (setup.destinations || []).forEach(function (destination) {
                var id = parseInt(destination.id, 10) || 0;
                var name = String(destination.name || (id === 0 ? 'Unverified Files' : ('Game #' + id)));
                destinationNames[id] = name;
                var option = document.createElement('option');
                option.value = String(id);
                option.textContent = name + (destination.engine_key ? ' (' + destination.engine_key + ')' : '');
                targetSelect.appendChild(option);
            });
            targetSelect.disabled = targetSelect.options.length < 1;
            matchingButton.disabled = targetSelect.disabled || total === 0;
            setBusy(toolbar, false, total > 0
                ? total.toLocaleString() + ' file(s) match the current filters.'
                : 'Choose selected files to move.');
            updateSelection();
        } catch (error) {
            targetSelect.innerHTML = '<option>Unavailable</option>';
            targetSelect.disabled = true;
            selectedButton.disabled = true;
            matchingButton.disabled = true;
            setBusy(toolbar, false, error.message || 'Could not load destinations.');
            return;
        }

        async function queueMove(scope) {
            var targetId = parseInt(targetSelect.value || '-1', 10);
            if (targetId < 0) return;
            var ids = scope === 'selected' ? selectedIds() : [];
            var count = scope === 'selected' ? ids.length : total;
            if (scope === 'selected' && ids.length < 1) {
                window.alert('Select at least one file first.');
                return;
            }
            var targetName = destinationNames[targetId] || (targetId === 0 ? 'Unverified Files' : ('game #' + targetId));
            if (!confirmMove(scope, count, sourceName, targetName, targetId)) return;

            var body = {
                scope: scope,
                source_game_id: sourceGameId,
                target_game_id: targetId
            };
            if (scope === 'selected') body.file_ids = ids;
            else body.filters = currentFilters();

            setBusy(toolbar, true, 'Queuing durable file reassignment…');
            try {
                var result = await postJson('api/v1/game-files-reassign.php', csrfInput.value, body);
                setBusy(toolbar, false, result.message || 'File reassignment queued.');
                window.alert(result.message || 'File reassignment queued.');
                if (result.queue) {
                    window.location.href = 'background-jobs.php?queue=' + encodeURIComponent(result.queue);
                }
            } catch (error) {
                setBusy(toolbar, false, error.message || 'Could not queue file reassignment.');
                window.alert(error.message || 'Could not queue file reassignment.');
            }
        }

        selectedButton.addEventListener('click', function () { queueMove('selected'); });
        matchingButton.addEventListener('click', function () { queueMove('matching'); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
