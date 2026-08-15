(function (global) {
    'use strict';

    if (global.UnrealDbHttp) return;

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    function requestReference(body) {
        if (!body || typeof body !== 'object') return '';
        if (body.request_id) return String(body.request_id);
        if (body.error && body.error.request_id) return String(body.error.request_id);
        if (body.error && body.error.details && body.error.details.request_id) {
            return String(body.error.details.request_id);
        }
        return '';
    }

    function errorMessage(body, fallback) {
        var text = fallback;
        if (body && body.error && body.error.message) text = String(body.error.message);
        else if (body && typeof body.error === 'string') text = body.error;
        var reference = requestReference(body);
        return reference && text.indexOf(reference) === -1 ? text + ' | reference: ' + reference : text;
    }

    function json(url, options) {
        var requestOptions = options || {};
        if (!requestOptions.credentials) requestOptions.credentials = 'same-origin';
        return fetch(url, requestOptions).then(function (response) {
            return response.json().catch(function () {
                throw new Error('The server returned invalid JSON (HTTP ' + response.status + ').');
            }).then(function (body) {
                if (!response.ok) {
                    throw new Error(errorMessage(body, 'The request failed with HTTP ' + response.status + '.'));
                }
                return body;
            });
        });
    }

    function getJson(url, options) {
        var requestOptions = Object.assign({
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }, options || {});
        return json(url, requestOptions);
    }

    function postJson(url, payload, csrf, options) {
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (csrf) headers['X-CSRF-Token'] = csrf;
        var requestOptions = Object.assign({
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(payload == null ? {} : payload)
        }, options || {});
        return json(url, requestOptions);
    }

    global.UnrealDbHttp = Object.freeze({
        ready: ready,
        json: json,
        getJson: getJson,
        postJson: postJson,
        requestReference: requestReference,
        errorMessage: errorMessage
    });
}(window));
