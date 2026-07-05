<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, private');
?>
(function () {
    'use strict';

    function makeToken() {
        var bytes = new Uint8Array(18);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes).map(function (value) {
                return value.toString(16).padStart(2, '0');
            }).join('');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function addStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '.catalog-maintenance-overlay{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(3,8,18,.72);backdrop-filter:blur(3px)}',
            '.catalog-maintenance-dialog{width:min(540px,100%);padding:24px;border:1px solid var(--line2);border-radius:14px;background:#111b2d;box-shadow:0 24px 70px rgba(0,0,0,.5)}',
            '.catalog-maintenance-dialog h2{margin:0 0 8px}.catalog-maintenance-dialog p{margin:0 0 16px}',
            '.catalog-maintenance-progress{height:14px;overflow:hidden;border:1px solid var(--line2);border-radius:999px;background:rgba(255,255,255,.05)}',
            '.catalog-maintenance-progress>span{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,#76a9ff,#9dc2ff);transition:width .18s linear}',
            '.catalog-maintenance-count{margin-top:9px;color:var(--muted);font-size:13px}',
            '.catalog-maintenance-loading{display:none;align-items:center;gap:10px;margin-top:16px;color:var(--text)}.catalog-maintenance-loading.is-visible{display:flex}',
            '.catalog-maintenance-spinner{width:17px;height:17px;border:3px solid rgba(157,194,255,.25);border-top-color:#9dc2ff;border-radius:50%;animation:catalog-maintenance-spin .8s linear infinite}',
            '@keyframes catalog-maintenance-spin{to{transform:rotate(360deg)}}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function createOverlay(title, label) {
        var overlay = document.createElement('div');
        overlay.className = 'catalog-maintenance-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.innerHTML = '<div class="catalog-maintenance-dialog">'
            + '<h2>' + title + '</h2>'
            + '<p class="catalog-maintenance-message"></p>'
            + '<div class="catalog-maintenance-progress"><span></span></div>'
            + '<div class="catalog-maintenance-count">Waiting for scanner…</div>'
            + '<div class="catalog-maintenance-loading"><span class="catalog-maintenance-spinner"></span><span>Loading updated file list…</span></div>'
            + '</div>';
        overlay.querySelector('.catalog-maintenance-message').textContent = label;
        document.body.appendChild(overlay);
        return overlay;
    }

    function showState(overlay, state) {
        var percent = Math.max(0, Math.min(100, Number(state.percent || 0)));
        var done = Number(state.done || 0);
        var total = Number(state.total || 0);
        overlay.querySelector('.catalog-maintenance-progress > span').style.width = percent + '%';
        overlay.querySelector('.catalog-maintenance-message').textContent = state.message || 'Working…';
        overlay.querySelector('.catalog-maintenance-count').textContent = total > 0
            ? done + ' of ' + total + ' (' + Math.round(percent) + '%)'
            : 'Working…';
    }

    function parseJson(response) {
        return response.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (error) {
                var responseType = response.headers.get('content-type') || 'unknown content type';
                throw new Error('Server returned ' + responseType + ' instead of a maintenance response (HTTP ' + response.status + ').');
            }
        });
    }

    function pollProgress(progressToken, overlay) {
        var active = true;
        var timer = null;

        function tick() {
            if (!active) return;
            fetch('file-maintenance.php?progress=' + encodeURIComponent(progressToken), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(parseJson).then(function (state) {
                if (active) showState(overlay, state);
            }).catch(function () {
                /* The active POST remains authoritative. Keep the last known scanner state visible. */
            }).finally(function () {
                if (active) timer = window.setTimeout(tick, 450);
            });
        }

        tick();
        return function () {
            active = false;
            if (timer !== null) window.clearTimeout(timer);
        };
    }

    function requestMaintenance(form, operation, fileName) {
        var isRemoval = operation === 'remove';
        if (isRemoval) {
            if (!window.confirm('Remove ' + fileName + ' from storage and the catalog? This cannot be undone.')) return;
        } else if (!window.confirm('Re-import ' + fileName + ' using the normal Upload Files scanner? The existing database record will be replaced.')) {
            return;
        }

        var overlay = createOverlay(isRemoval ? 'Removing package' : 'Re-importing package', fileName);
        var progressToken = makeToken();
        var stopPolling = pollProgress(progressToken, overlay);
        var data = new FormData(form);
        data.set('operation', isRemoval ? 'remove' : 'reimport');
        data.set('progress_token', progressToken);

        document.querySelectorAll('.game-files-admin-actions button').forEach(function (button) {
            button.disabled = true;
        });

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            body: data
        }).then(parseJson).then(function (result) {
            stopPolling();
            if (!result.ok) {
                throw new Error(result.error || 'Maintenance failed.');
            }
            showState(overlay, {percent: 100, done: 100, total: 100, message: result.message || 'Maintenance complete.'});
            overlay.querySelector('.catalog-maintenance-loading').classList.add('is-visible');
            window.setTimeout(function () {
                window.location.assign(result.return_url || window.location.href);
            }, 80);
        }).catch(function (error) {
            stopPolling();
            overlay.remove();
            document.querySelectorAll('.game-files-admin-actions button').forEach(function (button) {
                button.disabled = false;
            });
            window.alert(error.message || 'Maintenance failed.');
        });
    }

    function bindMaintenanceForms() {
        document.querySelectorAll('.game-files-admin-actions form').forEach(function (form) {
            var operationInput = form.querySelector('input[name="operation"]');
            var button = form.querySelector('button[type="submit"]');
            if (!operationInput || !button) return;

            var isRemoval = operationInput.value === 'remove';
            if (!isRemoval) {
                button.title = 'Re-import this stored package using the normal scanner';
                button.setAttribute('aria-label', button.title);
            }
            form.removeAttribute('onsubmit');
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var row = form.closest('tr');
                var fileName = row && row.cells.length > 1
                    ? row.cells[1].textContent.trim().replace(/\s+/g, ' ')
                    : 'this package';
                requestMaintenance(form, operationInput.value, fileName);
            });
        });
    }

    addStyle();
    bindMaintenanceForms();
})();
