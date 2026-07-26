(function () {
    'use strict';

    if (typeof window.fetch !== 'function' || window.__unverifiedImportTimeoutRecoveryInstalled) {
        return;
    }
    window.__unverifiedImportTimeoutRecoveryInstalled = true;

    var nativeFetch = window.fetch.bind(window);

    function requestUrl(input) {
        if (typeof input === 'string') return input;
        return input && typeof input.url === 'string' ? input.url : '';
    }

    function requestMethod(input, init) {
        var method = init && init.method ? init.method : (input && input.method ? input.method : 'GET');
        return String(method || 'GET').toUpperCase();
    }

    function isUnverifiedActionPost(input, init) {
        var url = requestUrl(input);
        return requestMethod(input, init) === 'POST'
            && /(?:^|\/)unverified-files-action\.php(?:[?#]|$)/.test(url);
    }

    function progressToken(init) {
        var body = init && init.body;
        if (!body || typeof body.get !== 'function') return '';
        return String(body.get('progress_token') || '').trim();
    }

    function delay(milliseconds) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, milliseconds);
        });
    }

    function jsonResponse(payload, status) {
        return new Response(JSON.stringify(payload), {
            status: status,
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'no-store'
            }
        });
    }

    async function readProgress(token) {
        var response = await nativeFetch('unverified-files-action.php?progress=' + encodeURIComponent(token), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        if (!response.ok) {
            throw new Error('Progress request returned HTTP ' + response.status + '.');
        }
        return response.json();
    }

    async function recoverFromProgress(token) {
        while (true) {
            try {
                var state = await readProgress(token);
                if (state && typeof state === 'object') {
                    var stage = String(state.stage || '').toLowerCase();
                    var message = String(state.message || '').trim();
                    if (stage === 'done') {
                        return jsonResponse({
                            ok: true,
                            recovered_after_timeout: true,
                            message: message || 'The file completed after the web proxy timed out.'
                        }, 200);
                    }
                    if (stage === 'failed') {
                        return jsonResponse({
                            ok: false,
                            recovered_after_timeout: true,
                            error: message || 'The file failed after the web proxy timed out.'
                        }, 400);
                    }
                }
            } catch (error) {
                // The main PHP request may still hold a worker while the proxy has
                // already returned 504. Keep polling until PHP publishes a terminal
                // progress state; do not convert a temporary poll failure into a
                // second import attempt.
            }
            await delay(500);
        }
    }

    window.fetch = async function (input, init) {
        if (!isUnverifiedActionPost(input, init)) {
            return nativeFetch(input, init);
        }

        var token = progressToken(init);
        try {
            var response = await nativeFetch(input, init);
            if (token !== '' && [502, 503, 504].indexOf(response.status) !== -1) {
                return recoverFromProgress(token);
            }
            return response;
        } catch (error) {
            if (token !== '') {
                return recoverFromProgress(token);
            }
            throw error;
        }
    };
})();
