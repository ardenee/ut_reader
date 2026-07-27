(function () {
    'use strict';

    if (!window.fetch) return;
    const originalFetch = window.fetch.bind(window);
    const pageState = new Map();

    function requestUrl(input) {
        try {
            return new URL(typeof input === 'string' ? input : input.url, window.location.href);
        } catch (error) {
            return null;
        }
    }

    function isBackgroundJobList(url, options) {
        if (!url || !/\/api\/v1\/job-status\.php$/i.test(url.pathname)) return false;
        const method = String((options && options.method) || 'GET').toUpperCase();
        return method === 'GET' && !url.searchParams.has('job_id');
    }

    function keyFor(url) {
        return [
            url.searchParams.get('queue') || '',
            url.searchParams.get('status') || '',
            url.searchParams.get('search') || '',
            url.searchParams.get('per_page') || url.searchParams.get('limit') || '100'
        ].join('\u0000');
    }

    function currentUrlDescriptor(requestedPage) {
        const params = new URLSearchParams(window.location.search);
        const page = Math.max(1, parseInt(params.get('page') || '1', 10) || 1);
        if (page !== requestedPage) return null;
        const move = String(params.get('job_move') || '');
        const cursor = String(params.get('job_cursor') || '');
        if (!['next', 'previous', 'last'].includes(move)) return null;
        if (move !== 'last' && !cursor) return null;
        return {move: move, cursor: cursor};
    }

    function persistDescriptor(page, descriptor) {
        const params = new URLSearchParams(window.location.search);
        if (page <= 1 || !descriptor || descriptor.move === 'first') {
            params.delete('job_move');
            params.delete('job_cursor');
        } else {
            params.set('job_move', descriptor.move);
            if (descriptor.cursor) params.set('job_cursor', descriptor.cursor);
            else params.delete('job_cursor');
        }
        const query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
    }

    window.fetch = async function (input, options) {
        const url = requestUrl(input);
        if (!isBackgroundJobList(url, options)) {
            return originalFetch(input, options);
        }

        const requestedPage = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1);
        const key = keyFor(url);
        let state = pageState.get(key);
        if (!state) {
            state = {pages: 1, descriptors: new Map()};
            pageState.set(key, state);
        }

        let descriptor = requestedPage === 1 ? {move: 'first', cursor: ''} : currentUrlDescriptor(requestedPage);
        if (!descriptor && state.descriptors.has(requestedPage)) {
            descriptor = state.descriptors.get(requestedPage);
        }
        if (!descriptor && state.pages > 1 && requestedPage === state.pages) {
            descriptor = {move: 'last', cursor: ''};
        }
        if (!descriptor) {
            descriptor = {move: 'first', cursor: ''};
            url.searchParams.set('page', '1');
        }

        url.pathname = url.pathname.replace(/job-status\.php$/i, 'job-status-cursor.php');
        url.searchParams.set('move', descriptor.move);
        if (descriptor.cursor) url.searchParams.set('cursor', descriptor.cursor);
        else url.searchParams.delete('cursor');

        const response = await originalFetch(url.toString(), options);
        if (!response.ok) return response;

        try {
            const body = await response.clone().json();
            const meta = body && body.meta ? body.meta : {};
            const page = Math.max(1, Number(meta.page || 1));
            state.pages = Math.max(1, Number(meta.pages || 1));
            state.descriptors.clear();
            if (meta.has_previous && meta.previous_cursor && page > 1) {
                state.descriptors.set(page - 1, {move: 'previous', cursor: String(meta.previous_cursor)});
            }
            if (meta.has_next && meta.next_cursor && page < state.pages) {
                state.descriptors.set(page + 1, {move: 'next', cursor: String(meta.next_cursor)});
            }
            persistDescriptor(page, page > 1 ? descriptor : {move: 'first', cursor: ''});
        } catch (error) {
            // The normal Background Jobs client owns response/error display.
        }

        return response;
    };
}());
