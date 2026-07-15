(function (global) {
    'use strict';

    var styleAdded = false;

    function addStyle() {
        if (styleAdded) return;
        styleAdded = true;
        var style = document.createElement('style');
        style.textContent = [
            '.catalog-long-job-overlay{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:20px;background:rgba(3,8,18,.76);backdrop-filter:blur(4px)}',
            '.catalog-long-job-dialog{width:min(680px,100%);padding:24px;border:1px solid var(--line2);border-radius:14px;background:#111b2d;box-shadow:0 24px 70px rgba(0,0,0,.55)}',
            '.catalog-long-job-dialog h2{margin:0 0 8px}',
            '.catalog-long-job-message{min-height:3.1em;margin:0 0 16px;overflow-wrap:anywhere}',
            '.catalog-long-job-progress{height:15px;overflow:hidden;border:1px solid var(--line2);border-radius:999px;background:rgba(255,255,255,.05)}',
            '.catalog-long-job-progress>span{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,#76a9ff,#9dc2ff);transition:width .2s linear}',
            '.catalog-long-job-meta{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px 18px;margin-top:10px;color:var(--muted);font-size:13px}',
            '.catalog-long-job-count{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.catalog-long-job-percent{text-align:right;white-space:nowrap}',
            '.catalog-long-job-time{grid-column:1/-1;display:flex;gap:18px;flex-wrap:wrap}',
            '.catalog-long-job-log{display:none;max-height:180px;overflow:auto;margin-top:14px;padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:rgba(255,255,255,.035);font:12px/1.45 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap}',
            '.catalog-long-job-log.is-visible{display:block}',
            '.catalog-long-job-log__error{color:#ffd9de}',
            '.catalog-long-job-actions{display:none;gap:8px;margin-top:16px;flex-wrap:wrap}',
            '.catalog-long-job-actions.is-visible{display:flex}',
            '.catalog-long-job-status{display:flex;align-items:center;gap:10px;margin-top:16px}',
            '.catalog-long-job-spinner{width:17px;height:17px;border:3px solid rgba(157,194,255,.25);border-top-color:#9dc2ff;border-radius:50%;animation:catalog-long-job-spin .8s linear infinite}',
            '.catalog-long-job-status.is-complete .catalog-long-job-spinner{display:none}',
            '@keyframes catalog-long-job-spin{to{transform:rotate(360deg)}}',
            '@media(max-width:620px){.catalog-long-job-meta{grid-template-columns:1fr}.catalog-long-job-percent{text-align:left}.catalog-long-job-time{gap:10px}}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function formatDuration(totalSeconds) {
        if (!Number.isFinite(totalSeconds) || totalSeconds < 0) return 'estimating…';
        totalSeconds = Math.max(0, Math.round(totalSeconds));
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;
        if (hours > 0) return hours + 'h ' + minutes + 'm';
        if (minutes > 0) return minutes + 'm ' + seconds + 's';
        return seconds + 's';
    }

    function makeToken() {
        var bytes = new Uint8Array(18);
        if (global.crypto && global.crypto.getRandomValues) {
            global.crypto.getRandomValues(bytes);
            return Array.from(bytes).map(function (value) {
                return value.toString(16).padStart(2, '0');
            }).join('');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function parseJson(response) {
        return response.text().then(function (text) {
            try {
                return JSON.parse(text || '{}');
            } catch (error) {
                var detail = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 260);
                throw new Error('Server returned a non-JSON response (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
            }
        });
    }

    function progressUrl(endpoint, token) {
        return endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + 'progress=' + encodeURIComponent(token);
    }

    function poll(endpoint, token, onState, intervalMs) {
        var active = true;
        var timer = null;
        intervalMs = Math.max(250, Number(intervalMs || 500));

        function tick() {
            if (!active) return;
            fetch(progressUrl(endpoint, token), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(parseJson).then(function (state) {
                if (active && typeof onState === 'function') onState(state || {});
            }).catch(function () {
                /* The active POST remains authoritative. Keep the last state visible. */
            }).finally(function () {
                if (active) timer = global.setTimeout(tick, intervalMs);
            });
        }

        tick();
        return function () {
            active = false;
            if (timer !== null) global.clearTimeout(timer);
        };
    }

    function create(options) {
        addStyle();
        options = options || {};
        var startedAt = Date.now();
        var percent = 0;
        var finished = false;

        var overlay = document.createElement('div');
        overlay.className = 'catalog-long-job-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = '<div class="catalog-long-job-dialog">'
            + '<h2></h2>'
            + '<p class="catalog-long-job-message"></p>'
            + '<div class="catalog-long-job-progress"><span></span></div>'
            + '<div class="catalog-long-job-meta">'
            + '<div class="catalog-long-job-count">Preparing…</div>'
            + '<div class="catalog-long-job-percent">0%</div>'
            + '<div class="catalog-long-job-time"><span class="catalog-long-job-elapsed">Elapsed: 0s</span><span class="catalog-long-job-remaining">Remaining: estimating…</span></div>'
            + '</div>'
            + '<div class="catalog-long-job-log"></div>'
            + '<div class="catalog-long-job-status"><span class="catalog-long-job-spinner"></span><span>Working…</span></div>'
            + '<div class="catalog-long-job-actions"></div>'
            + '</div>';
        overlay.querySelector('h2').textContent = options.title || 'Working';
        overlay.querySelector('.catalog-long-job-message').textContent = options.message || 'Preparing…';
        document.body.appendChild(overlay);

        var timer = global.setInterval(function () {
            renderTime();
        }, 1000);

        function renderTime() {
            var elapsedSeconds = Math.max(0, (Date.now() - startedAt) / 1000);
            overlay.querySelector('.catalog-long-job-elapsed').textContent = 'Elapsed: ' + formatDuration(elapsedSeconds);
            var remaining = 'estimating…';
            if (percent >= 2 && percent < 100 && elapsedSeconds >= 3) {
                remaining = formatDuration(elapsedSeconds * ((100 - percent) / percent));
            } else if (percent >= 100) {
                remaining = '0s';
            }
            overlay.querySelector('.catalog-long-job-remaining').textContent = 'Remaining: ' + remaining;
        }

        function update(state) {
            state = state || {};
            if (state.percent !== undefined) {
                percent = Math.max(0, Math.min(100, Number(state.percent) || 0));
            }
            overlay.querySelector('.catalog-long-job-progress > span').style.width = percent + '%';
            overlay.querySelector('.catalog-long-job-percent').textContent = Math.round(percent) + '%';
            if (state.message !== undefined) {
                overlay.querySelector('.catalog-long-job-message').textContent = state.message || 'Working…';
            }
            if (state.count !== undefined) {
                overlay.querySelector('.catalog-long-job-count').textContent = state.count || 'Working…';
            } else if (state.done !== undefined && state.total !== undefined) {
                overlay.querySelector('.catalog-long-job-count').textContent = state.done + ' of ' + state.total;
            }
            if (state.status !== undefined) {
                overlay.querySelector('.catalog-long-job-status span:last-child').textContent = state.status || 'Working…';
            }
            renderTime();
        }

        function addLog(message, isError) {
            if (!message) return;
            var log = overlay.querySelector('.catalog-long-job-log');
            var line = document.createElement('div');
            if (isError) line.className = 'catalog-long-job-log__error';
            line.textContent = message;
            log.appendChild(line);
            log.classList.add('is-visible');
            log.scrollTop = log.scrollHeight;
        }

        function addAction(label, href, onClick) {
            var actions = overlay.querySelector('.catalog-long-job-actions');
            var action;
            if (href) {
                action = document.createElement('a');
                action.href = href;
            } else {
                action = document.createElement('button');
                action.type = 'button';
                if (typeof onClick === 'function') action.addEventListener('click', onClick);
            }
            action.className = 'button';
            action.textContent = label;
            actions.appendChild(action);
            actions.classList.add('is-visible');
            return action;
        }

        function finish(message, status) {
            if (!finished) {
                finished = true;
                global.clearInterval(timer);
            }
            percent = 100;
            update({percent: 100, message: message || 'Complete.', status: status || 'Complete'});
            overlay.querySelector('.catalog-long-job-status').classList.add('is-complete');
        }

        function fail(message) {
            if (!finished) {
                finished = true;
                global.clearInterval(timer);
            }
            overlay.querySelector('.catalog-long-job-message').textContent = message || 'The operation failed.';
            overlay.querySelector('.catalog-long-job-status').classList.add('is-complete');
            overlay.querySelector('.catalog-long-job-status span:last-child').textContent = 'Stopped';
            addLog(message || 'The operation failed.', true);
            renderTime();
        }

        function destroy() {
            global.clearInterval(timer);
            overlay.remove();
        }

        update({percent: 0, message: options.message || 'Preparing…', count: options.count || 'Preparing…'});

        return {
            element: overlay,
            update: update,
            addLog: addLog,
            addAction: addAction,
            complete: finish,
            fail: fail,
            destroy: destroy,
            getPercent: function () { return percent; }
        };
    }

    global.CatalogLongJob = {
        create: create,
        makeToken: makeToken,
        parseJson: parseJson,
        poll: poll,
        formatDuration: formatDuration
    };
})(window);
