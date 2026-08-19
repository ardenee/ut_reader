(function () {
    'use strict';

    function addStyle() {
        if (document.getElementById('admin-all-matching-style')) return;
        var style = document.createElement('style');
        style.id = 'admin-all-matching-style';
        style.textContent = [
            '.admin-all-matching{display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px 9px;border:1px solid var(--line2);border-radius:8px;background:rgba(255,255,255,.025)}',
            '.admin-all-matching strong{white-space:nowrap}',
            '.admin-all-matching select{min-width:170px}',
            '.admin-all-matching-status{color:var(--muted);font-size:12px}',
            '.admin-all-matching.is-busy{opacity:.7;pointer-events:none}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function csrfFrom(form) {
        var field = form ? form.querySelector('input[name="csrf"]') : null;
        return field ? field.value : '';
    }

    function queryValue(name, fallback) {
        var value = new URL(window.location.href).searchParams.get(name);
        return value === null ? fallback : value;
    }

    function errorMessage(payload, fallback) {
        if (payload && payload.error && typeof payload.error === 'object' && payload.error.message) {
            return String(payload.error.message);
        }
        if (payload && typeof payload.error === 'string') return payload.error;
        if (payload && payload.message) return String(payload.message);
        return fallback;
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
            throw new Error('The server returned an invalid bulk-action response (HTTP ' + response.status + ').');
        }
        if (!response.ok) {
            throw new Error(errorMessage(payload, 'The bulk action failed.'));
        }
        return payload && payload.data ? payload.data : payload;
    }

    function setBusy(group, busy, status) {
        group.classList.toggle('is-busy', busy);
        var output = group.querySelector('.admin-all-matching-status');
        if (output) output.textContent = status || '';
    }

    function confirmAll(action, noun, count) {
        var amount = count > 0 ? count.toLocaleString() + ' matching ' + noun : 'every matching ' + noun;
        if (action === 'delete') {
            return window.confirm('Permanently delete ' + amount + '? This is not limited to the visible page.');
        }
        return window.confirm('Apply ' + action + ' to ' + amount + '? This is not limited to the visible page.');
    }

    function unverifiedTotal() {
        var node = document.querySelector('.uv-summary .stat:nth-child(2) h2');
        if (!node) return 0;
        return parseInt(String(node.textContent || '').replace(/[^0-9]/g, ''), 10) || 0;
    }

    function initUnverified() {
        var form = document.getElementById('unverified-bulk-form');
        var actions = form ? form.querySelector('.uv-actions') : null;
        if (!form || !actions || document.getElementById('unverified-all-matching-controls')) return;

        var total = unverifiedTotal();
        var group = document.createElement('span');
        group.id = 'unverified-all-matching-controls';
        group.className = 'admin-all-matching';
        group.innerHTML = ''
            + '<strong>All matching</strong>'
            + '<select data-all-action aria-label="Action for all matching unverified files">'
            + '<option value="import">Import</option><option value="move">Move queue</option><option value="delete">Delete</option>'
            + '</select>'
            + '<button type="button" data-apply-all>Apply to ' + (total > 0 ? total.toLocaleString() : 'all') + '</button>'
            + '<span class="admin-all-matching-status" aria-live="polite"></span>';
        actions.appendChild(group);

        group.querySelector('[data-apply-all]').addEventListener('click', async function () {
            var action = group.querySelector('[data-all-action]').value;
            var target = form.querySelector('[name="target_game_id"]');
            var targetGameId = target ? parseInt(target.value || '0', 10) || 0 : 0;
            if ((action === 'import' || action === 'move') && targetGameId === 0) {
                window.alert('Choose a target game first.');
                if (target) target.focus();
                return;
            }
            if (!confirmAll(action, 'unverified file(s)', total)) return;

            var override = form.querySelector('[name="allow_profile_override"]');
            var body = {
                action: action,
                target_game_id: targetGameId,
                allow_profile_override: !!(override && override.checked),
                filters: {
                    source_game_id: parseInt(queryValue('source_game_id', '0'), 10) || 0,
                    extension: queryValue('extension', ''),
                    engine: queryValue('engine', ''),
                    version: queryValue('version', ''),
                    licensee: queryValue('licensee', '')
                }
            };

            setBusy(group, true, 'Queuing durable background work…');
            try {
                var result = await postJson('api/v1/unverified-bulk.php', csrfFrom(form), body);
                setBusy(group, false, result.message || 'Bulk job queued.');
                window.alert(result.message || 'Bulk job queued.');
                if (result.queue) {
                    window.location.href = 'background-jobs.php?queue=' + encodeURIComponent(result.queue);
                }
            } catch (error) {
                setBusy(group, false, error.message || 'Bulk action failed.');
                window.alert(error.message || 'Bulk action failed.');
            }
        });
    }

    function actionFormFor(selector) {
        var actions = document.querySelector(selector);
        return actions ? actions.closest('form') : null;
    }

    function addLogMatchingControl(options) {
        var form = actionFormFor(options.actionsSelector);
        var actions = form ? form.querySelector(options.actionsSelector) : null;
        if (!form || !actions || document.getElementById(options.id)) return;

        var actionSelect = form.querySelector('select[name="action"]');
        var note = form.querySelector('[name="resolution_note"]');
        if (!actionSelect) return;

        var group = document.createElement('span');
        group.id = options.id;
        group.className = 'admin-all-matching';
        group.innerHTML = '<strong>All matching filters</strong>'
            + '<button type="button" data-apply-all>Apply action to all</button>'
            + '<span class="admin-all-matching-status" aria-live="polite"></span>';
        actions.appendChild(group);

        group.querySelector('[data-apply-all]').addEventListener('click', async function () {
            var action = actionSelect.value;
            if (!action) {
                window.alert('Choose an action first.');
                actionSelect.focus();
                return;
            }
            if (!confirmAll(action, options.noun, 0)) return;

            setBusy(group, true, 'Applying to all matching records…');
            try {
                var result = await postJson(options.url, csrfFrom(form), {
                    action: action,
                    resolution_note: note ? note.value : '',
                    filters: options.filters()
                });
                setBusy(group, false, result.message || 'Bulk action complete.');
                window.alert(result.message || 'Bulk action complete.');
                window.location.reload();
            } catch (error) {
                setBusy(group, false, error.message || 'Bulk action failed.');
                window.alert(error.message || 'Bulk action failed.');
            }
        });
    }

    function initSystemErrors() {
        addLogMatchingControl({
            id: 'system-errors-all-matching-controls',
            actionsSelector: '.system-error-actions',
            url: 'api/v1/system-errors-bulk.php',
            noun: 'System Error record(s)',
            filters: function () {
                return {
                    status: queryValue('status', 'open'),
                    severity: queryValue('severity', 'all'),
                    source: queryValue('source', 'all'),
                    q: queryValue('q', '')
                };
            }
        });
    }

    function initUploadIssues() {
        addLogMatchingControl({
            id: 'upload-issues-all-matching-controls',
            actionsSelector: '.upload-issue-actions',
            url: 'api/v1/upload-issues-bulk.php',
            noun: 'Upload Issue record(s)',
            filters: function () {
                return {
                    status: queryValue('status', 'open'),
                    q: queryValue('q', '')
                };
            }
        });
    }

    function init() {
        addStyle();
        var page = (window.location.pathname.split('/').pop() || '').toLowerCase();
        if (page === 'unverified-files.php') initUnverified();
        if (page === 'system-errors.php') initSystemErrors();
        if (page === 'upload-issues.php') initUploadIssues();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
